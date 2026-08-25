<?php
// src/Entity/Security/SecurityEvent.php

namespace App\Entity\Security;

use App\Entity\User;
use App\Repository\Security\SecurityEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: SecurityEventRepository::class)]
#[ORM\Table(name: 'security_event')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_security_event_ip')]
#[ORM\Index(columns: ['type'], name: 'idx_security_event_type')]
#[ORM\Index(columns: ['severity'], name: 'idx_security_event_severity')]
#[ORM\Index(columns: ['created_at'], name: 'idx_security_event_created_at')]
class SecurityEvent
{
    // Types d'événements
    public const TYPE_LOGIN_SUCCESS = 'LOGIN_SUCCESS';
    public const TYPE_LOGIN_FAILED = 'LOGIN_FAILED';
    public const TYPE_BRUTE_FORCE = 'BRUTE_FORCE';
    public const TYPE_ACCESS_DENIED = 'ACCESS_DENIED';
    public const TYPE_PRIVILEGE_ESCALATION = 'PRIVILEGE_ESCALATION_ATTEMPT';
    public const TYPE_SQLI_ATTEMPT = 'SQLI_ATTEMPT';
    public const TYPE_SESSION_ANOMALY = 'SESSION_ANOMALY';
    public const TYPE_ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';

    // Niveaux de sévérité
    public const SEVERITY_LOW = 'LOW';
    public const SEVERITY_MEDIUM = 'MEDIUM';
    public const SEVERITY_HIGH = 'HIGH';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    public const TYPES = [
        self::TYPE_LOGIN_SUCCESS,
        self::TYPE_LOGIN_FAILED,
        self::TYPE_BRUTE_FORCE,
        self::TYPE_ACCESS_DENIED,
        self::TYPE_PRIVILEGE_ESCALATION,
        self::TYPE_SQLI_ATTEMPT,
        self::TYPE_SESSION_ANOMALY,
        self::TYPE_ACCOUNT_LOCKED,
    ];

    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['security_event:read'])]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['security_event:read'])]
    private string $type;

    #[ORM\Column(type: 'string', length: 20)]
    #[Groups(['security_event:read'])]
    private string $severity;

    // Nullable : une tentative peut venir d'un visiteur non authentifié
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['security_event:read'])]
    private ?User $user = null;

    // On garde aussi l'identifiant textuel tenté (utile si login échoué sur un user qui n'existe pas)
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Groups(['security_event:read'])]
    private ?string $attemptedIdentifier = null;

    #[ORM\Column(type: 'string', length: 45)]
    #[Groups(['security_event:read'])]
    private string $ipAddress;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['security_event:read'])]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['security_event:read'])]
    private ?string $route = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    #[Groups(['security_event:read'])]
    private ?string $httpMethod = null;

    // Détails bruts (payload suspect détecté, paramètres, etc.) stockés en JSON
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['security_event:read'])]
    private ?array $context = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['security_event:read'])]
    private bool $resolved = false;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['security_event:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Type d\'événement invalide : %s', $type));
        }
        $this->type = $type;
        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
    {
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Sévérité invalide : %s', $severity));
        }
        $this->severity = $severity;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getAttemptedIdentifier(): ?string
    {
        return $this->attemptedIdentifier;
    }

    public function setAttemptedIdentifier(?string $attemptedIdentifier): static
    {
        $this->attemptedIdentifier = $attemptedIdentifier;
        return $this;
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

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function setRoute(?string $route): static
    {
        $this->route = $route;
        return $this;
    }

    public function getHttpMethod(): ?string
    {
        return $this->httpMethod;
    }

    public function setHttpMethod(?string $httpMethod): static
    {
        $this->httpMethod = $httpMethod;
        return $this;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): static
    {
        $this->context = $context;
        return $this;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function setResolved(bool $resolved): static
    {
        $this->resolved = $resolved;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}