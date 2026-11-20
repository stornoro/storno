<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Manager\TaxDeclarationManager;
use App\Model\Declaration\DukValidationResult;
use App\Repository\TaxDeclarationRepository;
use App\Service\Declaration\DeclarationNamespaceResolver;
use App\Service\Declaration\DeclarationValidator;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;
use App\Service\Declaration\DukIntegratorService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TaxDeclarationManagerValidateTest extends TestCase
{
    private function createManager(
        DukIntegratorService $dukService,
        ?DeclarationXmlGeneratorInterface $generator = null,
    ): TaxDeclarationManager {
        $repository = $this->createMock(TaxDeclarationRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $generators = $generator ? [$generator] : [];

        // The validator and the namespace resolver are final, so the real ones run
        // on top of the mocked DUK service.
        $namespaces = new DeclarationNamespaceResolver();

        return new TaxDeclarationManager(
            $repository,
            $entityManager,
            $messageBus,
            $eventDispatcher,
            $dukService,
            new DeclarationValidator($dukService, $namespaces, new NullLogger()),
            $namespaces,
            $this->createMock(FilesystemOperator::class),
            [], // populators
            $generators,
        );
    }

    private function createDeclaration(DeclarationStatus $status = DeclarationStatus::DRAFT): TaxDeclaration
    {
        $declaration = new TaxDeclaration();
        $declaration->setType(DeclarationType::from('d394'));
        $declaration->setYear(2026);
        $declaration->setMonth(1);
        $declaration->setStatus($status);

        return $declaration;
    }

    private function createGenerator(string $xml = '<declaratie394/>'): DeclarationXmlGeneratorInterface
    {
        $generator = $this->createMock(DeclarationXmlGeneratorInterface::class);
        $generator->method('supportsType')->willReturn(true);
        $generator->method('generate')->willReturn($xml);
        return $generator;
    }

    public function testValidateWithDukAvailableAndValid(): void
    {
        $dukService = $this->createMock(DukIntegratorService::class);
        $dukService->method('isAvailable')->willReturn(true);
        $dukService->method('validate')->willReturn(
            new DukValidationResult(valid: true, warnings: ['minor warning'])
        );

        $generator = $this->createGenerator();
        $manager = $this->createManager($dukService, $generator);
        $declaration = $this->createDeclaration();

        $result = $manager->validate($declaration);

        $this->assertSame(DeclarationStatus::VALIDATED, $result->getStatus());
        $metadata = $result->getMetadata();
        $this->assertArrayHasKey('dukValidation', $metadata);
        $this->assertTrue($metadata['dukValidation']['valid']);
        $this->assertContains('minor warning', $metadata['dukValidation']['warnings']);
    }

    public function testValidateWithDukAvailableAndInvalid(): void
    {
        $dukService = $this->createMock(DukIntegratorService::class);
        $dukService->method('isAvailable')->willReturn(true);
        $dukService->method('validate')->willReturn(
            new DukValidationResult(valid: false, errors: ['Missing CIF attribute'])
        );

        $generator = $this->createGenerator();
        $manager = $this->createManager($dukService, $generator);
        $declaration = $this->createDeclaration();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DUK validation failed: Missing CIF attribute');
        $manager->validate($declaration);
    }

    public function testValidateWithDukUnavailableIsRefused(): void
    {
        $dukService = $this->createMock(DukIntegratorService::class);
        $dukService->method('isAvailable')->willReturn(false);
        $dukService->expects($this->never())->method('validate');

        $generator = $this->createGenerator();
        $manager = $this->createManager($dukService, $generator);
        $declaration = $this->createDeclaration();

        // The ANAF validator is mandatory: a syntax check alone never marks a declaration validated.
        try {
            $manager->validate($declaration);
            $this->fail('validate() must throw when the DUK service is down.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('DUKIntegrator', $e->getMessage());
        }

        $this->assertSame(DeclarationStatus::DRAFT, $declaration->getStatus());
        $this->assertNull($declaration->getMetadata());
    }

    public function testValidateRejectsNonDraftStatus(): void
    {
        $dukService = $this->createMock(DukIntegratorService::class);
        $generator = $this->createGenerator();
        $manager = $this->createManager($dukService, $generator);
        $declaration = $this->createDeclaration(DeclarationStatus::VALIDATED);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only draft declarations can be validated');
        $manager->validate($declaration);
    }

    public function testValidateRejectsInvalidXml(): void
    {
        $dukService = $this->createMock(DukIntegratorService::class);
        $generator = $this->createMock(DeclarationXmlGeneratorInterface::class);
        $generator->method('supportsType')->willReturn(true);
        $generator->method('generate')->willReturn('not xml at all');

        $manager = $this->createManager($dukService, $generator);
        $declaration = $this->createDeclaration();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Generated XML is not valid');
        $manager->validate($declaration);
    }

    public function testValidateWithNoGeneratorThrows(): void
    {
        $dukService = $this->createMock(DukIntegratorService::class);
        // No generators passed
        $manager = $this->createManager($dukService);
        $declaration = $this->createDeclaration();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No XML generator found');
        $manager->validate($declaration);
    }
}
