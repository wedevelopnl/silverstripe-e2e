<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Fixtures;

use JsonSerializable;
use Override;

/**
 * Immutable result of loading a fixture via {@see FixtureLoader}.
 *
 * Returned as JSON from {@see FixtureController} so Playwright can extract the
 * page ID and the full identifier→DB-ID map for CMS navigation.
 */
final readonly class FixtureResult implements JsonSerializable
{
    /**
     * @param non-empty-string $fixtureName
     * @param positive-int $pageId
     * @param non-empty-string $pageUrl
     * @param array<string, array<string, int>> $fixtureMap Class → identifier → DB ID
     */
    public function __construct(
        public string $fixtureName,
        public int $pageId,
        public string $pageUrl,
        public array $fixtureMap,
    ) {
    }

    /**
     * @return array{pageId: positive-int, pageUrl: non-empty-string, fixtureMap: array<string, array<string, int>>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'pageId' => $this->pageId,
            'pageUrl' => $this->pageUrl,
            'fixtureMap' => $this->fixtureMap,
        ];
    }
}
