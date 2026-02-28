<?php

namespace App\Service\Import\Persister;

use App\Entity\BankAccount;
use App\Entity\Client;
use App\Entity\Company;
use App\Repository\BankAccountRepository;
use App\Repository\ClientRepository;
use App\Service\Import\ImportResult;
use Doctrine\ORM\EntityManagerInterface;

class ClientPersister implements EntityPersisterInterface
{
    private const BATCH_SIZE = 50;
    private int $batchCount = 0;

    /** @var array<string, Client> In-memory dedup cache keyed by companyId:cui:value or companyId:name:value */
    private array $pendingCache = [];

    /** @var array<string, true> In-memory dedup cache for bank accounts keyed by companyId:iban */
    private array $bankAccountCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientRepository,
        private readonly BankAccountRepository $bankAccountRepository,
    ) {}

    public function supports(string $importType): bool
    {
        return $importType === 'clients';
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $name = $mappedData['name'] ?? null;
        if (empty($name)) {
            return;
        }

        $cui = !empty($mappedData['cui']) ? trim($mappedData['cui']) : null;
        $type = $mappedData['type'] ?? ($cui ? 'company' : 'individual');

        // Try to find existing client — CUI-based dedup first (most reliable)
        $existing = null;
        if ($cui) {
            $cacheKey = $company->getId()->toRfc4122() . ':cui:' . $cui;
            $existing = $this->pendingCache[$cacheKey]
                ?? $this->clientRepository->findOneBy(['company' => $company, 'cui' => $cui, 'deletedAt' => null]);
        }

        // For clients without CUI, fall back to name-based dedup
        if (!$existing) {
            $cacheKey = $company->getId()->toRfc4122() . ':name:' . mb_strtolower($name);
            $existing = $this->pendingCache[$cacheKey]
                ?? $this->clientRepository->findOneBy(['company' => $company, 'name' => $name, 'deletedAt' => null]);
        }

        if ($existing) {
            // Update existing client with new data (only non-empty fields)
            $this->updateClient($existing, $mappedData);
            $this->findOrCreateBankAccount($company, $mappedData);
            $result->incrementUpdated();
        } else {
            // Create new client
            $client = new Client();
            $client->setCompany($company);
            $client->setName($name);
            $client->setType($type);

            if ($cui) {
                // Handle RO prefix: means VAT-registered (plătitor TVA)
                if (str_starts_with(strtoupper($cui), 'RO')) {
                    $client->setVatCode(strtoupper($cui));
                    $client->setCui(substr($cui, 2));
                    $client->setIsVatPayer(true);
                } else {
                    $client->setCui($cui);
                    $client->setIsVatPayer($mappedData['isVatPayer'] ?? false);
                }
            }

            $this->setClientFields($client, $mappedData);
            $client->setSource('import:' . ($mappedData['_source'] ?? 'generic'));

            $this->entityManager->persist($client);
            $this->findOrCreateBankAccount($company, $mappedData);

            // Populate in-memory cache to prevent within-batch duplicates
            if ($cui) {
                $this->pendingCache[$company->getId()->toRfc4122() . ':cui:' . $cui] = $client;
            }
            $this->pendingCache[$company->getId()->toRfc4122() . ':name:' . mb_strtolower($name)] = $client;

            $result->incrementCreated();
        }

        $this->batchCount++;
        if ($this->batchCount >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
        $this->batchCount = 0;
    }

    public function reset(): void
    {
        $this->pendingCache = [];
        $this->bankAccountCache = [];
        $this->batchCount = 0;
    }

    private function updateClient(Client $client, array $data): void
    {
        $this->setClientFields($client, $data);
    }

    private function findOrCreateBankAccount(Company $company, array $data): void
    {
        $iban = !empty($data['bankAccount']) ? trim($data['bankAccount']) : null;
        if (!$iban) {
            return;
        }

        $cacheKey = $company->getId()->toRfc4122() . ':' . strtoupper($iban);
        if (isset($this->bankAccountCache[$cacheKey])) {
            return;
        }

        $existing = $this->bankAccountRepository->findByIban($company, $iban);
        if ($existing) {
            // Update bank name if provided
            if (!empty($data['bankName'])) {
                $existing->setBankName($data['bankName']);
            }
            $this->bankAccountCache[$cacheKey] = true;
            return;
        }

        $bankAccount = new BankAccount();
        $bankAccount->setCompany($company);
        $bankAccount->setIban($iban);
        $bankAccount->setBankName($data['bankName'] ?? null);
        $bankAccount->setCurrency($data['currency'] ?? 'RON');
        $bankAccount->setSource('import:' . ($data['_source'] ?? 'generic'));

        $this->entityManager->persist($bankAccount);
        $this->bankAccountCache[$cacheKey] = true;
    }

    private function setClientFields(Client $client, array $data): void
    {
        $fields = [
            'cnp'                    => 'setCnp',
            'registrationNumber'     => 'setRegistrationNumber',
            'address'                => 'setAddress',
            'city'                   => 'setCity',
            'county'                 => 'setCounty',
            'country'                => 'setCountry',
            'postalCode'             => 'setPostalCode',
            'email'                  => 'setEmail',
            'phone'                  => 'setPhone',
            'bankName'               => 'setBankName',
            'bankAccount'            => 'setBankAccount',
            'contactPerson'          => 'setContactPerson',
            'clientCode'             => 'setClientCode',
            'notes'                  => 'setNotes',
        ];

        foreach ($fields as $field => $setter) {
            if (!empty($data[$field])) {
                $client->$setter($data[$field]);
            }
        }

        if (isset($data['defaultPaymentTermDays']) && $data['defaultPaymentTermDays'] !== '') {
            $client->setDefaultPaymentTermDays((int) $data['defaultPaymentTermDays']);
        }

        if (!empty($data['vatCode'])) {
            $client->setVatCode($data['vatCode']);
        }
    }
}
