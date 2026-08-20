<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration\Fixtures;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\E2e\Tests\Support\E2eConfigProbeObject;
use WeDevelop\E2e\Tests\Support\E2eFixtureTestPage;
use WeDevelop\E2e\Tests\Support\E2eOtherTestPage;
use WeDevelop\E2e\Tests\Support\E2eScaffoldingObject;
use WeDevelop\E2e\Tests\Support\E2eUnversionedObject;
use WeDevelop\E2e\Tests\Support\E2eVersionedObject;
use WeDevelop\E2e\Tests\Support\LoaderHookSpy;

#[CoversClass(FixtureLoader::class)]
final class FixtureLoaderTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        E2eFixtureTestPage::class,
        E2eOtherTestPage::class,
        E2eVersionedObject::class,
        E2eUnversionedObject::class,
        E2eScaffoldingObject::class,
        E2eConfigProbeObject::class,
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
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [
            E2eFixtureTestPage::class,
        ]);
        LoaderHookSpy::reset();
        E2eConfigProbeObject::reset();
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

    public function testResetRemovesEveryRecordOfAPurgedClass(): void
    {
        FixtureLoader::create()->load('simple-page');

        // No 'e2e-' prefix and not fixture-written: ownership follows the
        // declared class, not a property of the record.
        $handWritten = new E2eFixtureTestPage();
        $handWritten->Title = 'Hand authored';
        $handWritten->URLSegment = 'hand-authored';
        $handWritten->write();

        FixtureLoader::create()->reset();

        self::assertSame(0, $this->draftCount(E2eFixtureTestPage::class));
    }

    public function testResetLeavesClassesOutsideThePurgeScope(): void
    {
        $stray = new E2eOtherTestPage();
        $stray->Title = 'Out of scope';
        $stray->URLSegment = 'e2e-out-of-scope';
        $stray->write();

        $before = $this->draftCount(E2eOtherTestPage::class);

        FixtureLoader::create()->reset();

        self::assertSame($before, $this->draftCount(E2eOtherTestPage::class));
    }

    public function testResetPurgesSubclassesOfAPurgedClass(): void
    {
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [SiteTree::class]);

        $page = new E2eFixtureTestPage();
        $page->Title = 'In scope by base class';
        $page->write();
        $other = new E2eOtherTestPage();
        $other->Title = 'Also in scope by base class';
        $other->write();

        FixtureLoader::create()->reset();

        self::assertSame(0, $this->draftCount(SiteTree::class));
    }

    public function testResetRemovesVersionedRecordsThatHaveNoPage(): void
    {
        // The case a URLSegment-keyed reset can never see: a library record with
        // no page and no URL, which every run would otherwise leave behind.
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [E2eVersionedObject::class]);

        $object = new E2eVersionedObject();
        $object->Title = 'Library record';
        $object->write();
        $object->publishSingle();
        $id = (int) $object->ID;

        FixtureLoader::create()->reset();

        self::assertNull(Versioned::get_by_stage(E2eVersionedObject::class, Versioned::DRAFT)->byID($id));
        self::assertNull(Versioned::get_by_stage(E2eVersionedObject::class, Versioned::LIVE)->byID($id));
    }

    public function testResetRemovesUnversionedRecords(): void
    {
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [E2eUnversionedObject::class]);

        $object = new E2eUnversionedObject();
        $object->Title = 'Unversioned record';
        $object->write();

        FixtureLoader::create()->reset();

        self::assertSame(0, E2eUnversionedObject::get()->count());
    }

    public function testResetRemovesRecordsThatSurviveOnlyOnLive(): void
    {
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [E2eVersionedObject::class]);

        $object = new E2eVersionedObject();
        $object->Title = 'Dropped from draft while published';
        $object->write();
        $object->publishSingle();
        $id = (int) $object->ID;
        // Deleting the draft row without unpublishing leaves the record serving
        // from live, out of reach of any draft-stage query.
        $object->deleteFromStage(Versioned::DRAFT);

        FixtureLoader::create()->reset();

        self::assertNull(Versioned::get_by_stage(E2eVersionedObject::class, Versioned::LIVE)->byID($id));
    }

    public function testResetSurvivesRecordsCascadingIntoEachOther(): void
    {
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [E2eScaffoldingObject::class]);

        // Writing the container scaffolds a child that cascade-deletes with it,
        // so the purge deletes a record its own query already returned.
        $container = new E2eScaffoldingObject();
        $container->Title = 'container';
        $container->write();

        FixtureLoader::create()->reset();

        self::assertSame(0, E2eScaffoldingObject::get()->count());
    }

    public function testResetSurvivesVersionedRecordsCascadingIntoEachOther(): void
    {
        // The shape a page-plus-elements purge has: archiving the container
        // cascade-deletes the contained record the same query already returned.
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [E2eVersionedObject::class]);

        $container = new E2eVersionedObject();
        $container->Title = 'container';
        $container->write();
        $contained = new E2eVersionedObject();
        $contained->Title = 'contained';
        $contained->ContainerID = $container->ID;
        $contained->write();
        $contained->publishSingle();

        FixtureLoader::create()->reset();

        self::assertSame(0, $this->draftCount(E2eVersionedObject::class));
        self::assertCount(0, Versioned::get_by_stage(E2eVersionedObject::class, Versioned::LIVE)->toArray());
    }

    public function testResetThrowsWhenPurgeClassesEmpty(): void
    {
        Config::modify()->set(FixtureLoader::class, 'purge_classes', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('purge_classes is empty');

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

    public function testLoadThrowsWhenFixtureCreatesNoSiteTree(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'no-sitetree' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/no-sitetree.yml',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fixture "no-sitetree" did not create any SiteTree records');

        FixtureLoader::create()->load('no-sitetree');
    }

    public function testLoadFallsBackToNonAllowlistedSiteTreeSubclass(): void
    {
        // fixture_page_classes only lists E2eFixtureTestPage, but the fixture
        // creates an E2eOtherTestPage. The preferred-class lookup misses, so the
        // loader falls back to the first SiteTree subclass the factory produced.
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'fallback-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/fallback-page.yml',
        ]);

        $result = FixtureLoader::create()->load('fallback-page');

        self::assertArrayHasKey(E2eOtherTestPage::class, $result->fixtureMap);
        self::assertGreaterThan(0, $result->pageId);
        self::assertStringContainsString('e2e-fallback', $result->pageUrl);
    }

    public function testLoadThrowsWhenArrayConfigHasNoPath(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'broken' => ['post_actions' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fixture "broken" has no path configured');

        FixtureLoader::create()->load('broken');
    }

    public function testLoadThrowsWhenFixtureFileIsMissing(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'missing-file' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/does-not-exist.yml',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fixture file not found');

        FixtureLoader::create()->load('missing-file');
    }

    public function testLoadThrowsWhenPostActionReferencesUnknownFixture(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'bad-postaction' => [
                'path' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
                'post_actions' => [
                    [
                        'action' => 'modify',
                        'class' => E2eVersionedObject::class,
                        'identifier' => 'nonexistent',
                        'fields' => ['Title' => 'unused'],
                    ],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Post-action references unknown fixture: ' . E2eVersionedObject::class . '.nonexistent',
        );

        FixtureLoader::create()->load('bad-postaction');
    }

    public function testLoadAllLoadsEveryConfiguredFixtureInOrder(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
            'fallback-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/fallback-page.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'fixture_page_classes', [
            E2eFixtureTestPage::class,
            E2eOtherTestPage::class,
        ]);

        $results = FixtureLoader::create()->loadAll();

        self::assertSame(['simple-page', 'fallback-page'], array_keys($results));
        self::assertGreaterThan(0, $results['simple-page']->pageId);
        self::assertGreaterThan(0, $results['fallback-page']->pageId);
        self::assertSame('simple-page', $results['simple-page']->fixtureName);
    }

    public function testLoadAllResetsOnceSoFixturesCoexist(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
            'fallback-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/fallback-page.yml',
        ]);
        // Both classes are in the purge scope: if reset ran per-fixture, loading
        // the second fixture would wipe the first. Coexistence proves reset ran once.
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [
            E2eFixtureTestPage::class,
            E2eOtherTestPage::class,
        ]);

        FixtureLoader::create()->loadAll();

        self::assertCount(1, $this->draftPagesWithSegmentPrefix('e2e-simple'));
        self::assertCount(1, $this->draftPagesWithSegmentPrefix('e2e-fallback'));
    }

    public function testLoadAllThrowsAndDoesNotResetWhenNoFixturesConfigured(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', []);

        // A pre-existing page a reset WOULD wipe; it must survive the throw.
        $existing = new E2eFixtureTestPage();
        $existing->Title = 'Pre-existing';
        $existing->URLSegment = 'e2e-preexisting';
        $existing->write();

        try {
            FixtureLoader::create()->loadAll();
            self::fail('Expected InvalidArgumentException for empty fixtures config');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('No fixtures', $invalidArgumentException->getMessage());
        }

        self::assertCount(1, $this->draftPagesWithSegmentPrefix('e2e-preexisting'));
    }

    public function testLoadAllIsFailFastWhenAFixtureCreatesNoSiteTree(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
            'broken' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/no-sitetree.yml',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not create any SiteTree');

        FixtureLoader::create()->loadAll();
    }

    public function testLoadAllIsFailFastWhenAFixturePathIsInvalid(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
            'missing-file' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/does-not-exist.yml',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fixture file not found');

        FixtureLoader::create()->loadAll();
    }

    public function testLoadAllDoesNotResetWhenAFixturePathIsInvalid(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
            'missing-file' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/does-not-exist.yml',
        ]);
        // A pre-existing page a reset WOULD wipe: an invalid path must be caught
        // before reset, so this survives and the valid fixture is NOT loaded.
        $existing = new E2eFixtureTestPage();
        $existing->Title = 'Pre-existing';
        $existing->URLSegment = 'e2e-preexisting';
        $existing->write();

        // Baseline instead of an absolute 0: SapphireTest's per-test DB isolation
        // is not effective in this environment (verified: no START TRANSACTION /
        // ROLLBACK is ever issued against the test DB across the whole run), so a
        // sibling test's own 'simple-page' load can still be visible here. What
        // M1 guarantees is that THIS call adds no further 'e2e-simple' page.
        $simplePageCountBefore = count($this->draftPagesWithSegmentPrefix('e2e-simple'));

        try {
            FixtureLoader::create()->loadAll();
            self::fail('Expected InvalidArgumentException for invalid fixture path');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('Fixture file not found', $invalidArgumentException->getMessage());
        }

        self::assertCount(1, $this->draftPagesWithSegmentPrefix('e2e-preexisting'));
        self::assertCount($simplePageCountBefore, $this->draftPagesWithSegmentPrefix('e2e-simple'));
    }

    public function testConfigOverrideReachesRecordDuringWrite(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'config-overrides' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/config-overrides.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'config_overrides', [
            E2eConfigProbeObject::class => ['probe_alpha' => 'overridden'],
        ]);

        FixtureLoader::create()->load('config-overrides');

        self::assertSame('overridden', E2eConfigProbeObject::$observed['probe_alpha']);
    }

    public function testConfigOverrideDoesNotLeakPastLoad(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'config-overrides' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/config-overrides.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'config_overrides', [
            E2eConfigProbeObject::class => ['probe_alpha' => 'overridden'],
        ]);

        FixtureLoader::create()->load('config-overrides');

        // The override is scoped to each record's write by FixtureBlueprint's
        // Config::nest()/unnest(); normal app code sees the declared default.
        self::assertSame('default', Config::inst()->get(E2eConfigProbeObject::class, 'probe_alpha'));
    }

    public function testConfigOverridesApplyMultipleClassesAndKeys(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'config-overrides' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/config-overrides.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'config_overrides', [
            E2eScaffoldingObject::class => ['auto_scaffold' => false],
            E2eConfigProbeObject::class => [
                'probe_alpha' => 'alpha-override',
                'probe_beta' => 'beta-override',
            ],
        ]);

        $childrenBefore = $this->countScaffoldChildren();

        FixtureLoader::create()->load('config-overrides');

        // auto_scaffold=false suppressed the child on E2eScaffoldingObject...
        self::assertSame($childrenBefore, $this->countScaffoldChildren());
        // ...and both keys on the second class took effect at write time.
        self::assertSame('alpha-override', E2eConfigProbeObject::$observed['probe_alpha']);
        self::assertSame('beta-override', E2eConfigProbeObject::$observed['probe_beta']);
    }

    public function testAbsentConfigOverridesIsNoOp(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'config-overrides' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/config-overrides.yml',
        ]);
        // No config_overrides configured (default []): the model's own
        // auto_scaffold default (true) stands and its config statics are untouched.
        $childrenBefore = $this->countScaffoldChildren();

        FixtureLoader::create()->load('config-overrides');

        self::assertSame($childrenBefore + 1, $this->countScaffoldChildren());
        self::assertSame('default', E2eConfigProbeObject::$observed['probe_alpha']);
        self::assertSame('default', E2eConfigProbeObject::$observed['probe_beta']);
    }

    private function countScaffoldChildren(): int
    {
        return (int) E2eScaffoldingObject::get()->filter('Title', 'scaffolded-container')->count();
    }

    /**
     * @param class-string<DataObject> $class
     */
    private function draftCount(string $class): int
    {
        return Versioned::withVersionedMode(static function () use ($class): int {
            Versioned::set_stage(Versioned::DRAFT);

            return (int) DataObject::get($class)->count();
        });
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
