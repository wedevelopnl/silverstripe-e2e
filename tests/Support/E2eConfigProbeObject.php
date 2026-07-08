<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Records the value of its config statics as observed during its own write.
 *
 * Lets tests prove that FixtureLoader.config_overrides reaches the record at
 * write time (the overridden value is what {@see $observed} captures) and that
 * the override is scoped to the write (the live config reverts afterwards).
 */
class E2eConfigProbeObject extends DataObject implements TestOnly
{
    private static string $table_name = 'E2eConfigProbeObject';

    /** @var array<string, string> */
    private static array $db = [
        'Title' => 'Varchar(255)',
    ];

    private static string $probe_alpha = 'default';

    private static string $probe_beta = 'default';

    /**
     * Config values seen during writes since the last reset, keyed by config
     * name. Overwritten per write, so it reflects the most recent probe.
     *
     * @var array<string, string>
     */
    public static array $observed = [];

    public static function reset(): void
    {
        self::$observed = [];
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        self::$observed = [
            'probe_alpha' => (string) static::config()->get('probe_alpha'),
            'probe_beta' => (string) static::config()->get('probe_beta'),
        ];
    }
}
