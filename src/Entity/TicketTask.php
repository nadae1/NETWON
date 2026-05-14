<?php
namespace App\Entity;

use App\Repository\TicketTaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketTaskRepository::class)]
class TicketTask
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_BLOCKED = 'blocked';

    public const STEP_ENGINEERING_IP = 'engineering_ip';
    public const STEP_ANALYSE_COMPLEMENTAIRE = 'analyse_complementaire';
    public const STEP_CAPILLAIRE_FO = 'capillaire_fo';
    public const STEP_DEPLOIEMENT_FO = 'deploiement_fo';
    public const STEP_VALIDATION_FO = 'validation_fo';
    public const STEP_EXECUTION_SITE = 'execution_site';
    public const STEP_VALIDATION_FINALE = 'validation_finale';
    public const STEP_FERMETURE = 'fermeture_ticket';
    public const STEP_VERIFICATION_KPI = 'verification_kpi';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $assignedTo = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $serviceName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $departmentName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stepCode = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $decision = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private bool $requiresCapture = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $capturePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $proofFile = null;

    #[ORM\Column(type: 'integer')]
    private int $stepOrder = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cardType = null;

    #[ORM\Column(nullable: true)]
    private ?float $measuredCapacity = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ipDecision = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $woIpReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteValidated = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $nextAssignedTo = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    // Getters / Setters (générés automatiquement, inclus ci-dessous)
    public function getId(): ?int { return $this->id; }
    public function getTicket(): ?Ticket { return $this->ticket; }
    public function setTicket(?Ticket $ticket): static { $this->ticket = $ticket; return $this; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function setAssignedTo(?User $assignedTo): static { $this->assignedTo = $assignedTo; return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getServiceName(): ?string { return $this->serviceName; }
    public function setServiceName(?string $serviceName): static { $this->serviceName = $serviceName; return $this; }
    public function getDepartmentName(): ?string { return $this->departmentName; }
    public function setDepartmentName(?string $departmentName): static { $this->departmentName = $departmentName; return $this; }
    public function getStepCode(): ?string { return $this->stepCode; }
    public function setStepCode(?string $stepCode): static { $this->stepCode = $stepCode; return $this; }
    public function getDecision(): ?string { return $this->decision; }
    public function setDecision(?string $decision): static { $this->decision = $decision; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }
    public function isRequiresCapture(): bool { return $this->requiresCapture; }
    public function setRequiresCapture(bool $requiresCapture): static { $this->requiresCapture = $requiresCapture; return $this; }
    public function getCapturePath(): ?string { return $this->capturePath; }
    public function setCapturePath(?string $capturePath): static { $this->capturePath = $capturePath; return $this; }
    public function getProofFile(): ?string { return $this->proofFile; }
    public function setProofFile(?string $proofFile): static { $this->proofFile = $proofFile; return $this; }
    public function getStepOrder(): int { return $this->stepOrder; }
    public function setStepOrder(int $stepOrder): static { $this->stepOrder = $stepOrder; return $this; }
    public function getCompletedAt(): ?\DateTimeInterface { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeInterface $completedAt): static { $this->completedAt = $completedAt; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
    public function getCardType(): ?string { return $this->cardType; }
    public function setCardType(?string $cardType): static { $this->cardType = $cardType; return $this; }
    public function getMeasuredCapacity(): ?float { return $this->measuredCapacity; }
    public function setMeasuredCapacity(?float $measuredCapacity): static { $this->measuredCapacity = $measuredCapacity; return $this; }
    public function getIpDecision(): ?string { return $this->ipDecision; }
    public function setIpDecision(?string $ipDecision): static { $this->ipDecision = $ipDecision; return $this; }
    public function getWoIpReference(): ?string { return $this->woIpReference; }
    public function setWoIpReference(?string $woIpReference): static { $this->woIpReference = $woIpReference; return $this; }
    public function getSiteValidated(): ?string { return $this->siteValidated; }
    public function setSiteValidated(?string $siteValidated): static { $this->siteValidated = $siteValidated; return $this; }
    public function getStartedAt(): ?\DateTimeInterface { return $this->startedAt; }
    public function setStartedAt(?\DateTimeInterface $startedAt): static { $this->startedAt = $startedAt; return $this; }
    public function getNextAssignedTo(): ?User { return $this->nextAssignedTo; }
    public function setNextAssignedTo(?User $nextAssignedTo): static { $this->nextAssignedTo = $nextAssignedTo; return $this; }
}