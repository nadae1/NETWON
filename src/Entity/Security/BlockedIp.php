<?php
// src/Entity/Security/BlockedIp.php

namespace App\Entity\Security;

use App\Entity\User;
use App\Repository\Security\BlockedIpRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlockedIpRepository::class)]
#[ORM\Table(name: 'blocked_ip')]
#[ORM\UniqueConstraint(name: 'uniq_blocked_ip_address', columns: ['ip_address'])]
class BlockedIp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 45)]
    private string $ipAddress;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'blocked_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $blockedBy = null;

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

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;
        return $this;
    }

    public function getBlockedBy(): ?User
    {
        return $this->blockedBy;
    }

    public function setBlockedBy(?User $blockedBy): static
    {
        $this->blockedBy = $blockedBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}