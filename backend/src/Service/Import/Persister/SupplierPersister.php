<?php

namespace App\Service\Import\Persister;

use App\Entity\Company;
use App\Entity\Supplier;
use App\Service\Import\ImportResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suppliers from a partner file (the supplier list exported by another invoicing or
 * accounting program, or any spreadsheet).
 *
 * A supplier already known to the company — same fiscal code, or the same name when the
 * row has no fiscal code — is never duplicated and never overwritten: only the fields it
 * is still missing (address, e-mail, phone, bank, trade register number…) are filled in
 * from the file, and the row counts as updated. That is the usual case right after the
 * first e-Factura sync, which creates suppliers from received invoices with little more
 * than a name and a fiscal code.
 */
class SupplierPersister implements EntityPersisterInterface
{
    private const BATCH_SIZE = 100;
    private int $batchCount = 0;

    /** @var array<string, string> dedup key => supplier id (survives entityManager->clear()) */
    private array $known = [];
    private bool $initialized = false;
    private ?string $companyId = null;

    /** mapped field => [getter, setter] of the optional supplier fields */
    private const OPTIONAL_FIELDS = [
        'registrationNumber' => ['getRegistrationNumber', 'setRegistrationNumber'],
        'address'            => ['getAddress', 'setAddress'],
        'city'               => ['getCity', 'setCity'],
        'county'             => ['getCounty', 'setCounty'],
        'postalCode'         => ['getPostalCode', 'setPostalCode'],
        'email'              => ['getEmail', 'setEmail'],
        'phone'              => ['getPhone', 'setPhone'],
        'bankName'           => ['getBankName', 'setBankName'],
        'bankAccount'        => ['getBankAccount', 'setBankAccount'],
        'notes'              => ['getNotes', 'setNotes'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string $importType): bool
    {
        return $importType === 'suppliers';
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $this->initialize($company);

        $name = trim((string) ($mappedData['name'] ?? ''));
        if ($name === '') {
            return;
        }
        $companyId = $company->getId()->toRfc4122();
        $cif = self::normalizeCif($mappedData['cui'] ?? null);
        $keys = array_filter([
            $cif !== null ? 'cif:' . $cif : null,
            // the name identifies the supplier only when the row carries no fiscal code
            $cif === null ? 'name:' . mb_strtolower($name) : null,
        ]);

        foreach ($keys as $key) {
            if (isset($this->known[$key])) {
                $existing = $this->entityManager->find(Supplier::class, $this->known[$key]);
                if ($existing instanceof Supplier && $this->fillMissing($existing, $mappedData)) {
                    $this->countBatch();
                }
                $result->incrementUpdated();

                return;
            }
        }

        $supplier = new Supplier();
        $supplier->setCompany($company);
        $supplier->setName(mb_substr($name, 0, 255));
        if ($cif !== null) {
            $supplier->setCif($cif);
        }
        if (!empty($mappedData['vatCode'])) {
            $supplier->setVatCode(strtoupper(trim((string) $mappedData['vatCode'])));
        }
        $supplier->setIsVatPayer(($mappedData['isVatPayer'] ?? false) === true);
        if (!empty($mappedData['country'])) {
            $supplier->setCountry(strtoupper(trim((string) $mappedData['country'])));
        }
        $this->fillMissing($supplier, $mappedData);
        $supplier->setSource('import:' . ($mappedData['_source'] ?? 'generic'));
        if (isset($mappedData['_importJob'])) {
            $supplier->setImportJob($mappedData['_importJob']);
        }

        $this->entityManager->persist($supplier);
        foreach ($keys as $key) {
            $this->known[$key] = $supplier->getId()->toRfc4122();
        }
        $result->incrementCreated();
        $this->countBatch();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->batchCount = 0;
    }

    public function reset(): void
    {
        $this->known = [];
        $this->initialized = false;
        $this->companyId = null;
        $this->batchCount = 0;
    }

    /**
     * The fiscal code as suppliers store it: digits for a Romanian code (with or without the RO
     * prefix in the file), the full upper-case VAT number for a foreign one.
     */
    public static function normalizeCif(mixed $raw): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', (string) ($raw ?? '')) ?? '');
        if ($value === '' || $value === '-') {
            return null;
        }
        if (preg_match('/^RO(\d+)$/', $value, $m) === 1) {
            return $m[1];
        }

        return $value;
    }

    /** Pre-load the company's suppliers (fiscal code and name) for fast dedup. */
    private function initialize(Company $company): void
    {
        $companyId = $company->getId()->toRfc4122();
        if ($this->initialized && $this->companyId === $companyId) {
            return;
        }
        $this->known = [];
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, cif, LOWER(name) AS name FROM supplier WHERE company_id = :companyId AND deleted_at IS NULL',
            ['companyId' => $companyId],
        );
        foreach ($rows as $row) {
            $cif = self::normalizeCif($row['cif']);
            if ($cif !== null) {
                $this->known['cif:' . $cif] = (string) $row['id'];
            } elseif (!empty($row['name'])) {
                $this->known['name:' . $row['name']] = (string) $row['id'];
            }
        }
        $this->companyId = $companyId;
        $this->initialized = true;
    }

    /** Set the optional fields the supplier does not have yet. Returns whether anything changed. */
    private function fillMissing(Supplier $supplier, array $data): bool
    {
        $changed = false;
        foreach (self::OPTIONAL_FIELDS as $field => [$getter, $setter]) {
            $value = isset($data[$field]) ? trim((string) $data[$field]) : '';
            if ($value === '') {
                continue;
            }
            $current = $supplier->$getter();
            if ($current === null || trim((string) $current) === '') {
                $supplier->$setter($value);
                $changed = true;
            }
        }

        return $changed;
    }

    private function countBatch(): void
    {
        $this->batchCount++;
        if ($this->batchCount >= self::BATCH_SIZE) {
            $this->flush();
        }
    }
}
