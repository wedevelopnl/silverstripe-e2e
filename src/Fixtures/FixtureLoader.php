<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Fixtures;

use InvalidArgumentException;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Dev\FixtureBlueprint;
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
     * SiteTree ClassNames the loader prefers when resolving the page a fixture
     * load should navigate to.
     *
     * {@see findPageInFactory()} walks this list in order and falls back to the
     * first SiteTree subclass the fixture created, so this is a preference, not
     * an allowlist. Cleanup is governed by {@see $purge_classes}.
     *
     * @var list<class-string<SiteTree>>
     */
    private static array $fixture_page_classes = [];

    /**
     * Classes whose entire contents belong to E2E and {@see reset()} deletes.
     *
     * Ownership is declared here, not inferred from the data. reset() removes
     * EVERY record of these classes on both stages, however it was created:
     * fixture-written records, records a spec produced by driving the CMS, and
     * debris from an earlier crashed run alike. Listing a class therefore
     * declares its contents disposable, so the E2E database must be one nobody
     * minds losing.
     *
     * List base classes — subclasses come along, so `Page` also purges an
     * `ErrorPage`. Never list identity or configuration classes: purging
     * `Member` takes the admin account the Playwright session logs in with.
     *
     * Empty by default: reset() refuses to run until a consumer declares its
     * scope, so it can never wipe data nobody put in its charge.
     *
     * @var list<class-string<DataObject>>
     */
    private static array $purge_classes = [];

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
     * Config statics to force on specific classes while fixtures are written.
     *
     * For each class, the given key/value pairs are applied via a
     * FixtureBlueprint `beforeCreate` callback, so the override is set inside
     * FixtureBlueprint's own Config::nest()/unnest() window and reverts once the
     * record is written — it never leaks into normal app code.
     *
     * This is the declarative form of the common `onBeforeLoad` use case:
     * suppressing write-time side effects (auto-scaffolding, auto-publishing,
     * denormalisation hooks) whose trigger is a config static. Anything a static
     * value cannot express (dynamic values, non-config side effects) still
     * belongs in an `onBeforeLoad` extension.
     *
     * @var array<class-string, array<string, scalar>>
     */
    private static array $config_overrides = [];

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
     *         Fixture names are used verbatim as JSON object keys in the controller
     *         response, so they must be non-numeric (a set of sequential
     *         numeric-string names would make json_encode emit a JSON array
     *         instead of an object).
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

        // Resolve every fixture path BEFORE resetting, so a misconfigured
        // fixture cannot wipe E2E data or leave a partial seed (mirrors the
        // resolve-before-reset ordering in load()).
        $paths = [];
        foreach ($names as $name) {
            /** @var non-empty-string $name */
            $paths[$name] = $this->resolveFixturePath($name);
        }

        $this->reset();

        $results = [];
        foreach ($paths as $name => $path) {
            /** @var non-empty-string $name */
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
        // Apply declarative config overrides before the onBeforeLoad hook so a
        // consumer's dynamic extension can still override the same class (a later
        // FixtureFactory::define() replaces an earlier blueprint for that class).
        $this->applyConfigOverrides($factory);
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
     * Delete every record of the configured {@see $purge_classes}, both stages.
     *
     * @throws RuntimeException if no purge classes are configured.
     */
    public function reset(): void
    {
        /** @var list<class-string<DataObject>> $purgeClasses */
        $purgeClasses = static::config()->get('purge_classes');
        if ($purgeClasses === []) {
            throw new RuntimeException(
                'FixtureLoader.purge_classes is empty; refusing to reset. Configure the classes '
                . 'your E2E database owns before loading or resetting fixtures.',
            );
        }

        Versioned::withVersionedMode(function () use ($purgeClasses): void {
            Versioned::set_stage(Versioned::DRAFT);
            foreach ($purgeClasses as $class) {
                $this->archiveDraftRecords($class);
            }

            Versioned::set_stage(Versioned::LIVE);
            foreach ($purgeClasses as $class) {
                $this->deleteLiveRemainders($class);
            }
        });
    }

    /**
     * Remove every record of $class that the draft stage can see.
     *
     * The list is materialised first: the loop deletes rows out of the very
     * result set it walks, and one record's $cascade_deletes can take another
     * the same query returned. Deleting a record twice is a no-op, so a record
     * already carried off by a cascade needs no special handling.
     *
     * @param class-string<DataObject> $class
     */
    private function archiveDraftRecords(string $class): void
    {
        foreach (DataObject::get($class)->toArray() as $record) {
            if ($record->hasExtension(Versioned::class)) {
                /** @var DataObject&Versioned $record */
                // Clears draft AND live at once, carrying $cascade_deletes with it.
                $record->doArchive();

                continue;
            }

            $record->delete();
        }
    }

    /**
     * Remove records of $class that survive only on live.
     *
     * A record whose draft row was deleted without an unpublish still serves
     * from the live stage, where no draft query can reach it — so the pass
     * above leaves it behind and nothing else ever collects it.
     *
     * @param class-string<DataObject> $class
     */
    private function deleteLiveRemainders(string $class): void
    {
        foreach (DataObject::get($class)->toArray() as $record) {
            // Unversioned classes have no live stage; the draft pass took them.
            if ($record->hasExtension(Versioned::class)) {
                /** @var DataObject&Versioned $record */
                $record->deleteFromStage(Versioned::LIVE);
            }
        }
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
     * Register blueprints that force {@see $config_overrides} statics during the
     * write of each targeted class.
     *
     * Each override is set from a `beforeCreate` callback, which FixtureBlueprint
     * runs inside its own Config::nest()/unnest() window — so the value is live
     * for that record's write only and is restored immediately afterwards.
     */
    private function applyConfigOverrides(FixtureFactory $factory): void
    {
        /** @var array<class-string, array<string, scalar>> $overrides */
        $overrides = static::config()->get('config_overrides');

        foreach ($overrides as $class => $settings) {
            $blueprint = new FixtureBlueprint($class);
            $blueprint->addCallback('beforeCreate', static function () use ($class, $settings): void {
                foreach ($settings as $key => $value) {
                    Config::modify()->set($class, $key, $value);
                }
            });
            $factory->define($class, $blueprint);
        }
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
