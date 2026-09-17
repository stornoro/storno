<?php

namespace App\Service\Declaration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The filing PDF of the forms ANAF moved to its own web application (anaf.ro/declaratii/…).
 *
 * For those campaigns the downloadable validator lags behind the form: it still requires the
 * previous shape of the XML while ANAF's back office refuses it, so the PDF cannot be produced
 * locally. The web application builds it from the same XML, and this service asks it for one.
 * The answer is a React stream: a `<id>:T<hex length>,<base64>` chunk with the file, then a line
 * of JSON referring to that chunk.
 */
class AnafWebFormPdfService
{
    /** Forms filed through ANAF's web application, with the action that renders their PDF. */
    private const FORMS = [
        'D212' => [
            'url' => 'https://www.anaf.ro/declaratii/duf/rezultate-validare',
            'action' => '4098b45c1c1b4362f804c357385a2180e2d8c6c2d2',
            'since' => 2026, // reporting year (an_r) from which the web form is the only way
        ],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Does this declaration have to be built by ANAF's web application? */
    public function handles(string $type, ?int $year): bool
    {
        $form = self::FORMS[strtoupper($type)] ?? null;

        return $form !== null && $year !== null && $year >= $form['since'];
    }

    /**
     * The filing PDF for this XML, as ANAF's web application renders it.
     *
     * @throws \RuntimeException when ANAF cannot be reached or answers with something else
     */
    public function render(string $type, string $xml): string
    {
        $form = self::FORMS[strtoupper($type)] ?? null;
        if ($form === null) {
            throw new \RuntimeException(sprintf('%s nu se depune prin formularul web ANAF.', strtoupper($type)));
        }

        try {
            $response = $this->httpClient->request('POST', $form['url'], [
                'headers' => [
                    'Content-Type' => 'text/plain;charset=UTF-8',
                    'Accept' => 'text/x-component',
                    'Next-Action' => $form['action'],
                    'User-Agent' => 'Mozilla/5.0 (compatible; Storno.ro)',
                ],
                'body' => json_encode([$xml], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'timeout' => 60,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->error('ANAF web form PDF request failed.', ['type' => $type, 'error' => $e->getMessage()]);

            throw new \RuntimeException('Formularul web ANAF nu a răspuns: ' . $e->getMessage(), 0, $e);
        }

        if ($status !== 200) {
            throw new \RuntimeException(sprintf('Formularul web ANAF a răspuns cu HTTP %d.', $status));
        }

        $pdf = self::extractPdf($body);
        if ($pdf === null) {
            $this->logger->error('ANAF web form answered without a PDF.', ['type' => $type, 'answer' => mb_substr($body, 0, 500)]);

            throw new \RuntimeException('Formularul web ANAF nu a returnat un PDF: ' . self::errorIn($body));
        }

        return $pdf;
    }

    /** The PDF carried by a React stream answer, or null when there is none. */
    public static function extractPdf(string $body): ?string
    {
        if (preg_match('/^\d+:T([0-9a-f]+),/m', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $chunk = substr($body, $start, (int) hexdec($m[1][0]));
        $pdf = base64_decode(trim($chunk), true);

        return is_string($pdf) && str_starts_with($pdf, '%PDF') ? $pdf : null;
    }

    /** The message ANAF put in the stream when it refused to render the file. */
    private static function errorIn(string $body): string
    {
        foreach (explode("\n", $body) as $line) {
            $json = json_decode((string) preg_replace('/^\d+:/', '', $line), true);
            if (is_array($json) && isset($json['error'])) {
                return (string) $json['error'];
            }
            if (is_array($json) && ($json['ok'] ?? null) === false) {
                return sprintf('HTTP %s', $json['status'] ?? '?');
            }
        }

        return 'răspuns neașteptat';
    }
}
