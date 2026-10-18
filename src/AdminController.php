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
    /**
     * core.home_redirect is the core's own setting (HomeController) for
     * making any internal path the start page, without the core knowing
     * this addon exists - this is simply the one place that offers a box
     * for it, and only ever writes or clears its own path there.
     */
    private const HOME_PATH = '/landing';

    public function index(array $params): void
    {
        $app = $this->app;
        $data = (new LandingPage($app->db))->get();

        $this->render('@landing/admin/edit.twig', [
            'locale' => $app->translator->locale(),
            'default_locale' => $app->locales->default(),
            'published' => $data['published'],
            'is_home' => $app->settings->get('core.home_redirect') === self::HOME_PATH,
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
        } elseif (preg_match('/^(up|down|delete|toggle):([a-f0-9]{8})$/', $action, $match) === 1) {
            $blocks = $this->step($blocks, $match[1], $match[2]);
        }

        $meta = $data['meta'];
        $meta[$locale] = [
            'title' => (string) ($_POST['meta']['title'] ?? ''),
            'meta_description' => (string) ($_POST['meta']['meta_description'] ?? ''),
        ];

        $isHome = isset($_POST['is_home']);
        // Used as the start page implies published - an unpublished start page would 404 the whole site.
        $page->save(isset($_POST['published']) || $isHome, $meta, $blocks);

        if ($isHome) {
            $app->settings->set('core.home_redirect', self::HOME_PATH);
        } elseif ($app->settings->get('core.home_redirect') === self::HOME_PATH) {
            $app->settings->set('core.home_redirect', '');
        }

        Session::flash('success', $this->trans($action === 'save' ? 'landing.admin.saved' : 'landing.admin.changed'));
        $this->redirect($this->safeReturn('/admin/landing'));
    }

    /**
     * The whole list in the order dragged on the admin page's own list
     * (the core's menu.js, loaded on every administration page, posts
     * here as form data) - see order() below for the on-page editor's
     * own drag, which posts JSON instead.
     */
    public function reorder(array $params): void
    {
        $order = is_array($_POST['order'] ?? null) ? $_POST['order'] : [];
        $this->reorderTo($order);
        $this->json(['ok' => true]);
    }

    /**
     * The whole list in the order dragged on the live page itself (the
     * core's inline-edit.js, loaded only while a block's handle - ⠿ - is
     * on the page, posts a JSON body here).
     */
    public function order(array $params): void
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        $ids = is_array($data) && is_array($data['order'] ?? null) ? $data['order'] : null;
        if ($ids === null || array_filter($ids, fn ($id) => !is_string($id) || preg_match('/^[a-f0-9]{8}$/', $id) !== 1) !== []) {
            $this->json(['ok' => false], 422);
            return;
        }

        $this->reorderTo($ids);
        $this->json(['ok' => true]);
    }

    /**
     * One field of one block, saved on its own from a click on the live
     * page - e.g. "heading", or, inside a repeated row, "items.2.label".
     * The cleaned value comes back, so the page can show exactly what was
     * actually kept.
     */
    public function field(array $params): void
    {
        $app = $this->app;
        $data = json_decode((string) file_get_contents('php://input'), true);
        $locale = is_array($data) && is_string($data['locale'] ?? null) ? $data['locale'] : '';
        if (!is_array($data) || !$app->locales->isEnabled($locale) || !is_string($data['block'] ?? null)
            || !is_string($data['field'] ?? null) || !is_string($data['value'] ?? null)) {
            $this->json(['ok' => false], 422);
            return;
        }

        $clean = (new LandingPage($app->db))->setField($data['block'], $locale, $data['field'], $data['value']);
        if ($clean === null) {
            $this->json(['ok' => false], 422);
            return;
        }
        $this->json(['ok' => true, 'value' => $clean]);
    }

    /** @param list<mixed> $order block ids in the order they should end up in */
    private function reorderTo(array $order): void
    {
        $page = new LandingPage($this->app->db);
        $data = $page->get();

        $byId = [];
        foreach ($data['blocks'] as $block) {
            $byId[$block['id']] = $block;
        }

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
     * Moves a block one place up or down, removes it, or (the live page's
     * own quick hide/show button) flips "enabled" without opening its
     * settings.
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

        if ($direction === 'toggle') {
            $blocks[$index]['enabled'] = !$blocks[$index]['enabled'];

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
