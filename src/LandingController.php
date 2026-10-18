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

        if (!$data['published']) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $view = $page->view($app->translator->locale(), $app->locales->default());

        $this->render('@landing/landing.twig', [
            'page_title' => $view['title'],
            'page_meta_description' => $view['meta_description'],
            'blocks' => $view['blocks'],
        ]);
    }
}
