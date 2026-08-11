<?php

namespace App\Entity;

use App\Repository\WorkflowHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkflowHistoryRepository::class)]
class WorkflowHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Ticket $ticket = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $oldStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $newStatus = null;

    #[ORM\Column(length: 100)]
    private ?string $action = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userEmail = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $timestamp = null;

    public function __construct()
    {
        $this->timestamp = new \DateTimeImmutable();
    }

    // Getters et setters...
}