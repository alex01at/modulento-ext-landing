<?php

declare(strict_types=1);

namespace Modulento\Landing;

use Modulento\Core\Content\CustomMenu;
use Modulento\Core\Content\HomeLayout;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Design;
use Modulento\Core\Support\HtmlSanitizer;
use PDO;

/**
 * The one landing page this addon can build, as an ordered list of typed
 * blocks - the same idea the core's own CustomMenu and HomeLayout already
 * use for an ordered list of entries with per-language texts, just kept in
 * this addon's own table. Every block has an id (a short random hex string,
 * stable across edits), a type, "enabled", and whatever fields SCHEMA names
 * for that type.
 *
 * SCHEMA drives every operation below (cleaning, resolving to one language,
 * the single-field edit a click on the live page sends) instead of a
 * switch per type repeated five times, so a new block type is one entry
 * here plus one Twig partial - nothing else has to change by hand.
 * A field's kind is one of:
 *   "text" - a short value, per language (the stored shape is {locale: value})
 *   "body" - a longer value, per language, cleaned to a fixed set of HTML
 *            elements (HtmlSanitizer, the same one the core's content pages use)
 *   "raw"  - a short value with no language of its own (e.g. "0 %")
 *   "url"  - a path of this site or a secure address elsewhere, no language
 * "items" (where present) is itself a schema of the same kind, applied to
 * each of up to MAX_ITEMS repeated rows.
 */
final class LandingPage
{
    private const SCHEMA = [
        'hero' => [
            'eyebrow' => 'text', 'heading' => 'text', 'heading_accent' => 'text', 'subheading' => 'text',
            'primary_label' => 'text', 'primary_url' => 'url',
            'secondary_label' => 'text', 'secondary_url' => 'url',
        ],
        'stats' => [
            'heading' => 'text',
            'items' => ['value' => 'raw', 'label' => 'text'],
        ],
        'cards' => [
            'heading' => 'text', 'intro' => 'text',
            'items' => ['tag' => 'text', 'heading' => 'text', 'text' => 'text'],
        ],
        'text' => [
            'heading' => 'text', 'body' => 'body',
        ],
        'cta' => [
            'heading' => 'text', 'text' => 'text', 'button_label' => 'text', 'button_url' => 'url',
            'secondary_label' => 'text', 'secondary_url' => 'url',
        ],
    ];

    public const MAX_BLOCKS = 20;
    public const MAX_ITEMS = 6;
    private const MAX_TEXT = 200;
    private const MAX_BODY = 20000;
    private const ROW_ID = 1;

    /** @var list<string> */
    public const BLOCK_TYPES = ['hero', 'stats', 'cards', 'text', 'cta'];

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
     * Stores the page exactly as given, after cleaning every field - a
     * block of a type SCHEMA no longer knows (should not happen) is dropped
     * rather than kept half-understood.
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
            if (!isset(self::SCHEMA[$type]) || !preg_match('/^[a-f0-9]{8}$/', $id)) {
                continue;
            }
            $cleanBlocks[] = [
                'id' => $id, 'type' => $type, 'enabled' => (bool) ($block['enabled'] ?? false),
                'settings' => self::cleanSettings($block['settings'] ?? []),
            ] + self::cleanFields($type, $block);
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

    /** A new, empty block of one type. */
    public static function newBlock(string $type): array
    {
        $type = isset(self::SCHEMA[$type]) ? $type : self::BLOCK_TYPES[0];

        return ['id' => bin2hex(random_bytes(4)), 'type' => $type, 'enabled' => true, 'settings' => []] + self::cleanFields($type, []);
    }

    /**
     * The stored block with one language's fields replaced by what the
     * form posted for it - every other language's text, and anything not
     * tied to a language (a URL), stays as it was. What the controller
     * calls for every block the plain form named, before handing the
     * whole list to save().
     *
     * @param array<string, mixed> $stored the block as currently stored (id/type/enabled plus its fields)
     * @param array<string, mixed> $posted this block's $_POST entry, one language's values
     * @return array<string, mixed> the fields only (no id/type/enabled)
     */
    public static function applyLocaleEdit(array $stored, string $locale, array $posted): array
    {
        $schema = self::SCHEMA[(string) ($stored['type'] ?? '')] ?? [];
        $result = [];

        foreach ($schema as $field => $kind) {
            if ($field === 'items') {
                continue;
            }
            $result[$field] = self::overlayOne($kind, $stored[$field] ?? null, $locale, $posted[$field] ?? '');
        }

        if (is_array($schema['items'] ?? null)) {
            $storedItems = is_array($stored['items'] ?? null) ? $stored['items'] : [];
            $postedItems = is_array($posted['items'] ?? null) ? $posted['items'] : [];
            $result['items'] = [];
            foreach (array_slice($postedItems, 0, self::MAX_ITEMS) as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $storedItem = is_array($storedItems[$i] ?? null) ? $storedItems[$i] : [];
                $row = [];
                foreach ($schema['items'] as $field => $kind) {
                    $row[$field] = self::overlayOne($kind, $storedItem[$field] ?? null, $locale, $item[$field] ?? '');
                }
                $result['items'][] = $row;
            }
        }

        return $result;
    }

    /** One field's stored value with $locale's text replaced by $posted (a "text"/"body"), or just cleaned ("raw"/"url"). */
    private static function overlayOne(string $kind, $stored, string $locale, $posted)
    {
        if ($kind === 'text' || $kind === 'body') {
            $texts = is_array($stored) ? $stored : [];
            $texts[$locale] = self::cleanValue($kind, (string) $posted);

            return $texts;
        }

        return self::cleanValue($kind, (string) $posted);
    }

    /**
     * One field of one block, saved on its own from a click on the live
     * page - the field a block's own Twig partial names with data-field,
     * e.g. "heading" or, inside a repeated row, "items.2.label". Returns
     * the cleaned value to show in its place, or null when the block or
     * the field does not exist (nothing is saved then).
     */
    public function setField(string $blockId, string $locale, string $field, string $value): ?string
    {
        $page = $this->get();
        $index = null;
        foreach ($page['blocks'] as $i => $block) {
            if ($block['id'] === $blockId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return null;
        }

        $block = $page['blocks'][$index];
        $schema = self::SCHEMA[(string) ($block['type'] ?? '')] ?? [];

        if (preg_match('/^items\.(\d+)\.([a-z_]+)$/', $field, $match) === 1) {
            $itemIndex = (int) $match[1];
            $subfield = $match[2];
            $itemSchema = $schema['items'] ?? null;
            if (!is_array($itemSchema) || !isset($itemSchema[$subfield]) || $itemIndex < 0 || $itemIndex >= self::MAX_ITEMS) {
                return null;
            }

            $items = is_array($block['items'] ?? null) ? $block['items'] : [];
            while (count($items) <= $itemIndex) {
                $items[] = [];
            }
            $clean = self::cleanValue($itemSchema[$subfield], $value);
            $items[$itemIndex][$subfield] = self::overlayOne($itemSchema[$subfield], $items[$itemIndex][$subfield] ?? null, $locale, $value);
            $block['items'] = $items;
        } else {
            if (!isset($schema[$field]) || !is_string($schema[$field])) {
                return null;
            }
            $clean = self::cleanValue($schema[$field], $value);
            $block[$field] = self::overlayOne($schema[$field], $block[$field] ?? null, $locale, $value);
        }

        $page['blocks'][$index] = $block;
        $this->save($page['published'], $page['meta'], $page['blocks']);

        return $clean;
    }

    /**
     * The page in one language: every field resolved to that language (its
     * own text, else the site's default language), enabled blocks only,
     * and a block left out once resolving leaves it without what it needs
     * to show at all (see blockHasContent()). What the public page renders.
     *
     * @return array{title: string, meta_description: string, blocks: list<array<string, mixed>>}
     */
    public function view(string $locale, string $default): array
    {
        return $this->resolvedPage($locale, $default, forEditing: false);
    }

    /**
     * Every block, enabled or not, every item slot up to MAX_ITEMS whether
     * filled in or not - what the page shows an administrator editing it
     * in place, so an empty slot is still there to click into.
     *
     * @return array{title: string, meta_description: string, blocks: list<array<string, mixed>>}
     */
    public function editView(string $locale, string $default): array
    {
        return $this->resolvedPage($locale, $default, forEditing: true);
    }

    /** @return array{title: string, meta_description: string, blocks: list<array<string, mixed>>} */
    private function resolvedPage(string $locale, string $default, bool $forEditing): array
    {
        $page = $this->get();
        $meta = (array) ($page['meta'][$locale] ?? $page['meta'][$default] ?? []);

        $blocks = [];
        foreach ($page['blocks'] as $block) {
            if (!$forEditing && !($block['enabled'] ?? false)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $resolved = self::resolveFields($type, $block, $locale, $default, $forEditing);
            if ($forEditing || self::blockHasContent($type, $resolved)) {
                $blocks[] = ['id' => (string) ($block['id'] ?? ''), 'type' => $type, 'enabled' => (bool) ($block['enabled'] ?? false)] + $resolved;
            }
        }

        return [
            'title' => (string) ($meta['title'] ?? ''),
            'meta_description' => (string) ($meta['meta_description'] ?? ''),
            'blocks' => $blocks,
        ];
    }

    /** @return array<string, mixed> every field of this block, resolved to one language */
    private static function resolveFields(string $type, array $block, string $locale, string $default, bool $all): array
    {
        $schema = self::SCHEMA[$type] ?? [];
        $pick = static function ($raw) use ($locale, $default): string {
            $texts = is_array($raw) ? $raw : [];

            return (string) ($texts[$locale] ?? $texts[$default] ?? ($texts !== [] ? reset($texts) : ''));
        };

        $result = [];
        foreach ($schema as $field => $kind) {
            if ($field === 'items') {
                continue;
            }
            $result[$field] = $kind === 'text' || $kind === 'body' ? $pick($block[$field] ?? null) : (string) ($block[$field] ?? '');
        }

        if (is_array($schema['items'] ?? null)) {
            $rawItems = is_array($block['items'] ?? null) ? $block['items'] : [];
            $slots = $all ? self::MAX_ITEMS : count($rawItems);
            $items = [];
            for ($i = 0; $i < $slots; $i++) {
                $item = is_array($rawItems[$i] ?? null) ? $rawItems[$i] : [];
                $row = ['index' => $i];
                foreach ($schema['items'] as $field => $kind) {
                    $row[$field] = $kind === 'text' || $kind === 'body' ? $pick($item[$field] ?? null) : (string) ($item[$field] ?? '');
                }
                if ($all || self::itemHasContent($row, array_keys($schema['items']))) {
                    $items[] = $row;
                }
            }
            $result['items'] = $items;
        }

        return $result;
    }

    /** @param list<string> $fields */
    private static function itemHasContent(array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($row[$field] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /** Whether a resolved block still has enough to show - an empty one is left out rather than shown blank. */
    private static function blockHasContent(string $type, array $resolved): bool
    {
        return match ($type) {
            'hero' => $resolved['heading'] !== '',
            'stats', 'cards' => $resolved['items'] !== [],
            'text' => $resolved['body'] !== '',
            'cta' => $resolved['heading'] !== '' && $resolved['button_label'] !== '' && $resolved['button_url'] !== '',
            default => false,
        };
    }

    /** @return array<string, mixed> the fields SCHEMA names for this type, cleaned */
    private static function cleanFields(string $type, array $block): array
    {
        $schema = self::SCHEMA[$type] ?? [];
        $result = [];

        foreach ($schema as $field => $kind) {
            if ($field === 'items') {
                continue;
            }
            $result[$field] = $kind === 'text' || $kind === 'body'
                ? self::cleanLocalizedMap($block[$field] ?? null, $kind)
                : self::cleanValue($kind, $block[$field] ?? '');
        }

        if (is_array($schema['items'] ?? null)) {
            $items = is_array($block['items'] ?? null) ? array_slice($block['items'], 0, self::MAX_ITEMS) : [];
            $result['items'] = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $row = [];
                foreach ($schema['items'] as $field => $kind) {
                    $row[$field] = $kind === 'text' || $kind === 'body'
                        ? self::cleanLocalizedMap($item[$field] ?? null, $kind)
                        : self::cleanValue($kind, $item[$field] ?? '');
                }
                $result['items'][] = $row;
            }
        }

        return $result;
    }

    /**
     * A block's own look, the same idea the core's own HomeLayout already
     * offers per block (background colour/gradient/picture), plus a font
     * and a text colour of its own - no language of its own, unlike every
     * field above, so the plain form always sends the whole thing and it
     * is never merged with what was there before (see AdminController).
     *
     * @return array<string, string>
     */
    private static function cleanSettings($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $settings = [];

        $type = (string) ($raw['background_type'] ?? '');
        if (in_array($type, ['color', 'gradient', 'image'], true)) {
            $settings['background_type'] = $type;
            if ($type !== 'image') {
                $color = strtolower(trim((string) ($raw['background_color'] ?? '')));
                if (HomeLayout::validColour($color)) {
                    $settings['background_color'] = $color;
                }
            }
            if ($type === 'gradient') {
                $color2 = strtolower(trim((string) ($raw['background_color2'] ?? '')));
                if (HomeLayout::validColour($color2)) {
                    $settings['background_color2'] = $color2;
                }
            }
            if ($type === 'image') {
                $settings['background_media'] = HomeLayout::cleanMedia((string) ($raw['background_media'] ?? ''));
            }
        }

        $font = (string) ($raw['font'] ?? '');
        if (isset(Design::FONTS[$font])) {
            $settings['font'] = $font;
        }

        $textColor = strtolower(trim((string) ($raw['text_color'] ?? '')));
        if (HomeLayout::validColour($textColor)) {
            $settings['text_color'] = $textColor;
        }

        return $settings;
    }

    /**
     * CSS rules for every block that has a background, font or text colour
     * of its own, one rule per block targeted by `[data-landing-block="{id}"]`
     * - never an inline `style=""` (the site's Content-Security-Policy
     * allows no `style-src 'unsafe-inline'`, only same-origin stylesheets).
     * Served as its own small stylesheet (LandingController::css()), since
     * this addon's own content is no business of the core's design
     * stylesheet (DesignController).
     */
    public function blockStylesCss(): string
    {
        $css = '';
        foreach ($this->get()['blocks'] as $block) {
            $style = self::blockStyle(is_array($block['settings'] ?? null) ? $block['settings'] : []);
            if ($style !== '') {
                $css .= '[data-landing-block="' . $block['id'] . '"] {' . "\n    " . $style . "\n}\n";
            }
        }

        return $css;
    }

    /** A block's background, font and text colour, as ready "property: value;" lines - empty for none of them. */
    private static function blockStyle(array $settings): string
    {
        $rules = [];

        $background = self::backgroundStyle($settings);
        if ($background !== '') {
            $rules[] = $background;
        }

        $font = (string) ($settings['font'] ?? '');
        if (isset(Design::FONTS[$font])) {
            $rules[] = 'font-family: ' . Design::FONTS[$font] . ';';
        }

        $textColor = (string) ($settings['text_color'] ?? '');
        if (HomeLayout::validColour($textColor)) {
            $rules[] = 'color: ' . $textColor . ';';
        }

        return implode(' ', $rules);
    }

    /** The background a block's own settings ask for - the same rule HomeLayout's own blocks use. */
    private static function backgroundStyle(array $settings): string
    {
        $type = $settings['background_type'] ?? null;
        $colour = fn (string $key) => is_string($settings[$key] ?? null) && HomeLayout::validColour($settings[$key]) ? $settings[$key] : null;

        return match (true) {
            $type === 'color' && $colour('background_color') !== null => 'background: ' . $colour('background_color') . ';',
            $type === 'gradient' && $colour('background_color') !== null && $colour('background_color2') !== null
                => 'background: linear-gradient(135deg, ' . $colour('background_color') . ', ' . $colour('background_color2') . ');',
            $type === 'image' && HomeLayout::cleanMedia((string) ($settings['background_media'] ?? '')) !== ''
                => 'background-image: url(' . HomeLayout::cleanMedia((string) $settings['background_media']) . '); background-size: cover; background-position: center;',
            default => '',
        };
    }

    /** @return array<string, string> */
    private static function cleanLocalizedMap($raw, string $kind): array
    {
        $texts = is_array($raw) ? $raw : [];
        $clean = [];
        foreach ($texts as $locale => $value) {
            $clean[(string) $locale] = self::cleanValue($kind, $value);
        }

        return $clean;
    }

    private static function cleanValue(string $kind, $value): string
    {
        return match ($kind) {
            'raw', 'text' => self::text((string) $value),
            'url' => self::url((string) $value),
            'body' => HtmlSanitizer::clean(mb_substr((string) $value, 0, self::MAX_BODY)),
            default => '',
        };
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
}
