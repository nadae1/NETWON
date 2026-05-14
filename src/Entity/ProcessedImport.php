<?php

namespace App\Entity;

use App\Repository\ProcessedImportRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcessedImportRepository::class)]
class ProcessedImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $importedBy = null;

    #[ORM\Column(length: 50)]
    private ?string $serviceScope = null;

    #[ORM\Column(length: 255)]
    private ?string $trafficFileName = null;

    #[ORM\Column(length: 255)]
    private ?string $portsFileName = null;

    #[ORM\Column(length: 255)]
    private ?string $liaisonFileName = null;

    #[ORM\Column(length: 50)]
    private string $status = 'success';

    #[ORM\Column]
    private int $totalSites = 0;

    #[ORM\Column]
    private int $totalOccurrences = 0;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $importedAt;

    #[ORM\OneToMany(mappedBy: 'processedImport', targetEntity: ProcessedSite::class, cascade: ['persist', 'remove'])]
    private Collection $processedSites;

    public function __construct()
    {
        $this->processedSites = new ArrayCollection();
        $this->importedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getImportedBy(): ?User { return $this->importedBy; }
    public function setImportedBy(?User $importedBy): static { $this->importedBy = $importedBy; return $this; }

    public function getServiceScope(): ?string { return $this->serviceScope; }
    public function setServiceScope(string $serviceScope): static { $this->serviceScope = $serviceScope; return $this; }

    public function getTrafficFileName(): ?string { return $this->trafficFileName; }
    public function setTrafficFileName(string $trafficFileName): static { $this->trafficFileName = $trafficFileName; return $this; }

    public function getPortsFileName(): ?string { return $this->portsFileName; }
    public function setPortsFileName(string $portsFileName): static { $this->portsFileName = $portsFileName; return $this; }

    public function getLiaisonFileName(): ?string { return $this->liaisonFileName; }
    public function setLiaisonFileName(string $liaisonFileName): static { $this->liaisonFileName = $liaisonFileName; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getTotalSites(): int { return $this->totalSites; }
    public function setTotalSites(int $totalSites): static { $this->totalSites = $totalSites; return $this; }

    public function getTotalOccurrences(): int { return $this->totalOccurrences; }
    public function setTotalOccurrences(int $totalOccurrences): static { $this->totalOccurrences = $totalOccurrences; return $this; }

    public function getImportedAt(): \DateTimeInterface { return $this->importedAt; }
    public function setImportedAt(\DateTimeInterface $importedAt): static { $this->importedAt = $importedAt; return $this; }

    public function getProcessedSites(): Collection { return $this->processedSites; }
}