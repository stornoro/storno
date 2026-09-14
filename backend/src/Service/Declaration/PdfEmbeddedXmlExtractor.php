<?php

declare(strict_types=1);

namespace App\Service\Declaration;

/**
 * The declaration XML inside an ANAF PDF. DUKIntegrator (and every program built on it, SAGA
 * included) embeds the XML as a file attachment; ANAF's own "PDF inteligent" forms filled in
 * Acrobat keep it in the XFA datasets stream. Both are plain PDF streams, so no PDF library is
 * needed: find the stream, inflate it when it is FlateDecode, and check it is a declaration.
 */
final class PdfEmbeddedXmlExtractor
{
    /** @return string|null the XML document, or null when the PDF carries none */
    public function extract(string $pdf): ?string
    {
        if (!str_starts_with($pdf, '%PDF')) {
            return null;
        }
        $candidates = [];
        // 1. file attachments: an object with /Type /EmbeddedFile and a stream
        if (preg_match_all('/\/Type\s*\/EmbeddedFile(.{0,400}?)stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $candidates[] = $this->decode($hit[2], $hit[1]);
            }
        }
        // 2. XFA datasets: <xfa:datasets><xfa:data><declaratieXXX …/></xfa:data></xfa:datasets>
        if (preg_match_all('/<<(?:(?!<<).){0,400}?\/Filter\s*\/FlateDecode(?:(?!<<).){0,400}?>>\s*stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m2)) {
            foreach ($m2[1] as $raw) {
                $inflated = @gzuncompress($raw) ?: @gzinflate($raw) ?: @gzinflate(substr($raw, 2));
                if (is_string($inflated) && str_contains($inflated, 'xfa:datasets')) {
                    $candidates[] = $inflated;
                }
            }
        }
        if (preg_match_all('/<xfa:datasets.*?<\/xfa:datasets>/s', $pdf, $m3)) {
            foreach ($m3[0] as $plain) {
                $candidates[] = $plain;
            }
        }
        foreach ($candidates as $c) {
            $xml = $this->declarationXml($c);
            if ($xml !== null) {
                return $xml;
            }
        }

        return null;
    }

    private function decode(string $stream, string $dict): string
    {
        if (stripos($dict, 'FlateDecode') !== false) {
            $out = @gzuncompress($stream);
            if ($out === false) {
                $out = @gzinflate($stream) ?: @gzinflate(substr($stream, 2));
            }

            return is_string($out) ? $out : '';
        }

        return $stream;
    }

    /** A declaration document out of a raw attachment or an XFA datasets block. */
    private function declarationXml(string $text): ?string
    {
        if ($text === '' || !str_contains($text, '<')) {
            return null;
        }
        if (preg_match('/<xfa:data>(.*?)<\/xfa:data>/s', $text, $m)) {
            $text = trim($m[1]);
        }
        // the document may start with a BOM or a declaration; find the first element
        $start = strpos($text, '<');
        $text = substr($text, (int) $start);
        if (str_starts_with($text, '<?xml')) {
            $text = trim($text);
        } else {
            $text = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . trim($text);
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($text);
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->documentElement === null) {
            return null;
        }
        $root = strtolower($doc->documentElement->localName ?? '');
        if (!preg_match('/^(declaratie\w*|c168|d\d{3})$/', $root)) {
            return null;
        }

        return $doc->saveXML() ?: null;
    }
}
