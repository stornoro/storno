<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\Traits\SoftDeletableTrait;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 10)]
    private string $locale = 'ro';

    #[ORM\Column(length: 50)]
    private string $timezone = 'Europe/Bucharest';

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(nullable: true, length: 255)]
    #[Ignore]
    private ?string $password = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private ?bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastConnectedAt = null;

    #[ORM\Column]
    private ?bool $emailVerified = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $microsoftId = null;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: OrganizationMembership::class, cascade: ['persist', 'remove'])]
    private Collection $organizationMemberships;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserPasskey::class, cascade: ['persist', 'remove'])]
    private Collection $passkeys;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: LoginHistory::class)]
    private Collection $loginHistories;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ApiToken::class, cascade: ['remove'])]
    private Collection $apiTokens;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Notification::class, cascade: ['remove'])]
    private Collection $notifications;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: AnafToken::class, cascade: ['persist', 'remove'])]
    private Collection $anafTokens;

    #[ORM\OneToOne(inversedBy: 'user', cascade: ['persist', 'remove'])]
    private ?UserBilling $userBilling = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: UserTotpSecret::class, cascade: ['persist', 'remove'])]
    private ?UserTotpSecret $totpSecret = null;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserBackupCode::class, cascade: ['persist', 'remove'])]
    private Collection $backupCodes;

    #[ORM\Column]
    private ?bool $production = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $telegramChatId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $preferences = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $avatarPath = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->organizationMemberships = new ArrayCollection();
        $this->passkeys = new ArrayCollection();
        $this->loginHistories = new ArrayCollection();
        $this->apiTokens = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->anafTokens = new ArrayCollection();
        $this->backupCodes = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!$this->createdAt) {
            $this->createdAt = new \DateTimeImmutable();
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? '')) ?: $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function addRole(string $role): static
    {
        $this->roles[] = $role;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function activate(bool $status): static
    {
        $this->active = $status;

        return $this;
    }

    public function getLastConnectedAt(): ?\DateTimeImmutable
    {
        return $this->lastConnectedAt;
    }

    public function setLastConnectedAt(?\DateTimeImmutable $lastConnectedAt): static
    {
        $this->lastConnectedAt = $lastConnectedAt;

        return $this;
    }

    public function isEmailVerified(): ?bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): static
    {
        $this->emailVerified = $emailVerified;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getAppleId(): ?string
    {
        return $this->appleId;
    }

    public function setAppleId(?string $appleId): static
    {
        $this->appleId = $appleId;

        return $this;
    }

    public function getMicrosoftId(): ?string
    {
        return $this->microsoftId;
    }

    public function setMicrosoftId(?string $microsoftId): static
    {
        $this->microsoftId = $microsoftId;

        return $this;
    }

    /**
     * @return Collection<int, OrganizationMembership>
     */
    public function getOrganizationMemberships(): Collection
    {
        return $this->organizationMemberships;
    }

    public function addOrganizationMembership(OrganizationMembership $membership): static
    {
        if (!$this->organizationMemberships->contains($membership)) {
            $this->organizationMemberships->add($membership);
            $membership->setUser($this);
        }

        return $this;
    }

    public function removeOrganizationMembership(OrganizationMembership $membership): static
    {
        if ($this->organizationMemberships->removeElement($membership)) {
            if ($membership->getUser() === $this) {
                $membership->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, UserPasskey>
     */
    public function getPasskeys(): Collection
    {
        return $this->passkeys;
    }

    public function addPasskey(UserPasskey $passkey): static
    {
        if (!$this->passkeys->contains($passkey)) {
            $this->passkeys->add($passkey);
            $passkey->setUser($this);
        }
        return $this;
    }

    public function removePasskey(UserPasskey $passkey): static
    {
        if ($this->passkeys->removeElement($passkey)) {
            if ($passkey->getUser() === $this) {
                $passkey->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, LoginHistory>
     */
    public function getLoginHistories(): Collection
    {
        return $this->loginHistories;
    }

    public function addLoginHistory(LoginHistory $loginHistory): static
    {
        if (!$this->loginHistories->contains($loginHistory)) {
            $this->loginHistories->add($loginHistory);
            $loginHistory->setUser($this);
        }

        return $this;
    }

    public function removeLoginHistory(LoginHistory $loginHistory): static
    {
        if ($this->loginHistories->removeElement($loginHistory)) {
            if ($loginHistory->getUser() === $this) {
                $loginHistory->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ApiToken>
     */
    public function getApiTokens(): Collection
    {
        return $this->apiTokens;
    }

    public function addApiToken(ApiToken $apiToken): static
    {
        if (!$this->apiTokens->contains($apiToken)) {
            $this->apiTokens->add($apiToken);
            $apiToken->setUser($this);
        }

        return $this;
    }

    public function removeApiToken(ApiToken $apiToken): static
    {
        if ($this->apiTokens->removeElement($apiToken)) {
            if ($apiToken->getUser() === $this) {
                $apiToken->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        return $this->notifications;
    }

    public function addNotification(Notification $notification): static
    {
        if (!$this->notifications->contains($notification)) {
            $this->notifications->add($notification);
            $notification->setUser($this);
        }

        return $this;
    }

    public function removeNotification(Notification $notification): static
    {
        if ($this->notifications->removeElement($notification)) {
            if ($notification->getUser() === $this) {
                $notification->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, AnafToken>
     */
    public function getAnafTokens(): Collection
    {
        return $this->anafTokens;
    }

    public function addAnafToken(AnafToken $anafToken): static
    {
        if (!$this->anafTokens->contains($anafToken)) {
            $this->anafTokens->add($anafToken);
            $anafToken->setUser($this);
        }

        return $this;
    }

    public function removeAnafToken(AnafToken $anafToken): static
    {
        if ($this->anafTokens->removeElement($anafToken)) {
            if ($anafToken->getUser() === $this) {
                $anafToken->setUser(null);
            }
        }

        return $this;
    }

    public function getUserBilling(): ?UserBilling
    {
        return $this->userBilling;
    }

    public function setUserBilling(?UserBilling $userBilling): static
    {
        $this->userBilling = $userBilling;

        return $this;
    }

    public function isProduction(): ?bool
    {
        return $this->production;
    }

    public function setProduction(bool $production): static
    {
        $this->production = $production;

        return $this;
    }

    public function getTelegramChatId(): ?string
    {
        return $this->telegramChatId;
    }

    public function setTelegramChatId(?string $telegramChatId): static
    {
        $this->telegramChatId = $telegramChatId;

        return $this;
    }

    public function getPreferences(): ?array
    {
        return $this->preferences;
    }

    public function setPreferences(?array $preferences): static
    {
        $this->preferences = $preferences;

        return $this;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): static
    {
        $this->avatarPath = $avatarPath;

        return $this;
    }

    public function getTotpSecret(): ?UserTotpSecret
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?UserTotpSecret $totpSecret): static
    {
        if ($totpSecret !== null && $totpSecret->getUser() !== $this) {
            $totpSecret->setUser($this);
        }
        $this->totpSecret = $totpSecret;
        return $this;
    }

    /**
     * @return Collection<int, UserBackupCode>
     */
    public function getBackupCodes(): Collection
    {
        return $this->backupCodes;
    }

    public function addBackupCode(UserBackupCode $backupCode): static
    {
        if (!$this->backupCodes->contains($backupCode)) {
            $this->backupCodes->add($backupCode);
            $backupCode->setUser($this);
        }
        return $this;
    }

    public function removeBackupCode(UserBackupCode $backupCode): static
    {
        if ($this->backupCodes->removeElement($backupCode)) {
            if ($backupCode->getUser() === $this) {
                $backupCode->setUser(null);
            }
        }
        return $this;
    }

    public function isMfaEnabled(): bool
    {
        return $this->totpSecret !== null && $this->totpSecret->isVerified();
    }

    public function requiresMfa(): bool
    {
        return $this->isMfaEnabled() || $this->passkeys->count() > 0;
    }

    public function getAvailableMfaMethods(): array
    {
        $methods = [];
        if ($this->isMfaEnabled()) {
            $methods[] = 'totp';
        }
        if ($this->passkeys->count() > 0) {
            $methods[] = 'passkey';
        }
        return $methods;
    }
}
