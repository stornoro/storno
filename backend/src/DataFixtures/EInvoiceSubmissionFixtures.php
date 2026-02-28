<?php

namespace App\DataFixtures;

use App\Entity\EInvoiceSubmission;
use App\Entity\Invoice;
use App\Enum\EInvoiceProvider;
use App\Enum\EInvoiceSubmissionStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class EInvoiceSubmissionFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // 1. Accepted submission for invoice-2 (outgoing, validated) — 2h ago
        $sub1 = $this->create(
            invoice: $this->getReference('invoice-2', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ACCEPTED,
            externalId: '3847562',
            hoursAgo: 2,
        );
        $manager->persist($sub1);
        $this->addReference('einvoice-submission-1', $sub1);

        // 2. Accepted submission for invoice-7 (paid outgoing) — 5h ago
        $sub2 = $this->create(
            invoice: $this->getReference('invoice-7', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ACCEPTED,
            externalId: '4920183',
            hoursAgo: 5,
        );
        $manager->persist($sub2);
        $this->addReference('einvoice-submission-2', $sub2);

        // 3. Accepted submission for invoice-8 (paid outgoing) — 8h ago
        $sub3 = $this->create(
            invoice: $this->getReference('invoice-8', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ACCEPTED,
            externalId: '5831204',
            hoursAgo: 8,
        );
        $manager->persist($sub3);
        $this->addReference('einvoice-submission-3', $sub3);

        // 4. Submitted (pending ANAF response) for invoice-10 — 1h ago
        $sub4 = $this->create(
            invoice: $this->getReference('invoice-10', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::SUBMITTED,
            externalId: '9876543',
            hoursAgo: 1,
        );
        $manager->persist($sub4);
        $this->addReference('einvoice-submission-4', $sub4);

        // 5. Rejected by ANAF (validation error, not downtime) for invoice-4 — 12h ago
        $sub5 = $this->create(
            invoice: $this->getReference('invoice-4', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::REJECTED,
            externalId: '5678901',
            errorMessage: 'Eroare validare: CIF cumparator invalid.',
            hoursAgo: 12,
        );
        $manager->persist($sub5);
        $this->addReference('einvoice-submission-5', $sub5);

        // 6. Error — ANAF unreachable (upload failed, counts as downtime) — 18h ago
        $sub6 = $this->create(
            invoice: $this->getReference('invoice-9', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ERROR,
            errorMessage: 'ANAF upload failed: Connection timed out after 30s',
            hoursAgo: 18,
        );
        $manager->persist($sub6);
        $this->addReference('einvoice-submission-6', $sub6);

        // 7. Error — ANAF unreachable (same 10-min window as #6, ~17.9h ago)
        $sub7 = $this->create(
            invoice: $this->getReference('invoice-7', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ERROR,
            errorMessage: 'ANAF upload failed: Connection refused',
            hoursAgo: 17.85,
        );
        $manager->persist($sub7);
        $this->addReference('einvoice-submission-7', $sub7);

        // 8. Error — token issue (should NOT count as downtime) — 24h ago
        $sub8 = $this->create(
            invoice: $this->getReference('invoice-9', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ERROR,
            errorMessage: 'Nu s-a putut obtine token ANAF: token expirat sau invalid',
            hoursAgo: 24,
        );
        $manager->persist($sub8);
        $this->addReference('einvoice-submission-8', $sub8);

        // 9. Error — ANAF unreachable (different 10-min window) — 40h ago
        $sub9 = $this->create(
            invoice: $this->getReference('invoice-2', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ERROR,
            errorMessage: 'ANAF upload failed: 503 Service Unavailable',
            hoursAgo: 40,
        );
        $manager->persist($sub9);
        $this->addReference('einvoice-submission-9', $sub9);

        // 10. Accepted — older success — 48h ago
        $sub10 = $this->create(
            invoice: $this->getReference('invoice-9', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ACCEPTED,
            externalId: '2019384',
            hoursAgo: 48,
        );
        $manager->persist($sub10);
        $this->addReference('einvoice-submission-10', $sub10);

        // 11. Pending (not yet sent) for invoice-9 with scheduled retry — future
        $sub11 = (new EInvoiceSubmission())
            ->setInvoice($this->getReference('invoice-9', Invoice::class))
            ->setProvider(EInvoiceProvider::ANAF)
            ->setStatus(EInvoiceSubmissionStatus::PENDING);
        $manager->persist($sub11);
        $this->addReference('einvoice-submission-11', $sub11);

        // Set scheduledSendAt on invoice-9 for the "next retry" stat
        $invoice9 = $this->getReference('invoice-9', Invoice::class);
        $invoice9->setScheduledSendAt(new \DateTimeImmutable('+45 minutes'));

        // 12. Accepted submission for invoice-15 (outgoing, validated) — 3h ago
        $sub12 = $this->create(
            invoice: $this->getReference('invoice-15', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ACCEPTED,
            externalId: '1122334',
            hoursAgo: 3,
        );
        $manager->persist($sub12);
        $this->addReference('einvoice-submission-12', $sub12);

        // 13. Accepted submission for invoice-17 (outgoing, validated, paid) — 5h ago
        $sub13 = $this->create(
            invoice: $this->getReference('invoice-17', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ACCEPTED,
            externalId: '5566778',
            hoursAgo: 5,
        );
        $manager->persist($sub13);
        $this->addReference('einvoice-submission-13', $sub13);

        // 14. Accepted submission for invoice-20 (credit note outgoing) — 1h ago
        $sub14 = $this->create(
            invoice: $this->getReference('invoice-20', Invoice::class),
            provider: EInvoiceProvider::ANAF,
            status: EInvoiceSubmissionStatus::ACCEPTED,
            externalId: '7788990',
            hoursAgo: 1,
        );
        $manager->persist($sub14);
        $this->addReference('einvoice-submission-14', $sub14);

        $manager->flush();
    }

    private function create(
        Invoice $invoice,
        EInvoiceProvider $provider,
        EInvoiceSubmissionStatus $status,
        ?string $externalId = null,
        ?string $errorMessage = null,
        float $hoursAgo = 0,
    ): EInvoiceSubmission {
        $timestamp = new \DateTimeImmutable(sprintf('-%d minutes', (int) ($hoursAgo * 60)));

        $sub = new EInvoiceSubmission();
        $sub->setInvoice($invoice);
        $sub->setProvider($provider);
        $sub->setStatus($status);

        if ($externalId !== null) {
            $sub->setExternalId($externalId);
        }
        if ($errorMessage !== null) {
            $sub->setErrorMessage($errorMessage);
        }

        // Override timestamps via reflection since there are no setters
        $ref = new \ReflectionClass($sub);
        $createdProp = $ref->getProperty('createdAt');
        $createdProp->setValue($sub, $timestamp);
        $updatedProp = $ref->getProperty('updatedAt');
        $updatedProp->setValue($sub, $timestamp);

        return $sub;
    }

    public function getDependencies(): array
    {
        return [
            InvoiceFixtures::class,
        ];
    }
}
