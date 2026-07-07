<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration\Fixtures;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\E2e\Fixtures\FixturePostAction;
use WeDevelop\E2e\Tests\Support\E2eUnversionedObject;
use WeDevelop\E2e\Tests\Support\E2eVersionedObject;

#[CoversClass(FixturePostAction::class)]
final class FixturePostActionApplyTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        E2eVersionedObject::class,
        E2eUnversionedObject::class,
    ];

    public function testPublishRecursivePublishesVersionedRecord(): void
    {
        $record = new E2eVersionedObject();
        $record->Title = 'Draft';
        $record->write();

        (new FixturePostAction('publish_recursive', E2eVersionedObject::class, 'x'))->apply($record);

        $live = Versioned::get_by_stage(E2eVersionedObject::class, Versioned::LIVE)->byID($record->ID);
        self::assertNotNull($live);
    }

    public function testUnpublishRemovesLiveVersion(): void
    {
        $record = new E2eVersionedObject();
        $record->Title = 'Draft';
        $record->write();
        $record->publishRecursive();

        (new FixturePostAction('unpublish', E2eVersionedObject::class, 'x'))->apply($record);

        $live = Versioned::get_by_stage(E2eVersionedObject::class, Versioned::LIVE)->byID($record->ID);
        self::assertNull($live);
    }

    public function testModifySetsFieldsAndWrites(): void
    {
        $record = new E2eUnversionedObject();
        $record->Title = 'Old';
        $record->write();

        (new FixturePostAction('modify', E2eUnversionedObject::class, 'x', ['Title' => 'New']))->apply($record);

        $reloaded = E2eUnversionedObject::get()->byID($record->ID);
        self::assertNotNull($reloaded);
        self::assertSame('New', $reloaded->Title);
    }

    public function testAttachImageCreatesAndLinksImage(): void
    {
        $record = new E2eUnversionedObject();
        $record->Title = 'HasImage';
        $record->write();

        (new FixturePostAction(
            'attach_image',
            E2eUnversionedObject::class,
            'x',
            [
                'relation' => 'Image',
                'source' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/assets/test-image.png',
            ],
        ))->apply($record);

        $reloaded = E2eUnversionedObject::get()->byID($record->ID);
        self::assertNotNull($reloaded);
        self::assertGreaterThan(0, (int) $reloaded->ImageID);
    }

    public function testAttachImageThrowsWhenRelationOrSourceMissing(): void
    {
        $record = new E2eUnversionedObject();
        $record->write();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attach_image requires "relation" and "source"');

        (new FixturePostAction('attach_image', E2eUnversionedObject::class, 'x', ['relation' => 'Image']))
            ->apply($record);
    }

    public function testAttachImageThrowsWhenSourceFileIsMissing(): void
    {
        $record = new E2eUnversionedObject();
        $record->write();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file not found');

        (new FixturePostAction(
            'attach_image',
            E2eUnversionedObject::class,
            'x',
            [
                'relation' => 'Image',
                'source' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/assets/missing-image.png',
            ],
        ))->apply($record);
    }

    public function testVersionedActionOnUnversionedRecordThrows(): void
    {
        $record = new E2eUnversionedObject();
        $record->write();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires Versioned extension');

        (new FixturePostAction('publish_recursive', E2eUnversionedObject::class, 'x'))->apply($record);
    }
}
