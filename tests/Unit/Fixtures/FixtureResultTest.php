<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Unit\Fixtures;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\E2e\Fixtures\FixtureResult;

#[CoversClass(FixtureResult::class)]
final class FixtureResultTest extends TestCase
{
    public function testJsonSerializeEmitsPageIdUrlAndFixtureMapOnly(): void
    {
        $result = new FixtureResult(
            fixtureName: 'simple-page',
            pageId: 42,
            pageUrl: '/e2e-simple/',
            fixtureMap: ['SomeClass' => ['id1' => 7]],
        );

        self::assertSame(
            [
                'pageId' => 42,
                'pageUrl' => '/e2e-simple/',
                'fixtureMap' => ['SomeClass' => ['id1' => 7]],
            ],
            $result->jsonSerialize(),
        );
    }

    public function testFixtureNameIsExposedAsProperty(): void
    {
        $result = new FixtureResult(
            fixtureName: 'simple-page',
            pageId: 1,
            pageUrl: '/e2e-simple/',
            fixtureMap: [],
        );

        self::assertSame('simple-page', $result->fixtureName);
    }
}
