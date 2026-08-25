<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 100)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9._-]+$/',
        message: 'Le username contient des caractères non autorisés.'
    )]
    private ?string $username = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(nullable: true)]
    private ?string $service = null;

    #[ORM\Column(nullable: true)]
    private ?string $department = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(nullable: true)]
    private ?string $resetCode = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetCodeExpiresAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isLocked = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $lockReason = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getRoles(): array
    {
        return array_unique($this->roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void {}

    public function getService(): ?string
    {
        return $this->service;
    }

    public function setService(?string $service): static
    {
        $this->service = $service;
        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $this->department = $department;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getResetCode(): ?string
    {
        return $this->resetCode;
    }

    public function setResetCode(?string $resetCode): static
    {
        $this->resetCode = $resetCode;
        return $this;
    }

    public function getResetCodeExpiresAt(): ?\DateTimeInterface
    {
        return $this->resetCodeExpiresAt;
    }

    public function setResetCodeExpiresAt(?\DateTimeInterface $resetCodeExpiresAt): static
    {
        $this->resetCodeExpiresAt = $resetCodeExpiresAt;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->username ?? $this->email ?? 'Utilisateur';
    }


    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function setLocked(bool $isLocked): static
    {
        $this->isLocked = $isLocked;
        return $this;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?\DateTimeImmutable $lockedAt): static
    {
        $this->lockedAt = $lockedAt;
        return $this;
    }

    public function getLockReason(): ?string
    {
        return $this->lockReason;
    }

    public function setLockReason(?string $lockReason): static
    {
        $this->lockReason = $lockReason;
        return $this;
    }
}
