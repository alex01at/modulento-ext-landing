<?php

declare(strict_types=1);

namespace Modulento\Landing;

use Modulento\Core\Support\Session;

/**
 * The editor of the one landing page this addon can build: one form holds
 * every block. The buttons next to one (move, remove) and "add" save the
 * form first, then do their step - the same pattern the core's own
 * AdminMenuController uses for the main menu's entries. Only the language
 * the administration is in is edited at a time, like everywhere else; a
 * block's other languages stay as they are until their turn.
 */
final class AdminController extends \Modulento\Core\Controller\Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $data = (new LandingPage($app->db))->get();

        $this->render('@landing/admin/edit.twig', [
            'locale' => $app->translator->locale(),
            'default_locale' => $app->locales->default(),
            'published' => $data['published'],
            'meta' => $data['meta'][$app->translator->locale()] ?? [],
            'blocks' => $data['blocks'],
            'block_types' => LandingPage::BLOCK_TYPES,
            'max_items' => LandingPage::MAX_ITEMS,
        ]);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $page = new LandingPage($app->db);
        $data = $page->get();
        $locale = $app->translator->locale();

        $blocks = $this->fromForm($data['blocks'], $locale);
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'add') {
            $type = (string) ($_POST['new_type'] ?? '');
            if (in_array($type, LandingPage::BLOCK_TYPES, true) && count($blocks) < LandingPage::MAX_BLOCKS) {
                $blocks[] = LandingPage::newBlock($type);
            }
        } elseif (preg_match('/^(up|down|delete):([a-f0-9]{8})$/', $action, $match) === 1) {
            $blocks = $this->step($blocks, $match[1], $match[2]);
        }

        $meta = $data['meta'];
        $meta[$locale] = [
            'title' => (string) ($_POST['meta']['title'] ?? ''),
            'meta_description' => (string) ($_POST['meta']['meta_description'] ?? ''),
        ];

        $page->save(isset($_POST['published']), $meta, $blocks);
        Session::flash('success', $this->trans($action === 'save' ? 'landing.admin.saved' : 'landing.admin.changed'));
        $this->redirect('/admin/landing');
    }

    /**
     * The whole list in the order dragged on the page - the core's own
     * admin theme already ships the drag-and-drop script for exactly this
     * markup (menu.js, loaded on every admin page, looks for
     * [data-menu-list] and posts here), so this addon needs none of its
     * own. Nothing else about any block is touched.
     */
    public function reorder(array $params): void
    {
        $page = new LandingPage($this->app->db);
        $data = $page->get();

        $byId = [];
        foreach ($data['blocks'] as $block) {
            $byId[$block['id']] = $block;
        }

        $order = is_array($_POST['order'] ?? null) ? $_POST['order'] : [];
        $blocks = [];
        foreach ($order as $id) {
            if (is_string($id) && isset($byId[$id])) {
                $blocks[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        foreach ($byId as $leftover) {
            $blocks[] = $leftover;
        }

        $page->save($data['published'], $data['meta'], $blocks);
        $this->json(['ok' => true]);
    }

    /**
     * The stored blocks with the current language's fields from the form on
     * them. A block the form does not know (added elsewhere meanwhile) is
     * kept as it is.
     *
     * @param list<array<string, mixed>> $stored
     * @return list<array<string, mixed>>
     */
    private function fromForm(array $stored, string $locale): array
    {
        $posted = is_array($_POST['blocks'] ?? null) ? $_POST['blocks'] : [];
        $blocks = [];

        foreach ($stored as $block) {
            $id = $block['id'];
            if (!isset($posted[$id]) || !is_array($posted[$id])) {
                $blocks[] = $block;
                continue;
            }

            $blocks[] = [
                'id' => $id,
                'type' => $block['type'],
                'enabled' => isset($posted[$id]['enabled']),
            ] + LandingPage::applyLocaleEdit($block, $locale, $posted[$id]);
        }

        return $blocks;
    }

    /**
     * Moves a block one place up or down, or removes it.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function step(array $blocks, string $direction, string $id): array
    {
        $index = null;
        foreach ($blocks as $i => $block) {
            if ($block['id'] === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            return $blocks;
        }

        if ($direction === 'delete') {
            array_splice($blocks, $index, 1);
        } else {
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($target >= 0 && $target < count($blocks)) {
                [$blocks[$index], $blocks[$target]] = [$blocks[$target], $blocks[$index]];
            }
        }

        return $blocks;
    }
}
