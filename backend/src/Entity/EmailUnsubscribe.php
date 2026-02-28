<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\EmailUnsubscribeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EmailUnsubscribeRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_email_company', columns: ['email', 'company_id'])]
class EmailUnsubscribe
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Company $company = null;

    #[ORM\Column]
    private \DateTimeImmutable $unsubscribedAt;

    #[ORM\Column(length: 50)]
    private string $category;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->unsubscribedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getUnsubscribedAt(): \DateTimeImmutable
    {
        return $this->unsubscribedAt;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }
}
