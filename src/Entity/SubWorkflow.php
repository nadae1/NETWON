<?php

namespace App\Entity;

use App\Repository\SubWorkflowRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubWorkflowRepository::class)]
#[ORM\Table(name: 'subworkflow')]
#[ORM\Index(name: 'idx_subworkflow_parent', columns: ['parent_ticket_id'])]
#[ORM\Index(name: 'idx_subworkflow_child', columns: ['child_ticket_id'])]
class SubWorkflow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $parentTicket = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $childTicket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParentTicket(): ?Ticket
    {
        return $this->parentTicket;
    }

    public function setParentTicket(?Ticket $parentTicket): self
    {
        $this->parentTicket = $parentTicket;
        return $this;
    }

    public function getChildTicket(): ?Ticket
    {
        return $this->childTicket;
    }

    public function setChildTicket(?Ticket $childTicket): self
    {
        $this->childTicket = $childTicket;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

