<?php

namespace App\Tests\Unit;

use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use PHPUnit\Framework\TestCase;

class TaxDeclarationEntityTest extends TestCase
{
    public function testPdfPathGetterSetterDefaultsToNull(): void
    {
        $declaration = new TaxDeclaration();
        $this->assertNull($declaration->getPdfPath());
    }

    public function testPdfPathCanBeSet(): void
    {
        $declaration = new TaxDeclaration();
        $result = $declaration->setPdfPath('declarations/uuid/d394/uuid.pdf');

        $this->assertSame('declarations/uuid/d394/uuid.pdf', $declaration->getPdfPath());
        $this->assertSame($declaration, $result, 'setPdfPath should return $this for chaining');
    }

    public function testPdfPathCanBeSetToNull(): void
    {
        $declaration = new TaxDeclaration();
        $declaration->setPdfPath('some/path.pdf');
        $declaration->setPdfPath(null);

        $this->assertNull($declaration->getPdfPath());
    }

    public function testXmlPathStillWorks(): void
    {
        $declaration = new TaxDeclaration();
        $declaration->setXmlPath('declarations/uuid/d394/uuid.xml');

        $this->assertSame('declarations/uuid/d394/uuid.xml', $declaration->getXmlPath());
        $this->assertNull($declaration->getPdfPath());
    }

    public function testPdfPathAndXmlPathAreIndependent(): void
    {
        $declaration = new TaxDeclaration();
        $declaration->setXmlPath('path/to/xml');
        $declaration->setPdfPath('path/to/pdf');

        $this->assertSame('path/to/xml', $declaration->getXmlPath());
        $this->assertSame('path/to/pdf', $declaration->getPdfPath());
    }

    public function testStatusSetterUpdatesTimestamps(): void
    {
        $declaration = new TaxDeclaration();

        $declaration->setStatus(DeclarationStatus::SUBMITTED);
        $this->assertNotNull($declaration->getSubmittedAt());

        $declaration->setStatus(DeclarationStatus::ACCEPTED);
        $this->assertNotNull($declaration->getAcceptedAt());
    }

    public function testRecipisaPathStillWorks(): void
    {
        $declaration = new TaxDeclaration();
        $declaration->setRecipisaPath('declarations/uuid/d394/uuid_recipisa.pdf');

        $this->assertSame('declarations/uuid/d394/uuid_recipisa.pdf', $declaration->getRecipisaPath());
    }
}
