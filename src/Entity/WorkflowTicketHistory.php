<?php

namespace App\Entity;

use App\Repository\WorkflowTicketHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkflowTicketHistoryRepository::class)]
class WorkflowTicketHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowTicket::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowTicket $ticket;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private $user = null;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fromStep = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $toStep = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTicket(): WorkflowTicket { return $this->ticket; }
    public function setTicket(WorkflowTicket $ticket): self { $this->ticket = $ticket; return $this; }
    public function getUser() { return $this->user; }
    public function setUser($user): self { $this->user = $user; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $action): self { $this->action = $action; return $this; }
    public function getFromStep(): ?string { return $this->fromStep; }
    public function setFromStep(?string $fromStep): self { $this->fromStep = $fromStep; return $this; }
    public function getToStep(): ?string { return $this->toStep; }
    public function setToStep(?string $toStep): self { $this->toStep = $toStep; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}