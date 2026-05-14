<?php

namespace App\Entity;

use App\Repository\SiteUpdateRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteUpdateRequestRepository::class)]
#[ORM\Table(name: 'site_update_request')]
#[ORM\Index(name: 'idx_site_update_status', columns: ['status'])]
class SiteUpdateRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\Column(length: 255)]
    private string $siteName;

    #[ORM\Column(nullable: true)]
    private ?float $newCapacity = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $newSupportType = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $upgradeDate = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $decisionComment = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTicket(): ?Ticket { return $this->ticket; }
    public function setTicket(?Ticket $ticket): self { $this->ticket = $ticket; return $this; }

    public function getSiteName(): string { return $this->siteName; }
    public function setSiteName(string $siteName): self { $this->siteName = $siteName; return $this; }

    public function getNewCapacity(): ?float { return $this->newCapacity; }
    public function setNewCapacity(?float $newCapacity): self { $this->newCapacity = $newCapacity; return $this; }

    public function getNewSupportType(): ?string { return $this->newSupportType; }
    public function setNewSupportType(?string $newSupportType): self { $this->newSupportType = $newSupportType; return $this; }

    public function getUpgradeDate(): ?\DateTimeInterface { return $this->upgradeDate; }
    public function setUpgradeDate(?\DateTimeInterface $upgradeDate): self { $this->upgradeDate = $upgradeDate; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getRequestedBy(): ?User { return $this->requestedBy; }
    public function setRequestedBy(?User $requestedBy): self { $this->requestedBy = $requestedBy; return $this; }

    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function setDecidedBy(?User $decidedBy): self { $this->decidedBy = $decidedBy; return $this; }

    public function getDecisionComment(): ?string { return $this->decisionComment; }
    public function setDecisionComment(?string $decisionComment): self { $this->decisionComment = $decisionComment; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function setDecidedAt(?\DateTimeImmutable $decidedAt): self { $this->decidedAt = $decidedAt; return $this; }
}

