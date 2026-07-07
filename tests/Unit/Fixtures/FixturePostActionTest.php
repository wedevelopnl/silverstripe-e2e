<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Unit\Fixtures;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\E2e\Fixtures\FixturePostAction;

#[CoversClass(FixturePostAction::class)]
final class FixturePostActionTest extends TestCase
{
    public function testFromConfigBuildsActionWithDefaults(): void
    {
        $action = FixturePostAction::fromConfig([
            'action' => 'publish_recursive',
            'class' => 'Some\\Class',
            'identifier' => 'rec1',
        ]);

        self::assertSame('publish_recursive', $action->action);
        self::assertSame('Some\\Class', $action->class);
        self::assertSame('rec1', $action->identifier);
        self::assertSame([], $action->fields);
    }

    public function testFromConfigKeepsFields(): void
    {
        $action = FixturePostAction::fromConfig([
            'action' => 'modify',
            'class' => 'Some\\Class',
            'identifier' => 'rec1',
            'fields' => ['Title' => 'New'],
        ]);

        self::assertSame(['Title' => 'New'], $action->fields);
    }

    public function testFromConfigThrowsWhenRequiredKeysMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires "action", "class", and "identifier"');

        FixturePostAction::fromConfig(['action' => 'modify']);
    }

    public function testFromConfigThrowsOnUnknownAction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown post-action "explode"');

        FixturePostAction::fromConfig([
            'action' => 'explode',
            'class' => 'Some\\Class',
            'identifier' => 'rec1',
        ]);
    }
}
