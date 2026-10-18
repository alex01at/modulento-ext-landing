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
        ]);
    }
}
