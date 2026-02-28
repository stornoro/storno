<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\EmailTemplate;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class EmailTemplateFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $templates = [
            // company-1: UNIVERSAL EQUIPMENT PROJECTS SRL
            [
                'company' => 'company-1',
                'name' => 'Factura noua',
                'isDefault' => true,
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
            ],
            [
                'company' => 'company-1',
                'name' => 'Memento plata',
                'isDefault' => false,
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
            ],
            [
                'company' => 'company-1',
                'name' => 'Confirmare plata',
                'isDefault' => false,
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
            ],
            [
                'company' => 'company-1',
                'name' => 'Factura restanta',
                'isDefault' => false,
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
            ],
            [
                'company' => 'company-1',
                'name' => 'Multumire colaborare',
                'isDefault' => false,
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
            ],
            [
                'company' => 'company-1',
                'name' => 'Plata partiala primita',
                'isDefault' => false,
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
            ],
            [
                'company' => 'company-1',
                'name' => 'Notificare storno',
                'isDefault' => false,
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
            ],
            [
                'company' => 'company-1',
                'name' => 'Factura cu discount',
                'isDefault' => false,
                'subject' => 'Factura [[invoice_number]] - cu discount aplicat',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va transmitem factura **[[invoice_number]]** cu discountul convenit aplicat.

---

### Detalii factura

- **Numar:** [[invoice_number]]
- **Data emitere:** [[issue_date]]
- **Scadenta:** [[due_date]]
- **Total de plata (dupa discount):** [[total]] [[currency]]

---

Va multumim pentru loialitatea dumneavoastra! Discountul a fost aplicat conform intelegerii noastre.

Va rugam sa efectuati plata pana la **[[due_date]]**.

Cu stima,
**[[company_name]]**
MD,
            ],
            // company-4: CONTABILITATE EXPERT SRL
            [
                'company' => 'company-4',
                'name' => 'Factura servicii contabilitate',
                'isDefault' => true,
                'subject' => 'Factura [[invoice_number]] - servicii contabilitate',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va transmitem factura **[[invoice_number]]** pentru serviciile de contabilitate prestate.

---

### Detalii factura

- **Numar factura:** [[invoice_number]]
- **Data emitere:** [[issue_date]]
- **Data scadenta:** [[due_date]]
- **Total de plata:** [[total]] [[currency]]

---

Va rugam sa efectuati plata pana la data de **[[due_date]]**.

Cu stima,
**Echipa [[company_name]]**
MD,
            ],
            [
                'company' => 'company-4',
                'name' => 'Raport lunar contabilitate',
                'isDefault' => false,
                'subject' => 'Factura [[invoice_number]] - abonament lunar contabilitate',
                'body' => <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va transmitem factura **[[invoice_number]]** aferenta abonamentului lunar de servicii contabile.

---

### Detalii factura

- **Numar factura:** [[invoice_number]]
- **Data emitere:** [[issue_date]]
- **Scadenta:** [[due_date]]
- **Total:** [[total]] [[currency]]

---

Serviciile incluse in abonamentul lunar:
- Inregistrarea documentelor contabile
- Intocmirea declaratiilor fiscale
- Consultanta contabila si fiscala curenta

Daca doriti sa discutam despre servicii suplimentare sau aveti intrebari, suntem la dispozitia dumneavoastra.

Cu stima,
**[[company_name]]**
MD,
            ],
        ];

        foreach ($templates as $i => $data) {
            $template = (new EmailTemplate())
                ->setCompany($this->getReference($data['company'], Company::class))
                ->setName($data['name'])
                ->setSubject($data['subject'])
                ->setBody($data['body'])
                ->setIsDefault($data['isDefault']);

            $manager->persist($template);
            $this->addReference('email-template-' . ($i + 1), $template);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CompanyFixtures::class,
        ];
    }
}
