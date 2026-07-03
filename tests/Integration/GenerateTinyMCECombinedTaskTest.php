<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
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
}
