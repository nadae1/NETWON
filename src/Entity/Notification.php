<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_user_is_read', columns: ['user_id', 'is_read'])]
#[ORM\Index(name: 'idx_notification_created_at', columns: ['created_at'])]
class Notification
{
    public const TYPE_TICKET_ASSIGNED = 'ticket_assigned';
    public const TYPE_TICKET_STATUS_CHANGED = 'ticket_status_changed';
    public const TYPE_TICKET_BLOCKED = 'ticket_blocked';
    public const TYPE_DEADLINE_REMINDER = 'deadline_reminder';
    public const TYPE_TICKET_OVERDUE = 'ticket_overdue';
    public const TYPE_SITE_UPDATE_REQUEST = 'site_update_request';
    public const TYPE_WORKFLOW_ASSIGNED = 'workflow_assigned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Ticket $ticket = null;

    #[ORM\Column(length: 60)]
    private string $type;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column]
    private bool $isRead = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getTicket(): ?Ticket { return $this->ticket; }
    public function setTicket(?Ticket $ticket): self { $this->ticket = $ticket; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): self { $this->message = $message; return $this; }

    public function isRead(): bool { return $this->isRead; }
    public function markRead(): self { $this->isRead = true; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

