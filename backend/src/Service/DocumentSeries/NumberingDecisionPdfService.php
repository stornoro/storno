<?php

namespace App\Service\DocumentSeries;

use App\Entity\Company;
use App\Repository\PdfTemplateConfigRepository;
use Knp\Snappy\Pdf;
use Twig\Environment;

/**
 * Renders the numbering decision (decizia de numerotare) as an A4 PDF with
 * the same hardened wkhtmltopdf options as the other document PDFs.
 */
class NumberingDecisionPdfService
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Pdf $snappy,
        private readonly PdfTemplateConfigRepository $configRepository,
        private readonly string $projectDir,
    ) {}

    /**
     * @param array $decision result of NumberingDecisionService::build()
     */
    public function generate(Company $company, array $decision): string
    {
        return $this->convertToPdf($this->renderHtml($company, $decision));
    }

    public function renderHtml(Company $company, array $decision): string
    {
        $config = $this->configRepository->findByCompany($company);

        return $this->twig->render('documents/pdf/numbering_decision.html.twig', [
            'decision' => $decision,
            'decisionDateText' => self::romanianDate($decision['decisionDate']),
            'company' => $company,
            'primaryColor' => $config?->getPrimaryColor() ?? '#2563eb',
            'fontFamily' => $config?->getFontFamily() ?? 'DejaVu Sans',
            'fontsDir' => $this->projectDir . '/assets/fonts',
            'customCss' => null,
            'locale' => 'ro',
        ]);
    }

    public static function romanianDate(string $isoDate): string
    {
        $months = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
        $date = new \DateTimeImmutable($isoDate);

        return sprintf('%d %s %d', (int) $date->format('j'), $months[(int) $date->format('n') - 1], (int) $date->format('Y'));
    }

    public static function fileName(array $decision): string
    {
        return sprintf('decizie-numerotare-%d-nr-%d.pdf', $decision['year'], $decision['decisionNumber']);
    }

    private function convertToPdf(string $html): string
    {
        return $this->snappy->getOutputFromHtml($html, [
            'encoding' => 'UTF-8',
            'print-media-type' => true,
            'no-outline' => true,
            'disable-javascript' => true,
            'disable-external-links' => true,
            'disable-local-file-access' => true,
            'allow' => [$this->projectDir . '/assets/fonts'],
            'page-size' => 'A4',
            'margin-top' => '12mm',
            'margin-bottom' => '12mm',
            'margin-left' => '12mm',
            'margin-right' => '12mm',
        ]);
    }
}
