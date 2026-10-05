<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Fixtures;

use InvalidArgumentException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Director;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Declarative post-action applied after YAML fixture loading.
 *
 * Encapsulates the definition and execution of state manipulation (publish,
 * unpublish, modify, attach_image, attach_file) on fixture records. Used by
 * {@see FixtureLoader} after YAML writing. Operates on any DataObject and
 * carries no domain knowledge.
 */
final readonly class FixturePostAction
{
    /** @var list<string> */
    private const array VALID_ACTIONS = [
        'publish_recursive',
        'unpublish',
        'modify',
        'attach_image',
        'attach_file',
    ];

    /** @var list<string> Actions that do not require the Versioned extension. */
    private const array NON_VERSIONED_ACTIONS = [
        'modify',
        'attach_image',
        'attach_file',
    ];

    /**
     * @param 'publish_recursive'|'unpublish'|'modify'|'attach_image'|'attach_file' $action
     * @param class-string $class
     * @param array<string, string|int|float|bool> $fields
     */
    public function __construct(
        public string $action,
        public string $class,
        public string $identifier,
        public array $fields = [],
    ) {
    }

    /**
     * @param array{action?: string, class?: class-string, identifier?: string, fields?: array<string, string|int|float|bool>} $config
     */
    public static function fromConfig(array $config): self
    {
        if (
            !is_string($config['action'] ?? null)
            || !is_string($config['class'] ?? null)
            || !is_string($config['identifier'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'Post-action config requires "action", "class", and "identifier" keys',
            );
        }

        $action = $config['action'];
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown post-action "%s". Valid actions: %s',
                    $action,
                    implode(', ', self::VALID_ACTIONS),
                ),
            );
        }

        /** @var array<string, string|int|float|bool> $fields */
        $fields = $config['fields'] ?? [];

        /** @var 'publish_recursive'|'unpublish'|'modify'|'attach_image'|'attach_file' $action */
        return new self(
            action: $action,
            class: $config['class'],
            identifier: $config['identifier'],
            fields: $fields,
        );
    }

    /**
     * Execute this action against the given record.
     */
    public function apply(DataObject $record): void
    {
        if (
            !in_array($this->action, self::NON_VERSIONED_ACTIONS, true)
            && !$record->hasExtension(Versioned::class)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Post-action "%s" requires Versioned extension, but %s does not have it.',
                $this->action,
                $record::class,
            ));
        }

        /** @var DataObject&Versioned $record */
        match ($this->action) {
            'publish_recursive' => $record->publishRecursive(),
            'unpublish' => $record->doUnpublish(),
            'modify' => $this->applyModify($record),
            'attach_image' => $this->applyAttachImage($record),
            'attach_file' => $this->applyAttachFile($record),
        };
    }

    private function applyModify(DataObject $record): void
    {
        foreach ($this->fields as $field => $value) {
            $record->setField($field, $value);
        }

        $record->write();
    }

    /**
     * Create an Image from a local file and attach it via a has_one relation.
     *
     * Requires `fields.relation` (has_one name) and `fields.source` (module
     * resource path). The image is published so it appears on the live site.
     */
    private function applyAttachImage(DataObject $record): void
    {
        [$relation, $sourcePath] = $this->resolveAttachment();

        $image = Image::create();
        $image->setFromLocalFile($sourcePath, 'Uploads/' . basename($sourcePath));
        $image->write();
        $image->publishSingle();

        $this->linkAttachment($record, $relation, $image);
    }

    /**
     * Attach a local file via a has_one relation, as the File subclass its
     * extension maps to (`File.class_for_file_extension`) — the class a CMS
     * upload of the same file gets, so its write hooks run as they would there.
     *
     * Requires `fields.relation` and `fields.source` as attach_image does;
     * `fields.folder` defaults to Uploads. A file already at the target path is
     * reused as it is, so a reload does not pile up renamed copies (purging File
     * would wipe every asset); delete it to pick up a changed source.
     */
    private function applyAttachFile(DataObject $record): void
    {
        [$relation, $sourcePath] = $this->resolveAttachment();
        $folder = trim((string) ($this->fields['folder'] ?? 'Uploads'), '/');
        $filename = $folder . '/' . basename($sourcePath);

        $file = File::find($filename);
        if ($file === null) {
            $class = File::get_class_for_file_extension(File::get_file_extension($sourcePath));
            /** @var File $file */
            $file = $class::create();
            $file->setFromLocalFile($sourcePath, $filename);
            $file->write();
        }

        $file->publishSingle();

        $this->linkAttachment($record, $relation, $file);
    }

    /**
     * @return array{non-empty-string, non-empty-string} The relation name and the absolute source path.
     */
    private function resolveAttachment(): array
    {
        /** @var string $relation */
        $relation = $this->fields['relation'] ?? '';
        /** @var string $source */
        $source = $this->fields['source'] ?? '';

        if ($relation === '' || $source === '') {
            throw new InvalidArgumentException(
                sprintf('%s requires "relation" and "source" in fields', $this->action),
            );
        }

        $resolved = ModuleResourceLoader::singleton()->resolvePath($source);
        if ($resolved === null) {
            throw new InvalidArgumentException(
                sprintf('Could not resolve %s source path: %s', $this->action, $source),
            );
        }

        $absolutePath = Director::baseFolder() . '/' . $resolved;
        if (!file_exists($absolutePath)) {
            throw new InvalidArgumentException(
                sprintf('%s source file not found: %s (resolved from "%s")', $this->action, $absolutePath, $source),
            );
        }

        return [$relation, $absolutePath];
    }

    private function linkAttachment(DataObject $record, string $relation, File $file): void
    {
        $record->setField($relation . 'ID', $file->ID);
        $record->write();
    }
}
