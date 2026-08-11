<?php

namespace App\Entity;

use App\Repository\ChatbotConversationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatbotConversationRepository::class)]
#[ORM\Table(name: 'chatbot_conversation')]
class ChatbotConversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?User $user = null;

    #[ORM\Column(type: 'json')]
    private array $messages = [];

    #[ORM\Column(type: 'json')]
    private array $exports = [];

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }

    public function getMessages(): array { return $this->messages; }
    public function setMessages(array $messages): static { $this->messages = $messages; return $this; }

    public function getExports(): array { return $this->exports; }
    public function setExports(array $exports): static { $this->exports = $exports; return $this; }

    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}