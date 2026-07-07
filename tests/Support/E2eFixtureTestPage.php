<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Support;

use Page;
use SilverStripe\Dev\TestOnly;

class E2eFixtureTestPage extends Page implements TestOnly
{
    private static string $table_name = 'E2eFixtureTestPage';
}
