<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\EmailTemplate;
use App\Entity\VatRate;
use App\Model\Anaf\CompanyInfo;
use App\Repository\CompanyRepository;
use App\Repository\EmailTemplateRepository;
use App\Repository\VatRateRepository;
use App\Services\AnafService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class CompanyManager
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AnafService $anafService,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly DocumentSeriesManager $documentSeriesManager,
        private readonly EmailTemplateRepository $emailTemplateRepository,
        private readonly VatRateRepository $vatRateRepository,
    ) {}

    public function getByCif(int $cif): ?Company
    {
        return $this->companyRepository->findOneBy(['cif' => $cif]);
    }

    public function getCompanyData(int $cif): ?CompanyInfo
    {
        return $this->cache->get('cif-' . $cif, function (ItemInterface $item) use ($cif): ?CompanyInfo {
            $item->expiresAfter(new \DateInterval('P1Y'));
            return $this->anafService->findCompany((string) $cif);
        });
    }

    public function createFromAnaf(int $cif, ?Company $existingCompany = null): ?Company
    {
        $companyInfo = $this->getCompanyData($cif);

        if (!$companyInfo) {
            return null;
        }

        $company = $existingCompany ?? new Company();

        $company->setCif($companyInfo->getCif());
        $company->setName($companyInfo->getName());
        $company->setAddress($companyInfo->getAddress());
        $company->setCity($companyInfo->getCity());
        $company->setState($companyInfo->getState());
        $company->setCountry($companyInfo->getCountry());
        $company->setSector($companyInfo->getSector());
        $company->setVatPayer($companyInfo->isVatPayer());
        $company->setVatCode($companyInfo->getVatCode());
        $company->setRegistrationNumber($companyInfo->getRegistrationNumber());
        $company->setVatOnCollection($companyInfo->isVatOnCollection());

        if ($companyInfo->getPhone()) {
            $company->setPhone($companyInfo->getPhone());
        }

        if (!$existingCompany) {
            $this->entityManager->persist($company);
        }

        $this->entityManager->flush();

        return $company;
    }

    /**
     * Refresh an existing company with latest ANAF data.
     */
    public function refreshFromAnaf(Company $company): Company
    {
        // Invalidate cache to get fresh data
        $this->cache->delete('cif-' . $company->getCif());

        return $this->createFromAnaf($company->getCif(), $company);
    }

    public function create(Company $company): Company
    {
        $org = $company->getOrganization();

        // Temporarily disable soft-delete filter to find soft-deleted companies with the same CIF in this org
        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('soft_delete');
        if ($filterWasEnabled) {
            $filters->disable('soft_delete');
        }

        $existing = $org
            ? $this->companyRepository->findByOrganizationAndCif($org, $company->getCif())
            : null;

        if ($filterWasEnabled) {
            $filters->enable('soft_delete');
        }

        if ($existing) {
            // Restore the soft-deleted company with fresh ANAF data and clean state
            if ($existing->isDeleted()) {
                $existing->restore();
                $existing->setName($company->getName());
                $existing->setAddress($company->getAddress());
                $existing->setCity($company->getCity());
                $existing->setState($company->getState());
                $existing->setCountry($company->getCountry());
                $existing->setSector($company->getSector());
                $existing->setVatPayer($company->isVatPayer());
                $existing->setVatCode($company->getVatCode());
                $existing->setRegistrationNumber($company->getRegistrationNumber());
                // Reset to clean defaults
                $existing->setSyncEnabled(false);
                $existing->setLastSyncedAt(null);
                $existing->setSyncDaysBack(60);
            }

            $this->entityManager->flush();
            $this->documentSeriesManager->ensureDefaultSeries($existing);
            $this->ensureDefaultEmailTemplates($existing);
            $this->ensureDefaultVatRates($existing);
            $this->entityManager->flush();

            return $existing;
        }

        $this->entityManager->persist($company);
        $this->entityManager->flush();

        // Create default document series (FACT + NC)
        $this->documentSeriesManager->ensureDefaultSeries($company);
        $this->ensureDefaultEmailTemplates($company);
        $this->ensureDefaultVatRates($company);
        $this->entityManager->flush();

        return $company;
    }

    public function ensureDefaultEmailTemplates(Company $company): void
    {
        $existing = $this->emailTemplateRepository->findByCompany($company);
        if (count($existing) > 0) {
            return;
        }

        $templates = [
            [
                'name' => 'Factura noua',
                'subject' => 'Factura [[invoice_number]] din [[issue_date]]',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va transmitem atasat factura **nr. [[invoice_number]]** emisa in data de **[[issue_date]]**, cu scadenta la **[[due_date]]**.

---

### Detalii factura

- **Numar factura:** [[invoice_number]]
- **Data emitere:** [[issue_date]]
- **Data scadenta:** [[due_date]]
- **Total de plata:** [[total]] [[currency]]

---

Va rugam sa mentionati numarul facturii la efectuarea platii.

Cu stima,
**[[company_name]]**
MD,
                'isDefault' => true,
            ],
            [
                'name' => 'Memento plata',
                'subject' => 'Memento: Factura [[invoice_number]] - scadenta [[due_date]]',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va aducem aminte ca factura **[[invoice_number]]** in valoare de **[[balance]] [[currency]]** a ajuns la scadenta pe data de **[[due_date]]** si nu a fost inca achitata.

---

> **Suma restanta: [[balance]] [[currency]]**
> Scadenta: [[due_date]]

---

Va rugam sa efectuati plata in cel mai scurt timp posibil pentru a evita eventuale penalitati de intarziere.

Daca ati efectuat deja plata, va rugam sa ignorati acest mesaj.

Cu stima,
**[[company_name]]**
MD,
                'isDefault' => false,
            ],
            [
                'name' => 'Confirmare plata',
                'subject' => 'Confirmare plata - Factura [[invoice_number]]',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va confirmam primirea platii pentru factura **[[invoice_number]]**.

---

- **Factura:** [[invoice_number]]
- **Total factura:** [[total]] [[currency]]
- **Rest de plata:** [[balance]] [[currency]]

---

Va multumim pentru promptitudinea platii. Colaborarea cu dumneavoastra reprezinta o prioritate pentru noi.

Daca aveti intrebari privind aceasta plata, nu ezitati sa ne contactati.

Cu stima,
**[[company_name]]**
MD,
                'isDefault' => false,
            ],
            [
                'name' => 'Factura restanta',
                'subject' => 'URGENT: Factura [[invoice_number]] - plata intarziata',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va informam ca factura **[[invoice_number]]** in valoare de **[[balance]] [[currency]]** a depasit termenul de scadenta (**[[due_date]]**) si nu a fost inca achitata.

---

> **SUMA RESTANTA: [[balance]] [[currency]]**
> Scadenta depasita: [[due_date]]

---

Va rugam sa regularizati aceasta situatie **in cel mai scurt timp posibil**.

In cazul in care plata nu va fi inregistrata in termen de 5 zile lucratoare, ne rezervam dreptul de a aplica penalitati conform contractului si legislatiei in vigoare.

Daca ati efectuat deja plata, va rugam sa ne trimiteti dovada platii pentru a actualiza evidentele noastre.

Cu stima,
**[[company_name]]**
MD,
                'isDefault' => false,
            ],
            [
                'name' => 'Plata partiala primita',
                'subject' => 'Plata partiala inregistrata - Factura [[invoice_number]]',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va confirmam inregistrarea unei plati partiale pentru factura **[[invoice_number]]**.

---

- **Factura:** [[invoice_number]]
- **Total factura:** [[total]] [[currency]]
- **Rest de plata:** [[balance]] [[currency]]
- **Scadenta:** [[due_date]]

---

Va rugam sa achitati restul de **[[balance]] [[currency]]** pana la data scadentei.

Daca aveti intrebari, nu ezitati sa ne contactati.

Cu stima,
**[[company_name]]**
MD,
                'isDefault' => false,
            ],
            [
                'name' => 'Notificare storno',
                'subject' => 'Stornare factura [[invoice_number]]',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va informam ca factura **[[invoice_number]]** a fost stornata.

---

- **Factura stornata:** [[invoice_number]]
- **Data emitere initiala:** [[issue_date]]
- **Valoare:** [[total]] [[currency]]

---

A fost emisa o factura de storno corespunzatoare. Atasat veti gasi documentele aferente.

Daca aveti intrebari legate de aceasta stornare, va rugam sa ne contactati.

Cu stima,
**[[company_name]]**
MD,
                'isDefault' => false,
            ],
            [
                'name' => 'Multumire colaborare',
                'subject' => 'Multumim pentru colaborare - [[company_name]]',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va multumim pentru colaborarea avuta si pentru increderea acordata companiei **[[company_name]]**.

Atasat gasiti factura **[[invoice_number]]** in valoare de **[[total]] [[currency]]**, emisa in data de **[[issue_date]]**.

---

### Detalii factura

- **Numar:** [[invoice_number]]
- **Data emitere:** [[issue_date]]
- **Scadenta:** [[due_date]]
- **Total:** [[total]] [[currency]]

---

Suntem mereu la dispozitia dumneavoastra pentru orice intrebare sau solicitare.

Ne face placere sa lucram impreuna si speram sa continuam aceasta colaborare!

Cu stima,
**[[company_name]]**
MD,
                'isDefault' => false,
            ],
        ];

        foreach ($templates as $data) {
            $template = new EmailTemplate();
            $template->setCompany($company);
            $template->setName($data['name']);
            $template->setSubject($data['subject']);
            $template->setBody($data['body']);
            $template->setIsDefault($data['isDefault']);
            $this->entityManager->persist($template);
        }
    }

    public function ensureDefaultVatRates(Company $company): void
    {
        $existing = $this->vatRateRepository->findByCompany($company);
        if (count($existing) > 0) {
            return;
        }

        $defaults = [
            ['rate' => '21.00', 'label' => 'Standard',  'code' => 'S', 'default' => true,  'pos' => 0],
            ['rate' => '11.00', 'label' => 'Redus 11%', 'code' => 'S', 'default' => false, 'pos' => 1],
            ['rate' => '9.00',  'label' => 'Redus 9%',  'code' => 'S', 'default' => false, 'pos' => 2],
            ['rate' => '8.00',  'label' => 'Redus 8%',  'code' => 'S', 'default' => false, 'pos' => 3],
            ['rate' => '5.00',  'label' => 'Redus 5%',  'code' => 'S', 'default' => false, 'pos' => 4],
            ['rate' => '0.00',  'label' => 'Scutit',    'code' => 'Z', 'default' => false, 'pos' => 5],
        ];

        foreach ($defaults as $data) {
            $vatRate = new VatRate();
            $vatRate->setCompany($company);
            $vatRate->setRate($data['rate']);
            $vatRate->setLabel($data['label']);
            $vatRate->setCategoryCode($data['code']);
            $vatRate->setIsDefault($data['default']);
            $vatRate->setPosition($data['pos']);
            $vatRate->setCreatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($vatRate);
        }
    }

    public function delete(Company $company): void
    {
        $company->softDelete();
        $this->entityManager->flush();
    }
}
