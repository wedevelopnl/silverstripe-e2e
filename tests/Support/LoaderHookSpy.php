<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\TestOnly;
use WeDevelop\E2e\Fixtures\FixtureResult;

/**
 * @extends Extension<\WeDevelop\E2e\Fixtures\FixtureLoader>
 */
class LoaderHookSpy extends Extension implements TestOnly
{
    /** @var list<string> */
    public static array $beforeCalls = [];

    /** @var list<string> */
    public static array $afterCalls = [];

    public static function reset(): void
    {
        self::$beforeCalls = [];
        self::$afterCalls = [];
    }

    public function onBeforeLoad(string $fixtureName, FixtureFactory $factory): void
    {
        self::$beforeCalls[] = $fixtureName;
    }

    public function onAfterLoad(FixtureResult $result): void
    {
        self::$afterCalls[] = $result->fixtureName;
    }
}
