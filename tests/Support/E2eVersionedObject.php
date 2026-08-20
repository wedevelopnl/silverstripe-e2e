<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

class E2eVersionedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'E2eVersionedObject';

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
        'Contained' => self::class . '.Container',
    ];

    /** @var list<string> */
    private static array $cascade_deletes = [
        'Contained',
    ];

    /** @var array<string, string> */
    private static array $extensions = [
        Versioned::class,
    ];
}
