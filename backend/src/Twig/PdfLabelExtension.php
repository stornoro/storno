<?php

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PdfLabelExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pdf_label', [$this, 'pdfLabel']),
            new TwigFunction('pdf_visible', [$this, 'pdfVisible']),
        ];
    }

    /**
     * Returns custom label text or falls back to translation.
     */
    public function pdfLabel(string $key, ?array $overrides, string $locale = 'ro', ?string $transKey = null): string
    {
        if ($overrides && isset($overrides[$key]['text']) && $overrides[$key]['text'] !== null && $overrides[$key]['text'] !== '') {
            return $overrides[$key]['text'];
        }

        return $this->translator->trans($transKey ?? $key, [], 'pdf', $locale);
    }

    /**
     * Returns whether a label/field should be visible.
     */
    public function pdfVisible(string $key, ?array $overrides): bool
    {
        if ($overrides && isset($overrides[$key]['visible'])) {
            return (bool) $overrides[$key]['visible'];
        }

        return true;
    }
}
