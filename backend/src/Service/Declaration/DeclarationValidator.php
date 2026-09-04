<?php

declare(strict_types=1);

namespace App\Service\Declaration;

use App\Model\Declaration\DukValidationResult;
use Psr\Log\LoggerInterface;

/**
 * Validates a declaration XML exactly like the ANAF portal does: through
 * DUKIntegrator with the form's own validator jar. The validator is mandatory;
 * when the Java service is down the caller gets an exception instead of a
 * silently "valid" declaration.
 *
 * The one thing it repairs on its own is the root namespace: ANAF's validator
 * tells us the expected `xmlns` for the reporting period, so a wrong or missing
 * namespace is corrected and the document validated again.
 */
final class DeclarationValidator
{
    public function __construct(
        private readonly DukIntegratorService $duk,
        private readonly DeclarationNamespaceResolver $namespaces,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DukUnavailableException when the validator service is not running
     */
    public function validate(string $xml, string $type, bool $healNamespace = true): DeclarationValidationOutcome
    {
        if (!$this->duk->isAvailable()) {
            throw new DukUnavailableException('Serviciul de validare ANAF (DUKIntegrator) nu este disponibil.');
        }

        $type = strtoupper($type);
        $started = microtime(true);
        $namespaceCorrected = false;
        $namespace = $this->namespaces->fromXml($xml);

        $result = $this->duk->validate($xml, $type);

        if (!$result->valid && $healNamespace) {
            $suggested = $this->namespaces->suggestedNamespace($result);
            if ($suggested !== null && $suggested !== $namespace) {
                $this->logger->info('ANAF validator expects another namespace; retrying', [
                    'type' => $type,
                    'from' => $namespace,
                    'to' => $suggested,
                ]);
                $xml = $this->namespaces->apply($xml, $suggested);
                $namespace = $suggested;
                $namespaceCorrected = true;
                $result = $this->duk->validate($xml, $type);
            }
        }

        return new DeclarationValidationOutcome(
            valid: $result->valid,
            type: $type,
            xml: $xml,
            namespace: $namespace,
            namespaceCorrected: $namespaceCorrected,
            errors: array_values($result->errors),
            warnings: array_values($result->warnings),
            elapsedMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    /** Infer the form code from the root element: <declaratie300> → D300, <d212> → D212, <D177> → D177, <c168> → C168. */
    public function inferType(string $xml): ?string
    {
        $root = $this->namespaces->rootName($xml);
        if ($root === null) {
            return null;
        }
        if (preg_match('/^declaratie(\d{3,4})$/i', $root, $m)) {
            return 'D' . $m[1];
        }
        if (strcasecmp($root, 'declaratieUnica') === 0) {
            return 'D112';
        }
        if (preg_match('/^([A-Za-z]\d{3,4})$/', $root, $m)) {
            return strtoupper($m[1]);
        }
        $namespace = $this->namespaces->fromXml($xml);
        if ($namespace !== null && preg_match('/^mfp:anaf:dgti:([a-z]\d{3,4}):/i', $namespace, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }
}
