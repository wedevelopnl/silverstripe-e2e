<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration\Tasks;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\E2e\Tasks\LoadFixturesTask;
use WeDevelop\E2e\Tests\Support\E2eFixtureTestPage;
use WeDevelop\E2e\Tests\Support\E2eOtherTestPage;
use WeDevelop\E2e\Tests\Support\E2eVersionedObject;
use WeDevelop\E2e\Tests\Support\LoaderHookSpy;

#[CoversClass(LoadFixturesTask::class)]
final class LoadFixturesTaskTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        E2eFixtureTestPage::class,
        E2eOtherTestPage::class,
        E2eVersionedObject::class,
    ];

    private string $originalEnvironment = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The task's guard refuses to run unless Director::isDev(); SapphireTest
        // does not boot into the dev environment, so force it (mirrors
        // FixtureControllerTest). The non-dev path is exercised explicitly below.
        $kernel = Injector::inst()->get(Kernel::class);
        $this->originalEnvironment = $kernel->getEnvironment();
        $kernel->setEnvironment('dev');

        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'fixture_page_classes', [
            E2eFixtureTestPage::class,
        ]);
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [
            E2eFixtureTestPage::class,
            E2eOtherTestPage::class,
        ]);
    }

    protected function tearDown(): void
    {
        Injector::inst()->get(Kernel::class)->setEnvironment($this->originalEnvironment);

        parent::tearDown();
    }

    /**
     * @return array{int, string} exit code and captured output
     */
    private function runTask(?string $fixture): array
    {
        $task = new LoadFixturesTask();

        $parameters = $fixture === null ? [] : ['--fixture' => $fixture];
        $input = new ArrayInput($parameters, new InputDefinition($task->getOptions()));

        $buffer = new BufferedOutput();
        $output = new PolyOutput(
            PolyOutput::FORMAT_ANSI,
            OutputInterface::VERBOSITY_NORMAL,
            false,
            $buffer,
        );

        $exitCode = $task->run($input, $output);

        return [$exitCode, $buffer->fetch()];
    }

    public function testLoadAllSeedsEveryFixtureAndReportsEach(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
            'fallback-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/fallback-page.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'fixture_page_classes', [
            E2eFixtureTestPage::class,
            E2eOtherTestPage::class,
        ]);

        [$exitCode, $output] = $this->runTask(null);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Loaded fixture "simple-page"', $output);
        self::assertStringContainsString('Loaded fixture "fallback-page"', $output);
        self::assertStringContainsString('Loaded 2 fixture', $output);
        self::assertSame(1, E2eFixtureTestPage::get()->filter('URLSegment', 'e2e-simple')->count());
        self::assertSame(1, E2eOtherTestPage::get()->filter('URLSegment', 'e2e-fallback')->count());
    }

    public function testSingleFixtureLoadsOnlyThatFixture(): void
    {
        [$exitCode, $output] = $this->runTask('simple-page');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Loaded fixture "simple-page"', $output);
        self::assertStringContainsString('e2e-simple', $output);
        self::assertStringNotContainsString('Loaded 1 fixture.', $output);
        self::assertSame(1, E2eFixtureTestPage::get()->filter('URLSegment', 'e2e-simple')->count());
    }

    public function testEmptyFixtureOptionIsRejectedWithoutTouchingTheLoader(): void
    {
        // A spy on the loader's lifecycle hooks proves no fixture was loaded —
        // immune to fixture data leaking across test methods (runtime YamlFixture
        // writes are not rolled back like SapphireTest fixtures).
        Config::modify()->merge(FixtureLoader::class, 'extensions', [LoaderHookSpy::class]);
        LoaderHookSpy::reset();

        [$exitCode, $output] = $this->runTask('');

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('cannot be empty', $output);
        self::assertSame([], LoaderHookSpy::$beforeCalls);
    }

    public function testUnknownFixtureNameReturnsInvalid(): void
    {
        [$exitCode, $output] = $this->runTask('does-not-exist');

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Unknown fixture', $output);
    }

    public function testLoadAllWithNoFixturesConfiguredReturnsInvalid(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', []);

        [$exitCode, $output] = $this->runTask(null);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('No fixtures', $output);
    }

    public function testResetRefusalIsReportedAsFailure(): void
    {
        // reset() refuses when no purge_classes are configured; the loader
        // raises a RuntimeException, which the task surfaces as a hard failure
        // (distinct from the INVALID exit used for bad input).
        Config::modify()->set(FixtureLoader::class, 'purge_classes', []);

        [$exitCode, $output] = $this->runTask('simple-page');

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('refusing to reset', $output);
    }

    public function testRefusesToRunOutsideDevEnvironment(): void
    {
        Config::modify()->merge(FixtureLoader::class, 'extensions', [LoaderHookSpy::class]);
        LoaderHookSpy::reset();

        Injector::inst()->get(Kernel::class)->setEnvironment('live');

        [$exitCode, $output] = $this->runTask(null);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('dev environment', $output);
        // The guard returns before the loader runs, so no fixture is loaded.
        self::assertSame([], LoaderHookSpy::$beforeCalls);
    }
}
