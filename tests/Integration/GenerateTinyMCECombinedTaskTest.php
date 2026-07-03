<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\HTMLEditor\HTMLEditorConfig;
use SilverStripe\Forms\HTMLEditor\TextAreaConfig;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use WeDevelop\E2e\Tasks\GenerateTinyMCECombinedTask;

#[CoversClass(GenerateTinyMCECombinedTask::class)]
final class GenerateTinyMCECombinedTaskTest extends SapphireTest
{
    // The task is database-agnostic (designed to run with --no-database); it only
    // reads HTMLEditor config and writes combined TinyMCE assets to the filesystem.
    protected $usesDatabase = false;

    // Identifier used to register a non-TinyMCE editor config; HTMLEditorConfig
    // stores registrations in a static map, so it must be removed in tearDown to
    // avoid leaking into other tests.
    private const NON_TINYMCE_CONFIG_IDENTIFIER = 'e2e-plain';

    protected function tearDown(): void
    {
        HTMLEditorConfig::set_config(self::NON_TINYMCE_CONFIG_IDENTIFIER, null);

        parent::tearDown();
    }

    public function testTaskGeneratesTinyMceConfigAndReturnsSuccess(): void
    {
        $buffer = new BufferedOutput();
        $output = new PolyOutput(
            PolyOutput::FORMAT_ANSI,
            OutputInterface::VERBOSITY_NORMAL,
            false,
            $buffer,
        );

        $task = new GenerateTinyMCECombinedTask();

        $exitCode = $task->run(new ArrayInput([]), $output);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString(
            'Generated TinyMCE configuration files',
            $buffer->fetch(),
        );
    }

    public function testTaskSkipsNonTinyMceConfigsWithoutError(): void
    {
        // Register a concrete HTMLEditorConfig that is NOT a TinyMCEConfig so it
        // appears in HTMLEditorConfig::get_available_configs_map(). The task must
        // skip it (the instanceof guard's `continue`) rather than passing it to the
        // TinyMCE script generator, which only accepts TinyMCEConfig instances.
        HTMLEditorConfig::set_config(
            self::NON_TINYMCE_CONFIG_IDENTIFIER,
            TextAreaConfig::create(),
        );

        $buffer = new BufferedOutput();
        $output = new PolyOutput(
            PolyOutput::FORMAT_ANSI,
            OutputInterface::VERBOSITY_NORMAL,
            false,
            $buffer,
        );

        $task = new GenerateTinyMCECombinedTask();

        $exitCode = $task->run(new ArrayInput([]), $output);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString(
            'Generated TinyMCE configuration files',
            $buffer->fetch(),
        );
    }
}
