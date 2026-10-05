<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration\Fixtures;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
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

    private const string ASSETS = 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/assets/';

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('FixturePostActionApplyTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

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
        $this->expectExceptionMessage('attach_image source file not found');

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

    /**
     * @return iterable<string, array{string, class-string<File>}>
     */
    public static function attachFileSources(): iterable
    {
        yield 'a png becomes an Image' => ['test-image.png', Image::class];
        yield 'an unmapped extension stays a File' => ['test-file.txt', File::class];
    }

    /**
     * @param class-string<File> $expectedClass
     */
    #[DataProvider('attachFileSources')]
    public function testAttachFileCreatesTheMappedClassInTheFolderPublishedAndLinked(
        string $source,
        string $expectedClass,
    ): void {
        $record = new E2eUnversionedObject();
        $record->write();

        $this->attachFile($record, $source);

        $file = File::get()->byID($record->FileID);
        self::assertNotNull($file);
        self::assertSame($expectedClass, $file::class);
        self::assertSame('Icons/' . $source, $file->getFilename());
        self::assertTrue($file->isPublished());
    }

    public function testAttachFileReusesTheFileAlreadyAtTheTargetPath(): void
    {
        $first = new E2eUnversionedObject();
        $first->write();
        $second = new E2eUnversionedObject();
        $second->write();

        $this->attachFile($first, 'test-file.txt');
        $this->attachFile($second, 'test-file.txt');

        self::assertSame($first->FileID, $second->FileID);
        self::assertCount(1, File::get()->filter('Name:StartsWith', 'test-file'));
    }

    public function testAttachFileThrowsWhenRelationOrSourceMissing(): void
    {
        $record = new E2eUnversionedObject();
        $record->write();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attach_file requires "relation" and "source"');

        (new FixturePostAction('attach_file', E2eUnversionedObject::class, 'x', ['source' => self::ASSETS . 'test-file.txt']))
            ->apply($record);
    }

    public function testVersionedActionOnUnversionedRecordThrows(): void
    {
        $record = new E2eUnversionedObject();
        $record->write();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires Versioned extension');

        (new FixturePostAction('publish_recursive', E2eUnversionedObject::class, 'x'))->apply($record);
    }

    private function attachFile(E2eUnversionedObject $record, string $source): void
    {
        (new FixturePostAction(
            'attach_file',
            E2eUnversionedObject::class,
            'x',
            ['relation' => 'File', 'source' => self::ASSETS . $source, 'folder' => 'Icons'],
        ))->apply($record);
    }
}
