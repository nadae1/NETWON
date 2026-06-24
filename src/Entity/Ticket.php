<?php
// src/Entity/Ticket.php

namespace App\Entity;

use App\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    private ?string $actionType = null;

    #[ORM\Column(length: 50)]
    private string $status = 'open';

    #[ORM\Column(length: 20)]
    private string $priority = 'medium';

    #[ORM\Column]
    private int $progress = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $currentStep = 1;

    #[ORM\Column(type: 'integer', options: ['default' => 8])]
    private int $totalSteps = 8;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $processedSites = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalSites = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sitesProgress = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $comments = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $proofUrls = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deadlineAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $validatedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $closedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketTask::class, cascade: ['persist', 'remove'])]
    private Collection $tasks;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketSite::class, cascade: ['persist', 'remove'])]
    private Collection $ticketSites;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketComment::class, cascade: ['persist', 'remove'])]
    private Collection $commentsList;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketHistory::class, cascade: ['persist', 'remove'])]
    private Collection $history;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $workflowType = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'ticket_assigned_users')]
    private Collection $assignedUsers;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deadline = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $notifiedLevels = null;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
        $this->ticketSites = new ArrayCollection();
        $this->commentsList = new ArrayCollection();
        $this->history = new ArrayCollection();
        $this->assignedUsers = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->currentStep = 1;
        $this->totalSteps = 8;
        $this->processedSites = 0;
        $this->totalSites = 0;
    }

    // === Getters / Setters ===

    public function getId(): ?int { return $this->id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getActionType(): ?string { return $this->actionType; }
    public function setActionType(string $actionType): static { $this->actionType = $actionType; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $priority): static { $this->priority = $priority; return $this; }

    public function getProgress(): int { return $this->progress; }
    public function setProgress(int $progress): static { $this->progress = $progress; return $this; }

    public function getCurrentStep(): int { return (int) $this->currentStep; }
    public function setCurrentStep(int $currentStep): static { $this->currentStep = $currentStep; return $this; }

    public function getTotalSteps(): int { return (int) $this->totalSteps; }
    public function setTotalSteps(int $totalSteps): static { $this->totalSteps = $totalSteps; return $this; }

    public function getProcessedSites(): int { return (int) $this->processedSites; }
    public function setProcessedSites(int $processedSites): static { $this->processedSites = $processedSites; return $this; }

    public function getTotalSites(): int { return (int) $this->totalSites; }
    public function setTotalSites(int $totalSites): static { $this->totalSites = $totalSites; return $this; }

    public function getSitesProgress(): ?array { return $this->sitesProgress; }
    public function setSitesProgress(?array $sitesProgress): static { $this->sitesProgress = $sitesProgress; return $this; }

    public function getComments(): ?array { return $this->comments; }
    public function setComments(?array $comments): static { $this->comments = $comments; return $this; }

    public function getProofUrls(): ?array { return $this->proofUrls; }
    public function setProofUrls(?array $proofUrls): static { $this->proofUrls = $proofUrls; return $this; }

    public function getDeadlineAt(): ?\DateTimeInterface { return $this->deadlineAt; }
    public function setDeadlineAt(?\DateTimeInterface $deadlineAt): static { $this->deadlineAt = $deadlineAt; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getStartedAt(): ?\DateTimeInterface { return $this->startedAt; }
    public function setStartedAt(?\DateTimeInterface $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getValidatedAt(): ?\DateTimeInterface { return $this->validatedAt; }
    public function setValidatedAt(?\DateTimeInterface $validatedAt): static { $this->validatedAt = $validatedAt; return $this; }

    public function getClosedAt(): ?\DateTimeInterface { return $this->closedAt; }
    public function setClosedAt(?\DateTimeInterface $closedAt): static { $this->closedAt = $closedAt; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getTasks(): Collection { return $this->tasks; }
    public function getTicketSites(): Collection { return $this->ticketSites; }
    public function getCommentsList(): Collection { return $this->commentsList; }
    public function getHistory(): Collection { return $this->history; }

    public function getAssignedUsers(): Collection { return $this->assignedUsers; }
    public function addAssignedUser(User $user): static { if (!$this->assignedUsers->contains($user)) $this->assignedUsers->add($user); return $this; }
    public function removeAssignedUser(User $user): static { $this->assignedUsers->removeElement($user); return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): static { $this->reference = $reference; return $this; }

    public function getWorkflowType(): ?string { return $this->workflowType; }
    public function setWorkflowType(?string $workflowType): static { $this->workflowType = $workflowType; return $this; }

    public function getDeadline(): ?\DateTimeInterface { return $this->deadline; }
    public function setDeadline(?\DateTimeInterface $deadline): static { $this->deadline = $deadline; return $this; }

    public function getNotifiedLevels(): ?array { return $this->notifiedLevels; }
    public function setNotifiedLevels(?array $notifiedLevels): static { $this->notifiedLevels = $notifiedLevels; return $this; }

    // Helpers

    public function getSiteName(): ?string
    {
        foreach ($this->ticketSites as $site) {
            if ($site->getSiteName()) return $site->getSiteName();
        }
        return null;
    }

    public function getService(): ?string
    {
        foreach ($this->ticketSites as $site) {
            if ($site->getServiceName()) return $site->getServiceName();
        }
        return null;
    }

    public function getAssignedService(): ?string
    {
        foreach ($this->getTasks() as $task) {
            if (!in_array($task->getStatus(), [TicketTask::STATUS_DONE, TicketTask::STATUS_COMPLETED], true)) {
                return $task->getServiceName();
            }
        }
        return null;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['completed', 'closed'], true);
    }

    public function getCompletedSitesCount(): int
    {
        $count = 0;
        foreach ($this->ticketSites as $site) {
            if ($site->getStatus() === 'completed') {
                $count++;
            }
        }
        return $count;
    }

    public function getSitesProgressPercent(): int
    {
        $total = $this->ticketSites->count();
        if ($total === 0) return 0;
        return (int) round(($this->getCompletedSitesCount() / $total) * 100);
    }
}