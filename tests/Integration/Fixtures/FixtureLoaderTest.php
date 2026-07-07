<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration\Fixtures;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\E2e\Tests\Support\E2eFixtureTestPage;
use WeDevelop\E2e\Tests\Support\E2eVersionedObject;
use WeDevelop\E2e\Tests\Support\LoaderHookSpy;

#[CoversClass(FixtureLoader::class)]
final class FixtureLoaderTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        E2eFixtureTestPage::class,
        E2eVersionedObject::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'fixture_page_classes', [
            E2eFixtureTestPage::class,
        ]);
        LoaderHookSpy::reset();
    }

    public function testLoadWritesFixtureAndReturnsResult(): void
    {
        $result = FixtureLoader::create()->load('simple-page');

        self::assertSame('simple-page', $result->fixtureName);
        self::assertGreaterThan(0, $result->pageId);
        self::assertStringContainsString('e2e-simple', $result->pageUrl);
        self::assertArrayHasKey(E2eFixtureTestPage::class, $result->fixtureMap);
    }

    public function testLoadIsIdempotent(): void
    {
        FixtureLoader::create()->load('simple-page');
        FixtureLoader::create()->load('simple-page');

        self::assertCount(1, $this->draftPagesWithSegmentPrefix('e2e-simple'));
    }

    public function testLoadThrowsOnUnknownFixture(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown fixture "missing"');

        FixtureLoader::create()->load('missing');
    }

    public function testResetArchivesOnlyAllowlistedPrefixedPages(): void
    {
        FixtureLoader::create()->load('simple-page');

        $stray = new SiteTree();
        $stray->Title = 'Human authored';
        $stray->URLSegment = 'e2e-human';
        $stray->write();

        FixtureLoader::create()->reset();

        self::assertCount(0, $this->draftPagesWithSegmentPrefix('e2e-simple'));
        self::assertCount(1, $this->draftPagesWithSegmentPrefix('e2e-human'));
    }

    public function testResetThrowsWhenAllowlistEmpty(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixture_page_classes', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture_page_classes is empty');

        FixtureLoader::create()->reset();
    }

    public function testLifecycleHooksFireInOrder(): void
    {
        Config::modify()->merge(FixtureLoader::class, 'extensions', [
            LoaderHookSpy::class,
        ]);

        FixtureLoader::create()->load('simple-page');

        self::assertSame(['simple-page'], LoaderHookSpy::$beforeCalls);
        self::assertSame(['simple-page'], LoaderHookSpy::$afterCalls);
    }

    public function testGetAvailableFixturesReturnsConfiguredNames(): void
    {
        self::assertSame(['simple-page'], FixtureLoader::create()->getAvailableFixtures());
    }

    public function testLoadAppliesPostActions(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'page-with-postaction' => [
                'path' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/page-with-postaction.yml',
                'post_actions' => [
                    [
                        'action' => 'publish_recursive',
                        'class' => E2eVersionedObject::class,
                        'identifier' => 'obj1',
                    ],
                ],
            ],
        ]);

        $result = FixtureLoader::create()->load('page-with-postaction');

        $objId = $result->fixtureMap[E2eVersionedObject::class]['obj1'];
        $live = Versioned::get_by_stage(E2eVersionedObject::class, Versioned::LIVE)->byID($objId);
        self::assertNotNull($live);
    }

    /**
     * @return array<int, SiteTree>
     */
    private function draftPagesWithSegmentPrefix(string $prefix): array
    {
        return Versioned::withVersionedMode(static function () use ($prefix): array {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->filter('URLSegment:StartsWith', $prefix)->toArray();
        });
    }
}
