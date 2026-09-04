<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Spv\SpvDocumentSummarizer;
use PHPUnit\Framework\TestCase;

final class SpvDocumentSummarizerTest extends TestCase
{
    private SpvDocumentSummarizer $s;

    protected function setUp(): void
    {
        $this->s = new SpvDocumentSummarizer();
    }

    public function testRecipisaForSaft(): void
    {
        $out = $this->s->summarize('RECIPISA', 'recipisa pentru CIF 12345678, tip D406, numar_inregistrare INTERNT-100000123-2026/31-08-2026, perioada raportare 7.2026');

        self::assertStringContainsString('Confirmare de depunere (recipisă)', $out);
        self::assertStringContainsString('SAF-T (D406)', $out);
        self::assertStringContainsString('luna iulie 2026', $out);
        self::assertStringContainsString('INTERNT-100000123-2026 în 31.08.2026', $out);
        self::assertStringContainsString('acceptată', $out);
    }

    public function testEnglishRecipisaAndDecision(): void
    {
        $out = $this->s->summarize('RECIPISA', 'recipisa pentru CIF 12345678, tip D406, numar_inregistrare INTERNT-100000123-2026/31-08-2026, perioada raportare 7.2026', null, 'en');
        self::assertStringContainsString('Filing receipt', $out);
        self::assertStringContainsString('SAF-T standard audit file (D406)', $out);
        self::assertStringContainsString('July 2026', $out);
        self::assertStringContainsString('registered at ANAF under number INTERNT-100000123-2026 on 31.08.2026', $out);
        self::assertStringContainsString('tax-inactive', $this->s->summarize('Decizie inactivare', null, null, 'en'));
        self::assertStringContainsString('Payment summons', $this->s->summarize('SOMATII', null, null, 'en'));
    }

    public function testRecipisaWithErrorsWarns(): void
    {
        $out = $this->s->summarize('RECIPISA', 'recipisa pentru CIF 12345678, tip D300, numar_inregistrare INTERNT-100000124-2026/25-08-2026, perioada raportare 7.2026 - contine erori');
        self::assertStringContainsString('Decontul de TVA (D300)', $out);
        self::assertStringContainsString('NU este considerată validă', $out);
    }

    public function testSomatieAndPoprire(): void
    {
        self::assertStringContainsString('Somație de plată', $this->s->summarize('SOMATII', 'Somatie nr. 123 din 01.09.2026'));
        self::assertStringContainsString('Poprire', $this->s->summarize('ADRESE', 'Adresa de infiintare a popririi asupra disponibilitatilor banesti'));
    }

    public function testDecisions(): void
    {
        self::assertStringContainsString('inactiv fiscal', $this->s->summarize('Decizie inactivare', null));
        self::assertStringContainsString('anulare a codului de TVA', $this->s->summarize('Decizie anulare TVA', null));
        self::assertStringContainsString('reactivare', $this->s->summarize('Decizie reactivare inactivi', null));
    }

    public function testReportsAndFallback(): void
    {
        self::assertStringContainsString('Vectorul fiscal', $this->s->summarize('VECTOR FISCAL', null));
        self::assertStringContainsString('Răspunsul ANAF la o solicitare', $this->s->summarize('RASPUNS SESIZARE FORMULAR UNIC DE CONTACT', 'Raspuns la formularul unic de contact'));
        self::assertStringContainsString('Deschide PDF-ul', $this->s->summarize('CEVA NOU', 'text necunoscut'));
    }
}
