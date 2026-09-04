<?php

namespace App\Service\Spv;

use App\Enum\SpvDocumentCategory;
use App\Enum\SpvDocumentSeverity;

/**
 * Maps the free-text `tip` of an ANAF SPV message onto a category and a
 * severity. The label list comes from the SPV portal filter (2026-09) and
 * matching is by keyword so unseen variants still land in a sensible bucket.
 */
final class SpvDocumentClassifier
{
    /**
     * @return array{category: SpvDocumentCategory, severity: SpvDocumentSeverity}
     */
    public function classify(string $tip): array
    {
        $t = $this->normalize($tip);

        $has = static fn (string ...$needles): bool => array_reduce(
            $needles,
            static fn (bool $carry, string $n) => $carry || str_contains($t, $n),
            false,
        );

        // Order matters: the most specific / most urgent buckets first.
        if ($has('somati')) {
            return $this->r(SpvDocumentCategory::SOMATIE, SpvDocumentSeverity::CRITICAL);
        }
        if ($has('analiza de risc')) {
            return $this->r(SpvDocumentCategory::ANALIZA_RISC, SpvDocumentSeverity::CRITICAL);
        }
        if ($has('recipisa')) {
            $cat = $has('tezaur') ? SpvDocumentCategory::TEZAUR : SpvDocumentCategory::RECIPISA;
            return $this->r($cat, SpvDocumentSeverity::LOW);
        }
        if ($has('tezaur')) {
            return $this->r(SpvDocumentCategory::TEZAUR, SpvDocumentSeverity::LOW);
        }
        if ($has('decizie')) {
            $critical = $has('inactivare', 'anulare', 'radiere', 'respingere', 'din oficiu');
            return $this->r(SpvDocumentCategory::DECIZIE, $critical ? SpvDocumentSeverity::CRITICAL : SpvDocumentSeverity::HIGH);
        }
        if ($t === 'adrese' || $has('adresa ')) {
            return $this->r(SpvDocumentCategory::ADRESA, SpvDocumentSeverity::HIGH);
        }
        if ($has('notificare', 'notif.', 'notificarea', 'instiintare', 'invitatie', 'informare', 'solicitare intalnire')) {
            return $this->r(SpvDocumentCategory::NOTIFICARE, SpvDocumentSeverity::HIGH);
        }
        if ($has('raspuns')) {
            return $this->r(SpvDocumentCategory::RASPUNS, SpvDocumentSeverity::NORMAL);
        }
        if ($has('certificat', 'cazier', 'adeverint')) {
            return $this->r(SpvDocumentCategory::CERTIFICAT, SpvDocumentSeverity::NORMAL);
        }
        if ($has('extras de cont')) {
            return $this->r(SpvDocumentCategory::EXTRAS_CONT, SpvDocumentSeverity::NORMAL);
        }
        if ($t === 'plata' || $has('plata ')) {
            return $this->r(SpvDocumentCategory::PLATA, SpvDocumentSeverity::NORMAL);
        }
        if ($has('ajutor de stat')) {
            return $this->r(SpvDocumentCategory::AJUTOR_STAT, SpvDocumentSeverity::NORMAL);
        }
        if ($has('facturi arhiva')) {
            return $this->r(SpvDocumentCategory::FACTURI_ARHIVA, SpvDocumentSeverity::LOW);
        }
        if ($has('declaratie', 'decont', 'm1ss')) {
            return $this->r(SpvDocumentCategory::DECLARATIE, SpvDocumentSeverity::LOW);
        }
        if ($has('fiducie', 'registru')) {
            return $this->r(SpvDocumentCategory::REGISTRU, SpvDocumentSeverity::NORMAL);
        }

        return $this->r(SpvDocumentCategory::ALTELE, SpvDocumentSeverity::NORMAL);
    }

    /** Lowercase, strip diacritics, collapse whitespace. */
    public function normalize(string $tip): string
    {
        $t = mb_strtolower(trim($tip));
        $t = strtr($t, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        return preg_replace('/\s+/', ' ', $t) ?? $t;
    }

    /**
     * @return array{category: SpvDocumentCategory, severity: SpvDocumentSeverity}
     */
    private function r(SpvDocumentCategory $category, SpvDocumentSeverity $severity): array
    {
        return ['category' => $category, 'severity' => $severity];
    }
}
