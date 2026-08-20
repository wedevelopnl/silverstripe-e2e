<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Stand-in for a container model that auto-scaffolds a child on write.
 *
 * Mirrors the real-world case config_overrides exists for: a model whose
 * onAfterWrite() creates extra records unless a config static disables it.
 * When `auto_scaffold` is true a single child is scaffolded; a config override
 * setting it false during the fixture write suppresses that child.
 */
class E2eScaffoldingObject extends DataObject implements TestOnly
{
    private static string $table_name = 'E2eScaffoldingObject';

    /** @var array<string, string> */
    private static array $db = [
        'Title' => 'Varchar(255)',
    ];

    /** @var array<string, class-string> */
    private static array $has_one = [
        'Container' => self::class,
    ];

    /** @var array<string, string> */
    private static array $has_many = [
        'Scaffolded' => self::class . '.Container',
    ];

    /**
     * Deleting a container takes the child it scaffolded with it, so a purge of
     * this class deletes records the same query already returned.
     *
     * @var list<string>
     */
    private static array $cascade_deletes = [
        'Scaffolded',
    ];

    /**
     * When true, writing this object scaffolds one child. Consumers suppress
     * this during fixture loads via FixtureLoader.config_overrides.
     */
    private static bool $auto_scaffold = true;

    /** Marks factory-created children so they never scaffold recursively. */
    private bool $isScaffoldChild = false;

    public function markAsScaffoldChild(): void
    {
        $this->isScaffoldChild = true;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();

        if ($this->isScaffoldChild) {
            return;
        }

        if (!static::config()->get('auto_scaffold')) {
            return;
        }

        $child = new self();
        $child->markAsScaffoldChild();
        $child->Title = 'scaffolded-' . $this->Title;
        $child->ContainerID = $this->ID;
        $child->write();
    }
}
