<?php

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

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deadlineAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

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
    private Collection $comments;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketHistory::class, cascade: ['persist', 'remove'])]
    private Collection $history;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $workflowType = null;

    // Ajoutez ces propriétés dans la classe Ticket (vers le haut du fichier, avec les autres propriétés)

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'ticket_assigned_users')]
    private Collection $assignedUsers;



    // Ajoutez dans le constructeur (vers le bas du fichier)
    public function __construct()
    {
        $this->tasks = new ArrayCollection();
        $this->ticketSites = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->history = new ArrayCollection();
        $this->assignedUsers = new ArrayCollection(); // ← AJOUTER CETTE L
    }

    // Ajoutez ces méthodes (à la fin du fichier, avant la dernière accolade)

    public function getAssignedUsers(): Collection
    {
        return $this->assignedUsers;
    }

    public function addAssignedUser(User $user): self
    {
        if (!$this->assignedUsers->contains($user)) {
            $this->assignedUsers->add($user);
        }
        return $this;
    }

    public function removeAssignedUser(User $user): self
    {
        $this->assignedUsers->removeElement($user);
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;
        return $this;
    }

    public function getWorkflowType(): ?string
    {
        return $this->workflowType;
    }
    public function setWorkflowType(?string $workflowType): self
    {
        $this->workflowType = $workflowType;
        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;
        return $this;
    }


    public function getId(): ?int
    {
        return $this->id;
    }
    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getActionType(): ?string
    {
        return $this->actionType;
    }
    public function setActionType(string $actionType): static
    {
        $this->actionType = $actionType;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }
    public function setProgress(int $progress): static
    {
        $this->progress = $progress;
        return $this;
    }

    public function getDeadlineAt(): ?\DateTimeInterface
    {
        return $this->deadlineAt;
    }
    public function setDeadlineAt(?\DateTimeInterface $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getClosedAt(): ?\DateTimeInterface
    {
        return $this->closedAt;
    }
    public function setClosedAt(?\DateTimeInterface $closedAt): static
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }
    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getTasks(): Collection
    {
        return $this->tasks;
    }
    public function getTicketSites(): Collection
    {
        return $this->ticketSites;
    }
    public function getComments(): Collection
    {
        return $this->comments;
    }
    public function getHistory(): Collection
    {
        return $this->history;
    }

    // Dans la classe Ticket, ajoutez cette méthode :

public function getCurrentBlockerService(): ?string
{
    foreach ($this->getTasks() as $task) {
        if ($task->getStatus() === 'blocked') {
            return $task->getServiceName();
        }
    }
    if ($this->getStatus() === 'waiting_capillaire') {
        return 'Ingénierie Capillaire';
    }
    if ($this->getStatus() === 'waiting_swap') {
        return 'Ingénierie IP (swap)';
    }
    if ($this->getStatus() === 'waiting_other_service') {
        return 'Autre service';
    }
    return null;
}
}
