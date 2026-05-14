<?php

namespace App\Entity;

use App\Repository\AiRecommendationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AiRecommendationRepository::class)]
#[ORM\Table(name: 'ai_recommendation')]
#[ORM\Index(name: 'idx_ai_rec_status', columns: ['status'])]
#[ORM\Index(name: 'idx_ai_rec_service', columns: ['assigned_service'])]
class AiRecommendation
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProcessedSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProcessedSite $processedSite = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 80)]
    private string $actionType = 'MONITORING';

    #[ORM\Column(length: 50)]
    private string $assignedService = 'SHARED';

    #[ORM\Column(length: 20)]
    private string $priority = 'medium';

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deadlineAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $decisionComment = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getProcessedSite(): ?ProcessedSite { return $this->processedSite; }
    public function setProcessedSite(?ProcessedSite $processedSite): self { $this->processedSite = $processedSite; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getActionType(): string { return $this->actionType; }
    public function setActionType(string $actionType): self { $this->actionType = $actionType; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getAssignedService(): string { return $this->assignedService; }
    public function setAssignedService(string $assignedService): self { $this->assignedService = $assignedService; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $priority): self { $this->priority = $priority; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getDeadlineAt(): ?\DateTimeInterface { return $this->deadlineAt; }
    public function setDeadlineAt(?\DateTimeInterface $deadlineAt): self { $this->deadlineAt = $deadlineAt; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function setDecidedBy(?User $decidedBy): self { $this->decidedBy = $decidedBy; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getDecisionComment(): ?string { return $this->decisionComment; }
    public function setDecisionComment(?string $decisionComment): self { $this->decisionComment = $decisionComment; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}

