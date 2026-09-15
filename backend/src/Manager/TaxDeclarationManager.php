<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\TaxDeclaration;
use App\Entity\User;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Event\Declaration\DeclarationCreatedEvent;
use App\Message\Declaration\RefreshDeclarationStatusesMessage;
use App\Message\Declaration\CheckDeclarationStatusMessage;
use App\Message\Declaration\SubmitDeclarationMessage;
use App\Message\Declaration\SyncDeclarationsMessage;
use App\Repository\TaxDeclarationRepository;
use App\Service\Declaration\DeclarationDataPopulatorInterface;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;
use App\Service\Declaration\DeclarationNamespaceResolver;
use App\Service\Declaration\DeclarationValidator;
use App\Service\Declaration\DukIntegratorService;
use App\Service\Declaration\DukUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TaxDeclarationManager
{
    /** @var DeclarationDataPopulatorInterface[] */
    private array $populators = [];

    /** @var DeclarationXmlGeneratorInterface[] */
    private array $generators = [];

    public function __construct(
        private readonly TaxDeclarationRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DukIntegratorService $dukIntegrator,
        private readonly DeclarationValidator $validator,
        private readonly DeclarationNamespaceResolver $namespaces,
        private readonly \League\Flysystem\FilesystemOperator $defaultStorage,
        #[TaggedIterator('app.declaration_data_populator')]
        iterable $dataPopulators,
        #[TaggedIterator('app.declaration_xml_generator')]
        iterable $xmlGenerators,
    ) {
        foreach ($dataPopulators as $populator) {
            $this->populators[] = $populator;
        }
        foreach ($xmlGenerators as $generator) {
            $this->generators[] = $generator;
        }
    }

    private function findPopulator(string $type): ?DeclarationDataPopulatorInterface
    {
        foreach ($this->populators as $populator) {
            if ($populator->supportsType($type)) {
                return $populator;
            }
        }

        return null;
    }

    private function findGenerator(string $type): ?DeclarationXmlGeneratorInterface
    {
        foreach ($this->generators as $generator) {
            if ($generator->supportsType($type)) {
                return $generator;
            }
        }

        return null;
    }

    public function find(string $uuid): ?TaxDeclaration
    {
        return $this->repository->find(Uuid::fromString($uuid));
    }

    public function listByCompany(Company $company, array $filters = [], int $page = 1, int $limit = 10): array
    {
        return $this->repository->findByCompanyPaginated($company, $filters, $page, $limit);
    }

    public function create(Company $company, array $data, User $user): TaxDeclaration
    {
        $type = DeclarationType::from($data['type']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $periodType = $data['periodType'] ?? $type->periodType();

        $declaration = new TaxDeclaration();
        $declaration->setCompany($company);
        $declaration->setType($type);
        $declaration->setYear($year);
        $declaration->setMonth($month);
        $declaration->setPeriodType($periodType);
        $declaration->setStatus(DeclarationStatus::DRAFT);
        $declaration->setCreatedBy($user);
        $declaration->setCreatedAt(new \DateTimeImmutable());

        // Auto-populate data if populator exists
        $populator = $this->findPopulator($type->value);
        if ($populator) {
            $populatedData = $populator->populate($company, $year, $month, $periodType);
            $declaration->setData($populatedData);
        }

        $this->entityManager->persist($declaration);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new DeclarationCreatedEvent($declaration));

        return $declaration;
    }

    /**
     * The declaration was filed outside Storno (portal by hand, another program, or at the
     * counter): remember ANAF's number and follow its state on StareD112 like any other filing.
     * `index` is the online upload index, or the registration number from the counter with `ghiseu: true`.
     * @param array{index?: string, ghiseu?: bool} $filing
     */
    public function recordExternalFiling(TaxDeclaration $declaration, array $filing): TaxDeclaration
    {
        // "INTERNT-1216000000-2026" is how ANAF prints the upload index; only the middle number identifies the filing
        $raw = trim((string) ($filing['index'] ?? ''));
        $index = preg_match('/INTERNT-(\d+)-\d{4}/i', $raw, $m) ? $m[1] : (preg_replace('/\D/', '', $raw) ?? '');
        if ($index === '') {
            throw new \InvalidArgumentException('filedExternally.index: numarul de inregistrare (index de incarcare sau numar de la ghiseu) este obligatoriu.');
        }
        if (in_array($declaration->getStatus(), [DeclarationStatus::ACCEPTED, DeclarationStatus::PROCESSING, DeclarationStatus::SUBMITTED], true)) {
            throw new \InvalidArgumentException('Declaratia este deja depusa si urmarita.');
        }
        $declaration->setAnafUploadId($index);
        $declaration->setStatus(DeclarationStatus::SUBMITTED);
        $declaration->setErrorMessage(null);
        $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
            'filedExternally' => true,
            'ghiseu' => ($filing['ghiseu'] ?? false) === true,
        ]));
        $declaration->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        // the portal needs a little while to index the number
        $this->messageBus->dispatch(new CheckDeclarationStatusMessage(declarationId: (string) $declaration->getId()), [new DelayStamp(120_000)]);

        return $declaration;
    }

    public function update(TaxDeclaration $declaration, array $data): TaxDeclaration
    {
        if (isset($data['filedExternally']) && is_array($data['filedExternally'])) {
            return $this->recordExternalFiling($declaration, $data['filedExternally']);
        }
        if ($declaration->getStatus() !== DeclarationStatus::DRAFT) {
            throw new \InvalidArgumentException('Only draft declarations can be edited.');
        }

        if (isset($data['data'])) {
            // Clients read attachments without their content (see TaxDeclaration::getDataForApi)
            // and may send the same shape back: keep the stored files instead of blanking them.
            $declaration->setData(is_array($data['data']) ? TaxDeclaration::mergeAttachments($declaration->getData(), $data['data']) : $data['data']);
        }

        if (isset($data['metadata'])) {
            $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], $data['metadata']));
        }

        $declaration->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $declaration;
    }

    public function recalculate(TaxDeclaration $declaration): TaxDeclaration
    {
        if ($declaration->getStatus() !== DeclarationStatus::DRAFT) {
            throw new \InvalidArgumentException('Only draft declarations can be recalculated.');
        }

        $populator = $this->findPopulator($declaration->getType()->value);
        if ($populator === null) {
            throw new \InvalidArgumentException(sprintf('No populator found for type: %s', $declaration->getType()->value));
        }

        $data = $populator->populate(
            $declaration->getCompany(),
            $declaration->getYear(),
            $declaration->getMonth(),
            $declaration->getPeriodType()
        );

        $declaration->setData($data);
        $declaration->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $declaration;
    }

    public function validate(TaxDeclaration $declaration): TaxDeclaration
    {
        if ($declaration->getStatus() !== DeclarationStatus::DRAFT) {
            throw new \InvalidArgumentException('Only draft declarations can be validated.');
        }

        $generator = $this->findGenerator($declaration->getType()->value);
        if ($generator === null) {
            throw new \InvalidArgumentException(sprintf('No XML generator found for type: %s', $declaration->getType()->value));
        }

        // Generate XML to validate it (namespace from metadata override or the shipped XSD)
        $xml = $this->generateXml($declaration);

        // Basic XML validation
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new \RuntimeException('Generated XML is not valid.');
        }

        // ANAF-grade validation through DUKIntegrator. Mandatory: a declaration is
        // never marked validated on a syntax check alone.
        try {
            $outcome = $this->validator->validate($xml, $declaration->getType()->value);
        } catch (DukUnavailableException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if ($outcome->namespaceCorrected && $outcome->namespace !== null) {
            // Remember the namespace ANAF asked for, so prepare()/download produce the same document.
            $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], ['xmlns' => $outcome->namespace]));
        }

        if (!$outcome->valid) {
            $this->entityManager->flush();
            throw new \RuntimeException(sprintf('DUK validation failed: %s', implode('; ', $outcome->errors)));
        }

        $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
            'dukValidation' => [
                'valid' => true,
                'namespace' => $outcome->namespace,
                'warnings' => $outcome->warnings,
                'validatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        ]));

        $declaration->setStatus(DeclarationStatus::VALIDATED);
        $this->entityManager->flush();

        return $declaration;
    }

    public function submit(TaxDeclaration $declaration): void
    {
        if (!in_array($declaration->getStatus(), [DeclarationStatus::DRAFT, DeclarationStatus::VALIDATED], true)) {
            throw new \InvalidArgumentException('Only draft or validated declarations can be submitted.');
        }

        // Prevent duplicate: check if another declaration for the same type+period is already in-flight
        $existing = $this->repository->findExisting(
            $declaration->getCompany(),
            $declaration->getType(),
            $declaration->getYear(),
            $declaration->getMonth()
        );
        if ($existing !== null && $existing->getId() !== $declaration->getId()
            && in_array($existing->getStatus(), [DeclarationStatus::SUBMITTED, DeclarationStatus::PROCESSING], true)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'A %s declaration for %d-%02d is already submitted and awaiting response.',
                $declaration->getType()->value,
                $declaration->getYear(),
                $declaration->getMonth()
            ));
        }

        $declaration->setStatus(DeclarationStatus::SUBMITTED);
        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new SubmitDeclarationMessage(
                declarationId: (string) $declaration->getId(),
            )
        );
    }

    public function createFromXml(Company $company, string $xmlContent, User $user, string $source = 'xml_upload'): TaxDeclaration
    {
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xmlContent)) {
            throw new \InvalidArgumentException('Invalid XML content.');
        }

        $rootName = $doc->documentElement->localName ?? $doc->documentElement->nodeName;

        // Extract type from root element name (e.g., declaratie394 → d394)
        if (preg_match('/^declaratie(\d+)$/i', $rootName, $matches)) {
            $typeValue = 'd' . $matches[1];
        } elseif (strtolower($rootName) === 'declaratieunica') {
            $typeValue = 'd112';
        } elseif (strtolower($rootName) === 'c168') {
            $typeValue = 'c168';
        } else {
            throw new \InvalidArgumentException(sprintf('Cannot determine declaration type from root element: %s', $rootName));
        }

        $type = DeclarationType::tryFrom($typeValue);
        if ($type === null) {
            throw new \InvalidArgumentException(sprintf('Unknown declaration type: %s', $typeValue));
        }

        $root = $doc->documentElement;
        $year = (int) ($root->getAttribute('an') ?: date('Y'));
        $month = (int) ($root->getAttribute('luna') ?: 1);

        $declaration = new TaxDeclaration();
        $declaration->setCompany($company);
        $declaration->setType($type);
        $declaration->setYear($year);
        $declaration->setMonth($month);
        $declaration->setPeriodType($type->periodType());
        $declaration->setStatus(DeclarationStatus::DRAFT);
        $declaration->setCreatedBy($user);
        $declaration->setCreatedAt(new \DateTimeImmutable());

        // Extract row data from XML attributes for display
        $rows = [];
        foreach ($root->attributes as $attr) {
            $rows[$attr->name] = $attr->value;
        }
        $declaration->setData(['rows' => $rows, 'uploadedXml' => true]);
        // the document itself is what gets validated and filed, exactly as it came in
        $xmlPath = sprintf('declarations/%s/%s/%s.xml', $company->getId(), $type->value, $declaration->getId());
        $this->defaultStorage->write($xmlPath, $xmlContent);
        $declaration->setXmlPath($xmlPath);
        $declaration->setMetadata(['source' => $source, 'externalXml' => true]);

        $this->entityManager->persist($declaration);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new DeclarationCreatedEvent($declaration));

        return $declaration;
    }

    public function bulkSubmit(array $declarations): int
    {
        $count = 0;
        foreach ($declarations as $declaration) {
            if (!in_array($declaration->getStatus(), [DeclarationStatus::DRAFT, DeclarationStatus::VALIDATED], true)) {
                continue;
            }

            $declaration->setStatus(DeclarationStatus::SUBMITTED);

            $this->messageBus->dispatch(
                new SubmitDeclarationMessage(
                    declarationId: (string) $declaration->getId(),
                )
            );

            $count++;
        }

        $this->entityManager->flush();

        return $count;
    }

    public function delete(TaxDeclaration $declaration, ?User $user = null): void
    {
        $declaration->softDelete($user);
        $this->entityManager->flush();
    }

    public function syncFromAnaf(Company $company, int $year): void
    {
        $this->messageBus->dispatch(
            new SyncDeclarationsMessage(
                companyId: (string) $company->getId(),
                year: $year,
            )
        );
    }

    public function refreshStatuses(Company $company): void
    {
        $this->messageBus->dispatch(
            new RefreshDeclarationStatusesMessage(
                companyId: (string) $company->getId(),
            )
        );
    }

    public function generateXml(TaxDeclaration $declaration): string
    {
        // uploaded (XML or PDF from another program): the stored document, never a regeneration
        if (($declaration->getMetadata()['externalXml'] ?? false) === true && $declaration->getXmlPath() !== null && $this->defaultStorage->fileExists($declaration->getXmlPath())) {
            $stored = $this->defaultStorage->read($declaration->getXmlPath());
            $namespace = $declaration->getMetadata()['xmlns'] ?? $this->namespaces->fromXml($stored) ?? $this->namespaces->fromXsd($declaration->getType()->value);

            return $this->namespaces->apply($stored, is_string($namespace) ? $namespace : null);
        }

        $generator = $this->findGenerator($declaration->getType()->value);
        if ($generator === null) {
            throw new \InvalidArgumentException(sprintf('No XML generator found for type: %s', $declaration->getType()->value));
        }

        $xml = $generator->generate($declaration);

        // ANAF XSDs are namespace-qualified; generators emit unqualified XML.
        $namespace = $declaration->getMetadata()['xmlns'] ?? $this->namespaces->fromXsd($declaration->getType()->value);

        return $this->namespaces->apply($xml, is_string($namespace) ? $namespace : null);
    }
}
