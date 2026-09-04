<?php

declare(strict_types=1);

namespace App\Service\Declaration;

use App\Model\Declaration\DukValidationResult;

/**
 * Puts the ANAF XML namespace on a generated declaration.
 *
 * Every ANAF XSD uses elementFormDefault="qualified" with a targetNamespace of the
 * form `mfp:anaf:dgti:<form>:declaratie:v<N>`, and the DUKIntegrator validator
 * rejects a document whose root has no (or the wrong) namespace before checking a
 * single field. Which <N> is right depends on the reporting period, so the rule is:
 *
 *   1. an explicit override (declaration metadata `xmlns`, or the caller) wins;
 *   2. otherwise the targetNamespace of the newest XSD we ship for the form;
 *   3. when the validator answers "Valoarea corecta este xmlns='…'", the caller
 *      re-applies that value (see suggestedNamespace()) and validates again.
 */
final class DeclarationNamespaceResolver
{
    /** @var array<string, string|null> */
    private array $xsdCache = [];

    public function __construct(
        private readonly string $resourcesDir = __DIR__ . '/../../../resources/declarations',
    ) {
    }

    /** Namespace from the newest XSD shipped for the form, e.g. "mfp:anaf:dgti:d300:declaratie:v12". */
    public function fromXsd(string $type): ?string
    {
        $type = strtolower($type);
        if (array_key_exists($type, $this->xsdCache)) {
            return $this->xsdCache[$type];
        }

        $dir = rtrim($this->resourcesDir, '/');
        $candidates = array_merge(
            glob(sprintf('%s/%s_*.xsd', $dir, $type)) ?: [],
            glob(sprintf('%s/%s_*.xml', $dir, $type)) ?: [],
            glob(sprintf('%s/%s.xsd', $dir, $type)) ?: [],
        );
        // Files are date-suffixed inconsistently (ddmmyyyy / yyyymmdd / vN); the file's
        // own targetNamespace version is what matters, so pick the highest version.
        $best = null;
        $bestVersion = -1;
        foreach ($candidates as $file) {
            $head = @file_get_contents($file, false, null, 0, 4096);
            if ($head === false || !preg_match('/targetNamespace="([^"]+)"/', $head, $m)) {
                continue;
            }
            $version = preg_match('/:v(\d+)$/', $m[1], $v) ? (int) $v[1] : 0;
            if ($version > $bestVersion) {
                $bestVersion = $version;
                $best = $m[1];
            }
        }

        return $this->xsdCache[$type] = $best;
    }

    /** Namespace currently declared on the root element of an XML document, if any. */
    public function fromXml(string $xml): ?string
    {
        $tag = $this->rootStartTag($xml);
        if ($tag === null) {
            return null;
        }

        return preg_match('/\sxmlns="([^"]*)"/', $tag[0], $m) ? $m[1] : null;
    }

    /** Local name of the root element ("d212", "declaratie300", "D177"), if any. */
    public function rootName(string $xml): ?string
    {
        $tag = $this->rootStartTag($xml);

        return $tag === null ? null : $tag[1];
    }

    /**
     * Sets the default `xmlns` on the root element (replacing an existing one).
     * Children carry no prefix in ANAF documents, so the default namespace on the
     * root qualifies the whole tree. Returns the XML unchanged when $namespace is null.
     */
    public function apply(string $xml, ?string $namespace): string
    {
        if ($namespace === null || $namespace === '') {
            return $xml;
        }
        $tag = $this->rootStartTag($xml);
        if ($tag === null) {
            return $xml;
        }

        [$startTag, $name, $offset] = $tag;
        if (preg_match('/\sxmlns="[^"]*"/', $startTag)) {
            $newTag = preg_replace('/\sxmlns="[^"]*"/', ' xmlns="' . $namespace . '"', $startTag, 1);
        } else {
            $newTag = '<' . $name . ' xmlns="' . $namespace . '"' . substr($startTag, strlen($name) + 1);
        }

        return substr($xml, 0, $offset) . $newTag . substr($xml, $offset + strlen($startTag));
    }

    /** ANAF's validator names the expected namespace in its structural error; pull it out. */
    public function suggestedNamespace(DukValidationResult $result): ?string
    {
        foreach ($result->errors as $error) {
            if (preg_match("/Valoarea corecta este xmlns='([^']+)'/u", (string) $error, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string, 2: int}|null  [start tag text, element name, byte offset] */
    private function rootStartTag(string $xml): ?array
    {
        // Skip the prolog, comments and processing instructions.
        if (!preg_match('/<([A-Za-z_][\w.:-]*)(\s[^>]*)?>/s', $xml, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$m[0][0], $m[1][0], $m[0][1]];
    }
}
