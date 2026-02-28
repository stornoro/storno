<?php

namespace App\Entity;

use App\Enum\EmailStatus;
use App\Repository\EmailLogRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\Index(name: 'idx_emaillog_company_sent', columns: ['company_id', 'sent_at'])]
#[ORM\Index(name: 'idx_emaillog_invoice_sent', columns: ['invoice_id', 'sent_at'])]
#[ORM\Index(name: 'idx_emaillog_ses_msg_id', columns: ['ses_message_id'])]
class EmailLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?DeliveryNote $deliveryNote = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Receipt $receipt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Company $company = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private ?string $category = null;

    #[ORM\Column(length: 255)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private ?string $toEmail = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['email_log:detail'])]
    private ?array $ccEmails = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['email_log:detail'])]
    private ?array $bccEmails = null;

    #[ORM\Column(length: 500)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private ?string $subject = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(length: 20, enumType: EmailStatus::class)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private EmailStatus $status = EmailStatus::SENT;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['email_log:detail'])]
    private ?string $templateUsed = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $sentBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['email_log:detail'])]
    private ?string $errorMessage = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private ?string $sesMessageId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private ?string $fromEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['email_log:list', 'email_log:detail'])]
    private ?string $fromName = null;

    /**
     * @var Collection<int, EmailEvent>
     */
    #[ORM\OneToMany(mappedBy: 'emailLog', targetEntity: EmailEvent::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['timestamp' => 'ASC'])]
    #[Groups(['email_log:detail'])]
    private Collection $events;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->sentAt = new \DateTimeImmutable();
        $this->events = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getDeliveryNote(): ?DeliveryNote
    {
        return $this->deliveryNote;
    }

    public function setDeliveryNote(?DeliveryNote $deliveryNote): static
    {
        $this->deliveryNote = $deliveryNote;

        return $this;
    }

    public function getReceipt(): ?Receipt
    {
        return $this->receipt;
    }

    public function setReceipt(?Receipt $receipt): static
    {
        $this->receipt = $receipt;

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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getToEmail(): ?string
    {
        return $this->toEmail;
    }

    public function setToEmail(string $toEmail): static
    {
        $this->toEmail = $toEmail;

        return $this;
    }

    public function getCcEmails(): ?array
    {
        return $this->ccEmails;
    }

    public function setCcEmails(?array $ccEmails): static
    {
        $this->ccEmails = $ccEmails;

        return $this;
    }

    public function getBccEmails(): ?array
    {
        return $this->bccEmails;
    }

    public function setBccEmails(?array $bccEmails): static
    {
        $this->bccEmails = $bccEmails;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getStatus(): EmailStatus
    {
        return $this->status;
    }

    public function setStatus(EmailStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTemplateUsed(): ?string
    {
        return $this->templateUsed;
    }

    public function setTemplateUsed(?string $templateUsed): static
    {
        $this->templateUsed = $templateUsed;

        return $this;
    }

    public function getSentBy(): ?User
    {
        return $this->sentBy;
    }

    public function setSentBy(?User $sentBy): static
    {
        $this->sentBy = $sentBy;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getSesMessageId(): ?string
    {
        return $this->sesMessageId;
    }

    public function setSesMessageId(?string $sesMessageId): static
    {
        $this->sesMessageId = $sesMessageId;

        return $this;
    }

    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(?string $fromEmail): static
    {
        $this->fromEmail = $fromEmail;

        return $this;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): static
    {
        $this->fromName = $fromName;

        return $this;
    }

    /**
     * @return Collection<int, EmailEvent>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(EmailEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setEmailLog($this);
        }

        return $this;
    }

    // Virtual getters for serialization

    #[Groups(['email_log:list', 'email_log:detail'])]
    #[SerializedName('invoiceId')]
    public function getInvoiceId(): ?string
    {
        return $this->invoice?->getId()?->toRfc4122();
    }

    #[Groups(['email_log:list', 'email_log:detail'])]
    #[SerializedName('invoiceNumber')]
    public function getInvoiceNumber(): ?string
    {
        return $this->invoice?->getNumber();
    }

    #[Groups(['email_log:list', 'email_log:detail'])]
    #[SerializedName('sentByEmail')]
    public function getSentByEmail(): ?string
    {
        return $this->sentBy?->getEmail();
    }

    #[Groups(['email_log:list', 'email_log:detail'])]
    #[SerializedName('deliveryNoteId')]
    public function getDeliveryNoteId(): ?string
    {
        return $this->deliveryNote?->getId()?->toRfc4122();
    }

    #[Groups(['email_log:list', 'email_log:detail'])]
    #[SerializedName('deliveryNoteNumber')]
    public function getDeliveryNoteNumber(): ?string
    {
        return $this->deliveryNote?->getNumber();
    }
}
