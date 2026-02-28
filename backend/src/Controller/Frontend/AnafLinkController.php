<?php

namespace App\Controller\Frontend;

use App\Repository\AnafTokenLinkRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AnafLinkController extends AbstractController
{
    #[Route(path: '/anaf/link/{linkToken}', name: 'anaf_link_page', methods: ['GET'])]
    public function __invoke(string $linkToken, AnafTokenLinkRepository $linkRepository): Response
    {
        $link = $linkRepository->findValidByToken($linkToken);

        if (!$link) {
            return new Response($this->renderPage(
                'Link invalid',
                'Acest link a expirat sau a fost deja utilizat.',
                null,
            ), Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']);
        }

        $connectUrl = '/api/connect/anaf?link=' . urlencode($linkToken);

        return new Response($this->renderPage(
            'Conectare ANAF',
            'Apasati butonul de mai jos pentru a va conecta la ANAF cu dispozitivul criptografic.',
            $connectUrl,
        ), Response::HTTP_OK, ['Content-Type' => 'text/html']);
    }

    private function renderPage(string $title, string $description, ?string $connectUrl): string
    {
        $button = '';
        if ($connectUrl) {
            $button = sprintf(
                '<a href="%s" style="display:inline-block;padding:12px 32px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;margin-top:16px;">Conecteaza la ANAF</a>',
                htmlspecialchars($connectUrl),
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} - Storno.ro</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f9fafb; color: #111827; }
        .card { max-width: 420px; width: 100%; padding: 48px 32px; background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #6b7280; font-size: 15px; line-height: 1.5; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{$title}</h1>
        <p>{$description}</p>
        {$button}
    </div>
</body>
</html>
HTML;
    }
}
