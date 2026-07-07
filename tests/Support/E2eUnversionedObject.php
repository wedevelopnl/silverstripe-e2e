<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Support;

use SilverStripe\Assets\Image;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class E2eUnversionedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'E2eUnversionedObject';

    /** @var array<string, string> */
    private static array $db = [
        'Title' => 'Varchar(255)',
    ];

    /** @var array<string, class-string> */
    private static array $has_one = [
        'Image' => Image::class,
    ];
}
