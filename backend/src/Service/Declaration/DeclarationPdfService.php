<?php

declare(strict_types=1);

namespace App\Service\Declaration;

/**
 * The PDF ANAF wants uploaded: DUKIntegrator's rendering of the XML with the XML
 * embedded and, for forms that require it (C168), a zip of attachments embedded too.
 */
final class DeclarationPdfService
{
    private const MAX_ATTACHMENTS_BYTES = 10 * 1024 * 1024;
    private const FORMS_REQUIRING_ATTACHMENT = ['C168'];
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'zip'];

    public function __construct(
        private readonly DukIntegratorService $duk,
        private readonly DeclarationValidator $validator,
    ) {
    }

    public function requiresAttachment(string $type): bool
    {
        return in_array(strtoupper($type), self::FORMS_REQUIRING_ATTACHMENT, true);
    }

    /**
     * @param list<array{name: string, content: string}> $attachments binary contents; a single .zip is used as is
     * @throws \InvalidArgumentException for bad input (422), \RuntimeException when DUK fails
     */
    public function render(string $type, string $xml, array $attachments): string
    {
        $type = strtoupper($type);
        if (!$this->duk->isAvailable()) {
            throw new DukUnavailableException('Serviciul ANAF DUKIntegrator nu este disponibil.');
        }
        if ($attachments === [] && $this->requiresAttachment($type)) {
            throw new \InvalidArgumentException(sprintf('%s necesită cel puțin un atașament (contractul scanat, actul adițional sau documentul de încetare) — ANAF respinge PDF-ul fără zip.', $type));
        }
        // DUK refuses to render an invalid file with an opaque "-1": validate first and say why.
        $outcome = $this->validator->validate($xml, $type, false);
        if (!$outcome->valid) {
            throw new \InvalidArgumentException('XML-ul nu trece validarea ANAF: ' . implode(' | ', array_slice($outcome->errors, 0, 12)));
        }
        $zip = $attachments === [] ? null : $this->zip($attachments);

        return $this->duk->generatePdf($xml, $type, $zip);
    }

    /** @param list<array{name: string, content: string}> $attachments */
    private function zip(array $attachments): string
    {
        $total = 0;
        foreach ($attachments as $a) {
            $total += strlen($a['content']);
        }
        if ($total > self::MAX_ATTACHMENTS_BYTES) {
            throw new \InvalidArgumentException('Atașamentele depășesc 10 MB.');
        }
        if (count($attachments) === 1 && strtolower(pathinfo($attachments[0]['name'], PATHINFO_EXTENSION)) === 'zip') {
            return $attachments[0]['content'];
        }
        $path = tempnam(sys_get_temp_dir(), 'c168zip');
        if ($path === false) {
            throw new \RuntimeException('Nu pot crea fișierul temporar.');
        }
        try {
            $za = new \ZipArchive();
            if ($za->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Nu pot crea arhiva zip.');
            }
            $used = [];
            foreach ($attachments as $i => $a) {
                $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($a['name'])) ?: 'document-' . ($i + 1) . '.pdf';
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                    throw new \InvalidArgumentException(sprintf('Atașamentul "%s" trebuie să fie PDF, JPG, PNG sau TIFF.', $a['name']));
                }
                if (isset($used[$name])) {
                    $name = pathinfo($name, PATHINFO_FILENAME) . '-' . ($i + 1) . '.' . $ext;
                }
                $used[$name] = true;
                $za->addFromString($name, $a['content']);
            }
            $za->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
