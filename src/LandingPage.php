<?php

declare(strict_types=1);

namespace Modulento\Landing;

use Modulento\Core\Content\CustomMenu;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\HtmlSanitizer;
use PDO;

/**
 * The one landing page this addon can build, as an ordered list of typed
 * blocks - the same idea the core's own CustomMenu and HomeLayout already
 * use for an ordered list of entries with per-language texts, just kept in
 * this addon's own table. Every block has an id (a short random hex string,
 * stable across edits so the admin form can address one without reordering
 * the rest), a type, "enabled", and whatever fields its type needs below.
 */
final class LandingPage
{
    public const BLOCK_TYPES = ['hero', 'stats', 'cards', 'text', 'cta'];
    public const MAX_BLOCKS = 20;
    public const MAX_ITEMS = 6;
    private const MAX_TEXT = 200;
    private const MAX_BODY = 20000;
    private const ROW_ID = 1;

    public function __construct(private PDO $db)
    {
    }

    /** @return array{published: bool, meta: array<string, array<string, string>>, blocks: list<array<string, mixed>>} */
    public function get(): array
    {
        $stmt = $this->db->prepare('SELECT published, content FROM x_landing_page WHERE id = :id');
        $stmt->execute(['id' => self::ROW_ID]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['published' => false, 'meta' => [], 'blocks' => []];
        }

        $content = json_decode((string) $row['content'], true);
        $content = is_array($content) ? $content : [];

        return [
            'published' => (bool) $row['published'],
            'meta' => is_array($content['meta'] ?? null) ? $content['meta'] : [],
            'blocks' => is_array($content['blocks'] ?? null) ? $content['blocks'] : [],
        ];
    }

    /**
     * Stores the page exactly as the admin form sent it, after cleaning
     * every field - a block of a type this addon no longer knows (should
     * not happen, nothing removes BLOCK_TYPES) is dropped rather than kept
     * half-understood.
     *
     * @param array<string, array<string, string>> $meta locale => {title, meta_description}
     * @param list<array<string, mixed>> $blocks
     */
    public function save(bool $published, array $meta, array $blocks): void
    {
        $cleanMeta = [];
        foreach ($meta as $locale => $fields) {
            $title = self::text($fields['title'] ?? '');
            $description = self::text($fields['meta_description'] ?? '');
            if ($title !== '' || $description !== '') {
                $cleanMeta[$locale] = ['title' => $title, 'meta_description' => $description];
            }
        }

        $cleanBlocks = [];
        foreach (array_slice($blocks, 0, self::MAX_BLOCKS) as $block) {
            $type = (string) ($block['type'] ?? '');
            $id = (string) ($block['id'] ?? '');
            if (!in_array($type, self::BLOCK_TYPES, true) || !preg_match('/^[a-f0-9]{8}$/', $id)) {
                continue;
            }
            $cleanBlocks[] = ['id' => $id, 'type' => $type, 'enabled' => (bool) ($block['enabled'] ?? false)] + self::cleanFields($type, $block);
        }

        $content = (string) json_encode(['meta' => $cleanMeta, 'blocks' => $cleanBlocks], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $exists = $this->db->prepare('SELECT COUNT(*) FROM x_landing_page WHERE id = :id');
        $exists->execute(['id' => self::ROW_ID]);

        $stmt = $this->db->prepare(
            (int) $exists->fetchColumn() > 0
                ? 'UPDATE x_landing_page SET published = :published, content = :content, updated_at = :now WHERE id = :id'
                : 'INSERT INTO x_landing_page (id, published, content, updated_at) VALUES (:id, :published, :content, :now)'
        );
        $stmt->execute(['id' => self::ROW_ID, 'published' => $published ? 1 : 0, 'content' => $content, 'now' => Clock::now()]);
    }

    /**
     * The stored block with one language's fields replaced by what the
     * form posted for it - every other language's text, and anything not
     * tied to a language (a URL), stays as it was. What the controller
     * calls for every block the form named, before handing the whole list
     * to save().
     *
     * @param array<string, mixed> $stored the block as currently stored (id/type/enabled plus its fields)
     * @param array<string, mixed> $posted this block's $_POST entry, one language's values
     * @return array<string, mixed> the fields only (no id/type/enabled)
     */
    public static function applyLocaleEdit(array $stored, string $locale, array $posted): array
    {
        $overlay = function (string $field, $value) use ($stored, $locale): array {
            $texts = is_array($stored[$field] ?? null) ? $stored[$field] : [];
            $texts[$locale] = self::text((string) $value);

            return $texts;
        };

        switch ((string) ($stored['type'] ?? '')) {
            case 'hero':
                return [
                    'eyebrow' => $overlay('eyebrow', $posted['eyebrow'] ?? ''),
                    'heading' => $overlay('heading', $posted['heading'] ?? ''),
                    'subheading' => $overlay('subheading', $posted['subheading'] ?? ''),
                    'primary_label' => $overlay('primary_label', $posted['primary_label'] ?? ''),
                    'primary_url' => self::url((string) ($posted['primary_url'] ?? '')),
                    'secondary_label' => $overlay('secondary_label', $posted['secondary_label'] ?? ''),
                    'secondary_url' => self::url((string) ($posted['secondary_url'] ?? '')),
                ];
            case 'stats':
                return [
                    'heading' => $overlay('heading', $posted['heading'] ?? ''),
                    'items' => self::overlayItems(
                        is_array($stored['items'] ?? null) ? $stored['items'] : [],
                        is_array($posted['items'] ?? null) ? $posted['items'] : [],
                        $locale,
                        ['value' => 'plain', 'label' => 'text']
                    ),
                ];
            case 'cards':
                return [
                    'heading' => $overlay('heading', $posted['heading'] ?? ''),
                    'intro' => $overlay('intro', $posted['intro'] ?? ''),
                    'items' => self::overlayItems(
                        is_array($stored['items'] ?? null) ? $stored['items'] : [],
                        is_array($posted['items'] ?? null) ? $posted['items'] : [],
                        $locale,
                        ['tag' => 'text', 'heading' => 'text', 'text' => 'text']
                    ),
                ];
            case 'text':
                $body = is_array($stored['body'] ?? null) ? $stored['body'] : [];
                $body[$locale] = HtmlSanitizer::clean(mb_substr((string) ($posted['body'] ?? ''), 0, self::MAX_BODY));

                return ['heading' => $overlay('heading', $posted['heading'] ?? ''), 'body' => $body];
            case 'cta':
                return [
                    'heading' => $overlay('heading', $posted['heading'] ?? ''),
                    'text' => $overlay('text', $posted['text'] ?? ''),
                    'button_label' => $overlay('button_label', $posted['button_label'] ?? ''),
                    'button_url' => self::url((string) ($posted['button_url'] ?? '')),
                ];
            default:
                return $stored;
        }
    }

    /**
     * @param list<array<string, mixed>> $storedItems
     * @param list<array<string, mixed>> $postedItems
     * @param array<string, string> $fields field name => "plain" or "text" (per locale)
     * @return list<array<string, mixed>>
     */
    private static function overlayItems(array $storedItems, array $postedItems, string $locale, array $fields): array
    {
        $result = [];
        foreach (array_slice($postedItems, 0, self::MAX_ITEMS) as $i => $posted) {
            if (!is_array($posted)) {
                continue;
            }
            $storedItem = is_array($storedItems[$i] ?? null) ? $storedItems[$i] : [];
            $row = [];
            foreach ($fields as $field => $kind) {
                if ($kind === 'plain') {
                    $row[$field] = self::text((string) ($posted[$field] ?? ''));
                } else {
                    $texts = is_array($storedItem[$field] ?? null) ? $storedItem[$field] : [];
                    $texts[$locale] = self::text((string) ($posted[$field] ?? ''));
                    $row[$field] = $texts;
                }
            }
            $result[] = $row;
        }

        return $result;
    }

    /** A new, empty block of one type. */
    public static function newBlock(string $type): array
    {
        return ['id' => bin2hex(random_bytes(4)), 'type' => in_array($type, self::BLOCK_TYPES, true) ? $type : self::BLOCK_TYPES[0], 'enabled' => true] + self::emptyFields($type);
    }

    /**
     * The page in one language: every field resolved to that language (its
     * own text, else the site's default language), enabled blocks only,
     * and a block left out once resolving leaves it without what it needs
     * to show at all (see blockHasContent()).
     *
     * @return array{title: string, meta_description: string, blocks: list<array<string, mixed>>}
     */
    public function view(string $locale, string $default): array
    {
        $page = $this->get();
        $meta = (array) ($page['meta'][$locale] ?? $page['meta'][$default] ?? []);

        $blocks = [];
        foreach ($page['blocks'] as $block) {
            if (!($block['enabled'] ?? false)) {
                continue;
            }
            $resolved = self::resolveFields((string) $block['type'], $block, $locale, $default);
            if (self::blockHasContent((string) $block['type'], $resolved)) {
                $blocks[] = ['type' => $block['type']] + $resolved;
            }
        }

        return [
            'title' => (string) ($meta['title'] ?? ''),
            'meta_description' => (string) ($meta['meta_description'] ?? ''),
            'blocks' => $blocks,
        ];
    }

    private static function text(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));

        return mb_substr($value, 0, self::MAX_TEXT);
    }

    private static function url(string $value): string
    {
        $value = trim($value);

        return CustomMenu::validPath($value) ? $value : '';
    }

    /** Fields every block type shares: a text per locale, keyed by field name. */
    private static function localized(array $block, string $field): array
    {
        $texts = is_array($block[$field] ?? null) ? $block[$field] : [];
        $clean = [];
        foreach ($texts as $locale => $value) {
            $clean[(string) $locale] = self::text((string) $value);
        }

        return $clean;
    }

    /** @return array<string, mixed> the fields this block type stores, cleaned */
    private static function cleanFields(string $type, array $block): array
    {
        switch ($type) {
            case 'hero':
                return [
                    'eyebrow' => self::localized($block, 'eyebrow'),
                    'heading' => self::localized($block, 'heading'),
                    'subheading' => self::localized($block, 'subheading'),
                    'primary_label' => self::localized($block, 'primary_label'),
                    'primary_url' => self::url((string) ($block['primary_url'] ?? '')),
                    'secondary_label' => self::localized($block, 'secondary_label'),
                    'secondary_url' => self::url((string) ($block['secondary_url'] ?? '')),
                ];
            case 'stats':
                return [
                    'heading' => self::localized($block, 'heading'),
                    'items' => self::cleanItems($block, ['value' => 'plain', 'label' => 'text']),
                ];
            case 'cards':
                return [
                    'heading' => self::localized($block, 'heading'),
                    'intro' => self::localized($block, 'intro'),
                    'items' => self::cleanItems($block, ['tag' => 'text', 'heading' => 'text', 'text' => 'text']),
                ];
            case 'text':
                return [
                    'heading' => self::localized($block, 'heading'),
                    'body' => array_map(
                        fn ($value) => HtmlSanitizer::clean(mb_substr((string) $value, 0, self::MAX_BODY)),
                        is_array($block['body'] ?? null) ? $block['body'] : []
                    ),
                ];
            case 'cta':
                return [
                    'heading' => self::localized($block, 'heading'),
                    'text' => self::localized($block, 'text'),
                    'button_label' => self::localized($block, 'button_label'),
                    'button_url' => self::url((string) ($block['button_url'] ?? '')),
                ];
            default:
                return [];
        }
    }

    /**
     * @param array<string, string> $fields field name => "plain" (a short
     *        value with no language, e.g. "0 %") or "text" (per locale)
     * @return list<array<string, mixed>>
     */
    private static function cleanItems(array $block, array $fields): array
    {
        $items = is_array($block['items'] ?? null) ? array_slice($block['items'], 0, self::MAX_ITEMS) : [];
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($fields as $field => $kind) {
                $row[$field] = $kind === 'plain' ? self::text((string) ($item[$field] ?? '')) : self::localized($item, $field);
            }
            $clean[] = $row;
        }

        return $clean;
    }

    /** @return array<string, mixed> empty fields for a freshly added block of this type */
    private static function emptyFields(string $type): array
    {
        return self::cleanFields($type, []);
    }

    /** @return array<string, mixed> every field of this block, resolved to one language */
    private static function resolveFields(string $type, array $block, string $locale, string $default): array
    {
        $pick = fn (array $texts) => (string) ($texts[$locale] ?? $texts[$default] ?? reset($texts) ?: '');

        switch ($type) {
            case 'hero':
                return [
                    'eyebrow' => $pick($block['eyebrow'] ?? []),
                    'heading' => $pick($block['heading'] ?? []),
                    'subheading' => $pick($block['subheading'] ?? []),
                    'primary_label' => $pick($block['primary_label'] ?? []),
                    'primary_url' => (string) ($block['primary_url'] ?? ''),
                    'secondary_label' => $pick($block['secondary_label'] ?? []),
                    'secondary_url' => (string) ($block['secondary_url'] ?? ''),
                ];
            case 'stats':
                return [
                    'heading' => $pick($block['heading'] ?? []),
                    'items' => array_values(array_filter(array_map(fn (array $item) => [
                        'value' => (string) ($item['value'] ?? ''),
                        'label' => $pick($item['label'] ?? []),
                    ], is_array($block['items'] ?? null) ? $block['items'] : []), fn (array $i) => $i['value'] !== '' || $i['label'] !== '')),
                ];
            case 'cards':
                return [
                    'heading' => $pick($block['heading'] ?? []),
                    'intro' => $pick($block['intro'] ?? []),
                    'items' => array_values(array_filter(array_map(fn (array $item) => [
                        'tag' => $pick($item['tag'] ?? []),
                        'heading' => $pick($item['heading'] ?? []),
                        'text' => $pick($item['text'] ?? []),
                    ], is_array($block['items'] ?? null) ? $block['items'] : []), fn (array $i) => $i['heading'] !== '')),
                ];
            case 'text':
                return [
                    'heading' => $pick($block['heading'] ?? []),
                    'body' => $pick($block['body'] ?? []),
                ];
            case 'cta':
                return [
                    'heading' => $pick($block['heading'] ?? []),
                    'text' => $pick($block['text'] ?? []),
                    'button_label' => $pick($block['button_label'] ?? []),
                    'button_url' => (string) ($block['button_url'] ?? ''),
                ];
            default:
                return [];
        }
    }

    /** Whether a resolved block still has enough to show - an empty one is left out rather than shown blank. */
    private static function blockHasContent(string $type, array $resolved): bool
    {
        return match ($type) {
            'hero' => $resolved['heading'] !== '',
            'stats' => $resolved['items'] !== [],
            'cards' => $resolved['items'] !== [],
            'text' => $resolved['body'] !== '',
            'cta' => $resolved['heading'] !== '' && $resolved['button_label'] !== '' && $resolved['button_url'] !== '',
            default => false,
        };
    }
}
