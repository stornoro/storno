<?php

namespace App\Entity;

use App\Enum\OrganizationRole;
use App\Repository\OrganizationMembershipRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrganizationMembershipRepository::class)]
#[ORM\UniqueConstraint(name: 'user_organization_unique', columns: ['user_id', 'organization_id'])]
class OrganizationMembership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'organizationMemberships')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 20, enumType: OrganizationRole::class)]
    private OrganizationRole $role = OrganizationRole::EMPLOYEE;

    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $joinedAt;

    #[ORM\ManyToMany(targetEntity: Company::class)]
    #[ORM\JoinTable(name: 'membership_allowed_companies')]
    private Collection $allowedCompanies;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->joinedAt = new \DateTimeImmutable();
        $this->allowedCompanies = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getRole(): OrganizationRole
    {
        return $this->role;
    }

    public function setRole(OrganizationRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function setPermissions(array $permissions): static
    {
        $this->permissions = $permissions;

        return $this;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(\DateTimeImmutable $joinedAt): static
    {
        $this->joinedAt = $joinedAt;

        return $this;
    }

    /**
     * @return Collection<int, Company>
     */
    public function getAllowedCompanies(): Collection
    {
        return $this->allowedCompanies;
    }

    public function addAllowedCompany(Company $company): static
    {
        if (!$this->allowedCompanies->contains($company)) {
            $this->allowedCompanies->add($company);
        }

        return $this;
    }

    public function removeAllowedCompany(Company $company): static
    {
        $this->allowedCompanies->removeElement($company);

        return $this;
    }

    public function clearAllowedCompanies(): static
    {
        $this->allowedCompanies->clear();

        return $this;
    }

    public function hasAccessToAllCompanies(): bool
    {
        return $this->allowedCompanies->isEmpty();
    }

    public function hasAccessToCompany(Company $company): bool
    {
        if ($this->allowedCompanies->isEmpty()) {
            return true;
        }

        return $this->allowedCompanies->contains($company);
    }
}
