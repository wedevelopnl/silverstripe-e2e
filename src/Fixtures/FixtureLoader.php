<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Fixtures;

use InvalidArgumentException;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Loads and resets YAML fixtures at runtime for E2E tests.
 *
 * Playwright calls {@see FixtureController}, which delegates here. Fixtures are
 * registered via config and resolved through ModuleResourceLoader so
 * vendor:path syntax works. A fixture value is either a plain path string or an
 * array with `path` and optional `post_actions`.
 *
 * This class carries NO domain knowledge. Consuming modules inject behavior
 * (e.g. suppressing model auto-scaffolding during a fixture write) through the
 * `onBeforeLoad`/`onAfterLoad` extension hooks.
 */
class FixtureLoader
{
    use Injectable;
    use Configurable;
    use Extensible;

    /**
     * URLSegment prefix marking a page as E2E-owned. {@see reset()} only
     * archives pages whose segment starts with this AND whose ClassName is in
     * {@see $fixture_page_classes}.
     */
    private static string $url_segment_prefix = 'e2e-';

    /**
     * SiteTree ClassNames the fixtures may create and reset.
     *
     * {@see reset()} archives a page only when its ClassName matches one of
     * these AND its URLSegment starts with {@see $url_segment_prefix}. The
     * prefix alone is too weak an ownership marker on a shared dev DB. Empty by
     * default: reset() refuses to run until a consumer declares its page
     * classes, so it can never wipe pages it does not own.
     *
     * @var list<class-string<SiteTree>>
     */
    private static array $fixture_page_classes = [];

    /**
     * Map of fixture names to YAML paths or config arrays.
     *
     * String value: 'vendor/package:path/to/file.yml'
     * Array value:  { path: 'vendor/package:path.yml', post_actions: [...] }
     *
     * @var array<string, string|array{path: string, post_actions?: list<array{action: string, class: string, identifier: string, fields?: array<string, string|int|float|bool>}>}>
     */
    private static array $fixtures = [];

    /**
     * Load a single named fixture into the database.
     *
     * Resets existing E2E data first for idempotency, then writes the YAML,
     * applies any post-actions, and returns the page ID and full fixture map.
     *
     * @param non-empty-string $name
     */
    public function load(string $name): FixtureResult
    {
        // Resolve/validate before resetting so an unknown name cannot wipe data.
        $path = $this->resolveFixturePath($name);
        $this->reset();

        return $this->loadFixtureFromPath($name, $path);
    }

    /**
     * Reset once, then load every configured fixture additively.
     *
     * Each fixture is written into its own factory (no shared identifier scope,
     * so fixtures cannot cross-reference one another). Fail-fast: the first
     * fixture that raises aborts the loop, leaving a partial seed that the next
     * load() / loadAll() resets again.
     *
     * @return array<string, FixtureResult> Fixture name => result, in config order.
     * @throws InvalidArgumentException when no fixtures are configured (before any
     *         reset) or a fixture name/path is invalid.
     * @throws RuntimeException when a fixture creates no SiteTree records.
     */
    public function loadAll(): array
    {
        $names = $this->getAvailableFixtures();
        if ($names === []) {
            throw new InvalidArgumentException(
                'No fixtures are configured; nothing to load.',
            );
        }

        $this->reset();

        $results = [];
        foreach ($names as $name) {
            /** @var non-empty-string $name */
            $path = $this->resolveFixturePath($name);
            $results[$name] = $this->loadFixtureFromPath($name, $path);
        }

        return $results;
    }

    /**
     * Write one already-resolved fixture into a fresh factory. Shared by
     * {@see load()} and {@see loadAll()}; callers are responsible for resetting.
     *
     * @param non-empty-string $name
     */
    private function loadFixtureFromPath(string $name, string $path): FixtureResult
    {
        $factory = new FixtureFactory();
        // Extensible::extend() takes its arguments by reference (&...$arguments),
        // which makes PHPStan widen every passed variable to the union of all of
        // them for the rest of the scope. Pass throwaway aliases so the typed
        // $name/$factory stay pristine; the hook still mutates the same
        // FixtureFactory instance (object handle), so behaviour is unchanged.
        $beforeName = $name;
        $beforeFactory = $factory;
        $this->extend('onBeforeLoad', $beforeName, $beforeFactory);

        $fixture = YamlFixture::create($path);
        Versioned::withVersionedMode(static function () use ($fixture, $factory): void {
            Versioned::set_stage(Versioned::DRAFT);
            $fixture->writeInto($factory);
        });

        $postActions = $this->resolvePostActions($name);
        if ($postActions !== []) {
            $this->applyPostActions($postActions, $factory);
        }

        [$pageClass, $pageId] = $this->findPageInFactory($factory);
        if ($pageClass === null || $pageId === null) {
            throw new RuntimeException(
                sprintf('Fixture "%s" did not create any SiteTree records', $name),
            );
        }

        $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });
        if ($page === null) {
            throw new RuntimeException(
                sprintf('Page ID %d from fixture "%s" not found after write', $pageId, $name),
            );
        }

        /** @var array<string, array<string, int>> $fixtureMap */
        $fixtureMap = $factory->getFixtures();
        /** @var non-empty-string $pageUrl */
        $pageUrl = $page->Link();

        $result = new FixtureResult(
            fixtureName: $name,
            pageId: $pageId,
            pageUrl: $pageUrl,
            fixtureMap: $fixtureMap,
        );

        $this->extend('onAfterLoad', $result);

        return $result;
    }

    /**
     * Archive all E2E fixture pages (draft + live) via doArchive().
     *
     * @throws RuntimeException if no fixture page classes are configured.
     */
    public function reset(): void
    {
        /** @var list<class-string<SiteTree>> $pageClasses */
        $pageClasses = static::config()->get('fixture_page_classes');
        if ($pageClasses === []) {
            throw new RuntimeException(
                'FixtureLoader.fixture_page_classes is empty; refusing to reset. Configure the '
                . 'SiteTree classes your fixtures create before loading or resetting fixtures.',
            );
        }

        /** @var string $prefix */
        $prefix = static::config()->get('url_segment_prefix');

        Versioned::withVersionedMode(static function () use ($pageClasses, $prefix): void {
            Versioned::set_stage(Versioned::DRAFT);

            $pages = SiteTree::get()->filter([
                'URLSegment:StartsWith' => $prefix,
                'ClassName' => $pageClasses,
            ]);

            foreach ($pages as $page) {
                $page->doArchive();
            }
        });
    }

    /**
     * @return list<string>
     */
    public function getAvailableFixtures(): array
    {
        /** @var array<string, string|array<string, mixed>> $fixtures */
        $fixtures = static::config()->get('fixtures');

        return array_keys($fixtures);
    }

    /**
     * Find the page to return. Prefers the first configured
     * {@see $fixture_page_classes} entry the factory created, else the first
     * SiteTree subclass created.
     *
     * @return array{class-string<SiteTree>|null, positive-int|null}
     */
    private function findPageInFactory(FixtureFactory $factory): array
    {
        /** @var array<string, array<string, int>> $fixtures */
        $fixtures = $factory->getFixtures();

        /** @var list<class-string<SiteTree>> $preferred */
        $preferred = static::config()->get('fixture_page_classes');

        foreach ($preferred as $preferredClass) {
            if (isset($fixtures[$preferredClass]) && $fixtures[$preferredClass] !== []) {
                $ids = $fixtures[$preferredClass];
                /** @var positive-int $id */
                $id = $ids[array_key_first($ids)];

                return [$preferredClass, $id];
            }
        }

        foreach ($fixtures as $class => $ids) {
            if (!is_a($class, SiteTree::class, true) || $ids === []) {
                continue;
            }
            /** @var class-string<SiteTree> $class */
            /** @var positive-int $id */
            $id = $ids[array_key_first($ids)];

            return [$class, $id];
        }

        return [null, null];
    }

    /**
     * @throws InvalidArgumentException if the name is unregistered or the file is missing.
     */
    private function resolveFixturePath(string $name): string
    {
        /** @var array<string, string|array<string, mixed>> $fixtures */
        $fixtures = static::config()->get('fixtures');

        if (!isset($fixtures[$name])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown fixture "%s". Available: %s',
                    $name,
                    implode(', ', array_keys($fixtures)),
                ),
            );
        }

        $config = $fixtures[$name];
        $resourcePath = is_array($config) ? ($config['path'] ?? null) : $config;

        if (!is_string($resourcePath) || $resourcePath === '') {
            throw new InvalidArgumentException(
                sprintf('Fixture "%s" has no path configured', $name),
            );
        }

        $resolved = ModuleResourceLoader::singleton()->resolvePath($resourcePath);
        if ($resolved === null) {
            throw new InvalidArgumentException(
                sprintf('Could not resolve fixture path "%s" for fixture "%s"', $resourcePath, $name),
            );
        }

        $absolutePath = Director::baseFolder() . '/' . $resolved;
        if (!file_exists($absolutePath)) {
            throw new InvalidArgumentException(
                sprintf('Fixture file not found: %s (resolved from "%s")', $absolutePath, $resourcePath),
            );
        }

        return $absolutePath;
    }

    /**
     * @return list<FixturePostAction>
     */
    private function resolvePostActions(string $name): array
    {
        /** @var array<string, string|array<string, mixed>> $fixtures */
        $fixtures = static::config()->get('fixtures');

        $config = $fixtures[$name] ?? null;
        if (!is_array($config) || !isset($config['post_actions'])) {
            return [];
        }

        /** @var list<array{action?: string, class?: class-string, identifier?: string, fields?: array<string, string|int|float|bool>}> $rawActions */
        $rawActions = $config['post_actions'];

        return array_map(
            FixturePostAction::fromConfig(...),
            $rawActions,
        );
    }

    /**
     * @param list<FixturePostAction> $actions
     */
    private function applyPostActions(array $actions, FixtureFactory $factory): void
    {
        Versioned::withVersionedMode(static function () use ($actions, $factory): void {
            Versioned::set_stage(Versioned::DRAFT);

            foreach ($actions as $action) {
                $id = $factory->getId($action->class, $action->identifier);
                if ($id === false || $id === 0) {
                    throw new RuntimeException(
                        sprintf(
                            'Post-action references unknown fixture: %s.%s',
                            $action->class,
                            $action->identifier,
                        ),
                    );
                }

                $record = DataObject::get($action->class)->byID($id);
                if ($record === null) {
                    throw new RuntimeException(
                        sprintf(
                            'Record not found for post-action: %s #%d (%s)',
                            $action->class,
                            $id,
                            $action->identifier,
                        ),
                    );
                }

                $action->apply($record);
            }
        });
    }
}
