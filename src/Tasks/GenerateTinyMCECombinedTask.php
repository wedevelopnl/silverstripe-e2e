<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Forms\HTMLEditor\HTMLEditorConfig;
use SilverStripe\TinyMCE\TinyMCECombinedGenerator;
use SilverStripe\TinyMCE\TinyMCEConfig;
use SilverStripe\TinyMCE\TinyMCEScriptGenerator;
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
        // silverstripe/htmleditor-tinymce is a suggested (optional) dependency:
        // only this CI helper needs it, while fixtures-only consumers do not.
        // Skip cleanly when it is absent rather than fataling on the TinyMCE
        // classes used below.
        if (!class_exists(TinyMCECombinedGenerator::class)) {
            // @codeCoverageIgnoreStart
            // Unreachable in this module's own test run, which require-dev's TinyMCE.
            $output->writeln(
                'silverstripe/htmleditor-tinymce is not installed; skipping TinyMCE asset generation',
            );

            return Command::SUCCESS;
            // @codeCoverageIgnoreEnd
        }

        TinyMCECombinedGenerator::flush();

        $editorConfigs = HTMLEditorConfig::get_available_configs_map();
        $doGenerate = function () use ($editorConfigs): void {
            /** @var TinyMCEScriptGenerator $generator */
            $generator = Injector::inst()->create(TinyMCEScriptGenerator::class);
            foreach (array_keys($editorConfigs) as $identifier) {
                $config = HTMLEditorConfig::get($identifier);
                // Only TinyMCE-backed configs expose a combined script to pre-generate;
                // skip any other HTMLEditorConfig implementation that may be registered.
                if (!$config instanceof TinyMCEConfig) {
                    continue;
                }

                $generator->getScriptURL($config);
            }
        };

        if (class_exists(i18n::class)) {
            // The task runs without an active database, so we do not know which
            // locales are enabled. Generate the script for every known locale.
            foreach (array_keys(i18n::getData()->getLocales()) as $locale) {
                i18n::with_locale($locale, $doGenerate);
            }
        } else {
            // @codeCoverageIgnoreStart
            // Unreachable once the framework has booted (i18n is always autoloadable);
            // retained only as a defensive fallback for a stripped runtime.
            $doGenerate();
            // @codeCoverageIgnoreEnd
        }

        $output->writeln('Generated TinyMCE configuration files');

        return Command::SUCCESS;
    }
}
