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
    private const BATCH_SIZE = 100;
    private int $batchCount = 0;

    /** @var array<string, true> Known client keys (survives clear) */
    private array $knownClients = [];

    /** @var array<string, true> Known bank accounts */
    private array $bankAccountCache = [];

    private bool $initialized = false;
    private ?string $companyId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientRepository,
        private readonly BankAccountRepository $bankAccountRepository,
    ) {}

    public function supports(string $importType): bool
    {
        return $importType === 'clients';
    }

    /**
     * Pre-load existing client keys (cui, email) for fast dedup.
     */
    private function initialize(Company $company): void
    {
        if ($this->initialized && $this->companyId === $company->getId()->toRfc4122()) {
            return;
        }

        $companyId = $company->getId()->toRfc4122();
        $conn = $this->entityManager->getConnection();

        $rows = $conn->fetchAllAssociative(
            'SELECT LOWER(email) as email, cui FROM client WHERE company_id = :companyId AND deleted_at IS NULL',
            ['companyId' => $companyId],
        );

        foreach ($rows as $row) {
            if (!empty($row['cui'])) {
                $this->knownClients[$companyId . ':cui:' . $row['cui']] = true;
            }
            if (!empty($row['email'])) {
                $this->knownClients[$companyId . ':email:' . $row['email']] = true;
            }
        }

        $this->companyId = $companyId;
        $this->initialized = true;
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $this->initialize($company);

        $name = $mappedData['name'] ?? null;
        if (empty($name)) {
            return;
        }

        $companyId = $company->getId()->toRfc4122();
        $cui = !empty($mappedData['cui']) ? trim($mappedData['cui']) : null;
        $email = !empty($mappedData['email']) ? trim($mappedData['email']) : null;
        $type = $mappedData['type'] ?? ($cui ? 'company' : 'individual');

        $isExisting = false;
        if ($cui && isset($this->knownClients[$companyId . ':cui:' . $cui])) {
            $isExisting = true;
        } elseif ($email && isset($this->knownClients[$companyId . ':email:' . mb_strtolower($email)])) {
            $isExisting = true;
        }

        if ($isExisting) {
            $result->incrementUpdated();
            return;
        }

        // Create new client
        $client = new Client();
        $client->setCompany($company);
        $client->setName($name);
        $client->setType($type);

        if ($cui) {
            if (str_starts_with(strtoupper($cui), 'RO')) {
                $client->setVatCode(strtoupper($cui));
                $client->setCui(substr($cui, 2));
                $client->setIsVatPayer(true);
            } else {
                $client->setCui($cui);
                $client->setIsVatPayer($mappedData['isVatPayer'] ?? false);
            }
        }

        // Set vatCode from mapped data (EU VAT numbers handled by mapper)
        if (!empty($mappedData['vatCode'])) {
            $client->setVatCode($mappedData['vatCode']);
        }
        if (isset($mappedData['isVatPayer']) && $mappedData['isVatPayer']) {
            $client->setIsVatPayer(true);
        }

        $this->setClientFields($client, $mappedData);
        $client->setSource('import:' . ($mappedData['_source'] ?? 'generic'));

        if (!empty($mappedData['createdAt'])) {
            try {
                $client->setCreatedAt(new \DateTimeImmutable($mappedData['createdAt']));
            } catch (\Exception) {}
        }

        if (isset($mappedData['_importJob'])) {
            $client->setImportJob($mappedData['_importJob']);
        }

        $this->entityManager->persist($client);

        if ($cui) {
            $this->knownClients[$companyId . ':cui:' . $cui] = true;
        }
        if ($email) {
            $this->knownClients[$companyId . ':email:' . mb_strtolower($email)] = true;
        }

        $result->incrementCreated();

        $this->batchCount++;
        if ($this->batchCount >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->batchCount = 0;
    }

    public function reset(): void
    {
        $this->knownClients = [];
        $this->bankAccountCache = [];
        $this->initialized = false;
        $this->companyId = null;
        $this->batchCount = 0;
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
    }
}
