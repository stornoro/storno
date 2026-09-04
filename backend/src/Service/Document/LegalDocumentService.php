<?php

declare(strict_types=1);

namespace App\Service\Document;

use Knp\Snappy\Pdf;
use Twig\Environment;

/**
 * Renders standard legal documents (Romanian) from structured fields: the rental
 * termination agreement (convenție de încetare) and the locator's sworn statement
 * used as the C168 attachment. Templates live in templates/documents/legal/.
 * Public: no account, nothing stored; the MCP tools and the landing tools use it.
 */
final class LegalDocumentService
{
    /** @var array<string, array{title: string, template: string, required: list<string>, defaults: array<string, mixed>}> */
    public const TYPES = [
        'conventie_incetare_inchiriere' => [
            'title' => 'CONVENȚIE DE ÎNCETARE A CONTRACTULUI DE ÎNCHIRIERE',
            'template' => 'documents/legal/conventie_incetare_inchiriere.html.twig',
            'required' => ['locator.nume', 'locator.adresa', 'locatar.nume', 'locatar.adresa', 'contract.numar', 'contract.data', 'contract.adresa_imobil', 'data_incetare'],
            'defaults' => ['termen_utilitati_zile' => 5, 'garantie' => ['suma' => null, 'valuta' => 'EUR', 'termen_zile' => 15]],
        ],
        'declaratie_incetare_contract' => [
            'title' => 'DECLARAȚIE PE PROPRIA RĂSPUNDERE',
            'template' => 'documents/legal/declaratie_incetare_contract.html.twig',
            'required' => ['locator.nume', 'locator.adresa', 'locatar.nume', 'contract.numar', 'contract.data', 'contract.adresa_imobil', 'contract.data_inceput', 'contract.data_sfarsit', 'data_incetare'],
            'defaults' => ['motiv' => 'la termen'],
        ],
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly Pdf $snappy,
    ) {
    }

    /** @return list<array{type: string, title: string, required: list<string>}> */
    public function catalog(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $def) {
            $out[] = ['type' => $type, 'title' => $def['title'], 'required' => $def['required']];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{html: string, pdf: string, title: string}
     * @throws \InvalidArgumentException listing the missing fields
     */
    public function render(string $type, array $fields): array
    {
        $def = self::TYPES[$type] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException(sprintf('Tip de document necunoscut: %s. Disponibile: %s', $type, implode(', ', array_keys(self::TYPES))));
        }
        $missing = [];
        foreach ($def['required'] as $path) {
            if ($this->get($fields, $path) === null) {
                $missing[] = $path;
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException('Campuri obligatorii lipsa: ' . implode(', ', $missing));
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Bucharest')))->format('d.m.Y');
        // Twig runs with strict variables: every optional key the templates touch exists, null when absent.
        $party = ['nume' => null, 'adresa' => null, 'ci_serie' => null, 'ci_numar' => null, 'cnp' => null];
        $shape = [
            'locator' => $party,
            'locatar' => $party,
            'contract' => ['numar' => null, 'data' => null, 'adresa_imobil' => null, 'numar_inregistrare_anaf' => null, 'data_inregistrare_anaf' => null, 'chirie' => null, 'valuta' => 'EUR', 'data_inceput' => null, 'data_sfarsit' => null],
            'garantie' => ['suma' => null, 'valuta' => 'EUR', 'termen_zile' => 15],
            'data_incetare' => null, 'motiv' => null, 'motiv_detalii' => null, 'organ_fiscal' => null, 'termen_utilitati_zile' => 5,
        ];
        $context = array_replace_recursive($shape, $def['defaults'], $fields, [
            'title' => $def['title'],
            'data_conventie' => $fields['data_conventie'] ?? $today,
            'data_declaratie' => $fields['data_declaratie'] ?? $today,
        ]);

        $html = $this->twig->render($def['template'], $context);
        $pdf = $this->snappy->getOutputFromHtml($html, [
            'encoding' => 'UTF-8',
            'page-size' => 'A4',
            'margin-top' => '20mm',
            'margin-bottom' => '18mm',
            'margin-left' => '20mm',
            'margin-right' => '20mm',
            'disable-javascript' => true,
            'no-images' => true,
            'disable-local-file-access' => true,
        ]);

        return ['html' => $html, 'pdf' => $pdf, 'title' => $def['title']];
    }

    /** @param array<string, mixed> $data */
    private function get(array $data, string $path): mixed
    {
        $cur = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }

        return is_string($cur) && trim($cur) === '' ? null : $cur;
    }
}
