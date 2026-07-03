<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Forms\HTMLEditor\HTMLEditorConfig;
use SilverStripe\Forms\HTMLEditor\TinyMCECombinedGenerator;
use SilverStripe\Forms\HTMLEditor\TinyMCEScriptGenerator;
use SilverStripe\i18n\i18n;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class GenerateTinyMCECombinedTask extends BuildTask
{
    protected static string $commandName = 'generate-tinymce-combined';

    protected static string $description =
        'Silverstripe generates the TinyMCE bundle on the fly during the request; '
        . 'for CI purposes it is required to build the assets from the CLI';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        TinyMCECombinedGenerator::flush();

        $editorConfigs = HTMLEditorConfig::get_available_configs_map();
        $doGenerate = function () use ($editorConfigs): void {
            /** @var TinyMCEScriptGenerator $generator */
            $generator = Injector::inst()->create(TinyMCEScriptGenerator::class);
            foreach (array_keys($editorConfigs) as $identifier) {
                $generator->getScriptURL(HTMLEditorConfig::get($identifier));
            }
        };

        if (class_exists(i18n::class)) {
            // The task runs without an active database, so we do not know which
            // locales are enabled. Generate the script for every known locale.
            foreach (array_keys(i18n::getData()->getLocales()) as $locale) {
                i18n::with_locale($locale, $doGenerate);
            }
        } else {
            $doGenerate();
        }

        $output->writeln('Generated TinyMCE configuration files');

        return Command::SUCCESS;
    }
}
