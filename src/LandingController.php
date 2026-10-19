<?php

declare(strict_types=1);

namespace Modulento\Landing;

final class LandingController extends \Modulento\Core\Controller\Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $page = new LandingPage($app->db);
        $data = $page->get();

        // Never while impersonating another account, exactly like the core's own edit_mode() -
        // whoever started impersonation keeps that account's permissions, not their own.
        $canEdit = $app->auth->can(Extension::PERMISSION) && $app->auth->impersonator() === null;
        if (!$data['published'] && !$canEdit) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $editMode = $canEdit && ($_GET['edit'] ?? '') === '1';
        $locale = $app->translator->locale();
        $default = $app->locales->default();
        $view = $editMode ? $page->editView($locale, $default) : $page->view($locale, $default);

        $rawById = [];
        if ($editMode) {
            foreach ($data['blocks'] as $raw) {
                $rawById[$raw['id']] = $raw;
            }
        }

        $this->render('@landing/landing.twig', [
            'page_title' => $view['title'],
            'page_meta_description' => $view['meta_description'],
            'blocks' => $view['blocks'],
            'can_edit' => $canEdit,
            'edit_mode' => $editMode,
            'published' => $data['published'],
            'raw_blocks' => $rawById,
            'block_types' => LandingPage::BLOCK_TYPES,
            'max_items' => LandingPage::MAX_ITEMS,
            // So the browser fetches the stylesheet below again exactly when a block's own
            // background, font or text colour actually changed - never otherwise.
            'blocks_css_version' => substr(sha1(json_encode($data['blocks'], JSON_THROW_ON_ERROR)), 0, 8),
        ]);
    }

    /**
     * The background, font and text colour a block's own settings ask for
     * - never an inline `style=""` (blocked by the site's own Content-
     * Security-Policy) - as its own small stylesheet, public, cacheable,
     * independent of the core's design stylesheet (DesignController).
     */
    public function css(array $params): void
    {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo (new LandingPage($this->app->db))->blockStylesCss();
    }
}
