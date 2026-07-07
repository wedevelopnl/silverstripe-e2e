<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\E2e\Tests\Support\E2eFixtureTestPage;
use WeDevelop\E2e\Tests\Support\E2eUnversionedObject;
use WeDevelop\E2e\Tests\Support\E2eVersionedObject;

#[CoversNothing]
final class SupportScaffoldingTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        E2eFixtureTestPage::class,
        E2eVersionedObject::class,
        E2eUnversionedObject::class,
    ];

    public function testSupportObjectsBuildTablesAndWrite(): void
    {
        $page = new E2eFixtureTestPage();
        $page->Title = 'Smoke';
        $page->URLSegment = 'e2e-smoke';
        $page->write();
        self::assertGreaterThan(0, $page->ID);

        $versioned = new E2eVersionedObject();
        $versioned->Title = 'V';
        $versioned->write();
        self::assertTrue($versioned->hasExtension(Versioned::class));

        $unversioned = new E2eUnversionedObject();
        $unversioned->Title = 'U';
        $unversioned->write();
        self::assertFalse($unversioned->hasExtension(Versioned::class));
    }
}
