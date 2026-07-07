<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Support;

use Page;
use SilverStripe\Dev\TestOnly;

/**
 * A second SiteTree subclass used to exercise FixtureLoader's fallback branch:
 * a fixture creates this page while it is NOT registered in
 * FixtureLoader.fixture_page_classes, so the preferred-class lookup misses and
 * the loader falls back to the first SiteTree it finds.
 */
class E2eOtherTestPage extends Page implements TestOnly
{
    private static string $table_name = 'E2eOtherTestPage';
}
