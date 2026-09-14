<?php

namespace App\Service\Client;

use App\Entity\Client;
use App\Entity\Company;
use App\Repository\PdfTemplateConfigRepository;
use App\Service\WhiteLabelResolver;
use Knp\Snappy\Pdf;
use Twig\Environment;

/**
 * Renders a customer statement (situație facturi neachitate) as an A4 PDF
 * with the same hardened wkhtmltopdf options as the document PDFs.
 */
class ClientStatementPdfService
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Pdf $snappy,
        private readonly PdfTemplateConfigRepository $configRepository,
        private readonly WhiteLabelResolver $whiteLabelResolver,
        private readonly string $projectDir,
    ) {}

    /**
     * @param array $statement result of ClientStatementService::statement()
     */
    public function generate(Client $client, array $statement): string
    {
        return $this->convertToPdf($this->renderHtml($client, $statement));
    }

    public function renderHtml(Client $client, array $statement): string
    {
        $company = $client->getCompany();
        $config = $company ? $this->configRepository->findByCompany($company) : null;

        return $this->twig->render('documents/pdf/client_statement.html.twig', [
            'statement' => $statement,
            'client' => $client,
            'company' => $company,
            'primaryColor' => $config?->getPrimaryColor() ?? '#2563eb',
            'fontFamily' => $config?->getFontFamily() ?? 'DejaVu Sans',
            'fontsDir' => $this->projectDir . '/assets/fonts',
            'customCss' => null,
            'bands' => self::bandLabels(),
            'whiteLabelHideBranding' => ($company instanceof Company && $company->getOrganization())
                ? $this->whiteLabelResolver->shouldHideBranding($company->getOrganization())
                : false,
        ]);
    }

    /**
     * @return array<string, string> band key => Romanian label
     */
    public static function bandLabels(): array
    {
        return [
            'current' => 'Neajunse la scadență',
            'days1_30' => '1–30 zile',
            'days31_60' => '31–60 zile',
            'days61_90' => '61–90 zile',
            'days91_120' => '91–120 zile',
            'days121_180' => '121–180 zile',
            'over180' => 'peste 180 zile',
        ];
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
            'margin-top' => '10mm',
            'margin-bottom' => '10mm',
            'margin-left' => '10mm',
            'margin-right' => '10mm',
        ]);
    }
}
