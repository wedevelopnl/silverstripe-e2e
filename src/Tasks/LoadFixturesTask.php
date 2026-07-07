<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tasks;

use Override;
use InvalidArgumentException;
use RuntimeException;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\E2e\Fixtures\FixtureResult;

class LoadFixturesTask extends BuildTask
{
    protected static string $commandName = 'load-e2e-fixtures';

    protected static string $description =
        'Seed E2E fixtures from the CLI. Loads every configured fixture, or a '
        . 'single one with --fixture=<name>.';

    /**
     * @return array<InputOption>
     */
    #[Override]
    public function getOptions(): array
    {
        return [
            new InputOption(
                'fixture',
                null,
                InputOption::VALUE_REQUIRED,
                'Load only this named fixture. Omit to load every configured fixture.',
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        // Both loader modes reset() first, which archives SiteTree pages. The
        // HTTP FixtureController locks its route to dev for exactly this reason;
        // a CLI task has no such gate by default, so re-establish the invariant
        // here: never fire a destructive archive on a staging/production host.
        if (!Director::isDev()) {
            $output->writeln('<error>Refusing to run outside the dev environment.</error>');

            return Command::FAILURE;
        }

        $fixtureName = $input->getOption('fixture');
        // Null means "load all". An explicitly-empty --fixture= is a user error,
        // mirroring FixtureController's non-empty-string guard.
        if ($fixtureName !== null && (!is_string($fixtureName) || $fixtureName === '')) {
            $output->writeln('<error>The --fixture option cannot be empty.</error>');

            return Command::INVALID;
        }

        $loader = FixtureLoader::create();

        try {
            if ($fixtureName !== null) {
                $output->writeln($this->formatResult($fixtureName, $loader->load($fixtureName)));

                return Command::SUCCESS;
            }

            $results = $loader->loadAll();
            foreach ($results as $name => $result) {
                $output->writeln($this->formatResult($name, $result));
            }

            $count = count($results);
            $output->writeln(sprintf('Loaded %d %s.', $count, $this->pluralise('fixture', $count)));
        } catch (InvalidArgumentException $invalidArgumentException) {
            // Bad input: unknown/misconfigured fixture name, or no fixtures configured.
            $output->writeln('<error>' . $invalidArgumentException->getMessage() . '</error>');

            return Command::INVALID;
        } catch (RuntimeException $runtimeException) {
            // Operational failure: fixture created no SiteTree, or reset() refused.
            $output->writeln('<error>' . $runtimeException->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function formatResult(string $name, FixtureResult $result): string
    {
        $records = 0;
        foreach ($result->fixtureMap as $ids) {
            $records += count($ids);
        }

        return sprintf(
            'Loaded fixture "%s" — page #%d at %s (%d %s)',
            $name,
            $result->pageId,
            $result->pageUrl,
            $records,
            $this->pluralise('record', $records),
        );
    }

    private function pluralise(string $noun, int $count): string
    {
        return $count === 1 ? $noun : $noun . 's';
    }
}
