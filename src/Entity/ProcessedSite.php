<?php

namespace App\Entity;

use App\Repository\ProcessedSiteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcessedSiteRepository::class)]
#[ORM\Table(name: 'processed_site')]
#[ORM\Index(name: 'idx_service', columns: ['service'])]
#[ORM\Index(name: 'idx_classification', columns: ['classification'])]
#[ORM\Index(name: 'idx_is_critical', columns: ['is_critical'])]
class ProcessedSite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'processedSites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProcessedImport $processedImport = null;

    #[ORM\Column(length: 255)]
    private ?string $siteName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pairedSiteName = null;

    #[ORM\Column(length: 100)]
    private ?string $classification = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $typeTrans = null;

    #[ORM\Column(nullable: true)]
    private ?float $maxTrafic = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateMax = null;

    #[ORM\Column(nullable: true)]
    private ?float $seuilCritique = null;

    #[ORM\Column]
    private int $nombreOccurrences = 0;

    #[ORM\Column]
    private int $totalMeasures = 0;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $service = null;  // ← CHANGÉ: service_name → service

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = null;

    #[ORM\Column]
    private bool $isCritical = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $dataHash = null;
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $recommendedAction = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $siteStatus = null; // 'critical', 'secured', 'warning'

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $supervisionUntil = null;

    public function getSupervisionUntil(): ?\DateTimeInterface
    {
        return $this->supervisionUntil;
    }
    public function setSupervisionUntil(?\DateTimeInterface $supervisionUntil): self
    {
        $this->supervisionUntil = $supervisionUntil;
        return $this;
    }

    #[ORM\Column(name: 'capacite_mbps', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?float $capaciteMbps = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $observationUntil = null;

    public function getObservationUntil(): ?\DateTimeInterface
    {
        return $this->observationUntil;
    }
    public function setObservationUntil(?\DateTimeInterface $observationUntil): self
    {
        $this->observationUntil = $observationUntil;
        return $this;
    }
    public function getCapaciteMbps(): ?float
    {
        return $this->capaciteMbps;
    }

    public function setCapaciteMbps(?float $capaciteMbps): self
    {
        $this->capaciteMbps = $capaciteMbps;
        return $this;
    }


    public function getLatitude(): ?string
    {
        return $this->latitude;
    }
    public function setLatitude(?string $lat): self
    {
        $this->latitude = $lat;
        return $this;
    }


    public function getLongitude(): ?string
    {
        return $this->longitude;
    }
    public function setLongitude(?string $lng): self
    {
        $this->longitude = $lng;
        return $this;
    }




    // getter / setter
    public function getRecommendedAction(): ?string
    {
        return $this->recommendedAction;
    }
    public function setRecommendedAction(?string $action): self
    {
        $this->recommendedAction = $action;
        return $this;
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProcessedImport(): ?ProcessedImport
    {
        return $this->processedImport;
    }

    public function setProcessedImport(?ProcessedImport $processedImport): static
    {
        $this->processedImport = $processedImport;
        return $this;
    }

    public function getSiteName(): ?string
    {
        return $this->siteName;
    }

    public function setSiteName(string $siteName): static
    {
        $this->siteName = $siteName;
        return $this;
    }

    public function getPairedSiteName(): ?string
    {
        return $this->pairedSiteName;
    }

    public function setPairedSiteName(?string $pairedSiteName): static
    {
        $this->pairedSiteName = $pairedSiteName;
        return $this;
    }

    public function getClassification(): ?string
    {
        return $this->classification;
    }

    public function setClassification(string $classification): static
    {
        $this->classification = $classification;
        return $this;
    }

    public function getTypeTrans(): ?string
    {
        return $this->typeTrans;
    }

    public function setTypeTrans(?string $typeTrans): static
    {
        $this->typeTrans = $typeTrans;
        return $this;
    }

    public function getMaxTrafic(): ?float
    {
        return $this->maxTrafic;
    }

    public function setMaxTrafic(?float $maxTrafic): static
    {
        $this->maxTrafic = $maxTrafic;
        return $this;
    }

    public function getDateMax(): ?\DateTimeInterface
    {
        return $this->dateMax;
    }

    public function setDateMax(?\DateTimeInterface $dateMax): static
    {
        $this->dateMax = $dateMax;
        return $this;
    }

    public function getSeuilCritique(): ?float
    {
        return $this->seuilCritique;
    }

    public function setSeuilCritique(?float $seuilCritique): static
    {
        $this->seuilCritique = $seuilCritique;
        return $this;
    }

    public function getNombreOccurrences(): int
    {
        return $this->nombreOccurrences;
    }

    public function setNombreOccurrences(int $nombreOccurrences): static
    {
        $this->nombreOccurrences = $nombreOccurrences;
        return $this;
    }

    public function getTotalMeasures(): int
    {
        return $this->totalMeasures;
    }

    public function setTotalMeasures(int $totalMeasures): static
    {
        $this->totalMeasures = $totalMeasures;
        return $this;
    }

    public function getService(): ?string
    {
        return $this->service;
    }

    public function setService(?string $service): static
    {
        $this->service = $service;
        return $this;
    }

    // Alias pour compatibilité ascendante
    public function getServiceName(): ?string
    {
        return $this->service;
    }

    public function setServiceName(?string $service): static
    {
        $this->service = $service;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isCritical(): bool
    {
        return $this->isCritical;
    }

    public function setIsCritical(bool $isCritical): static
    {
        $this->isCritical = $isCritical;
        return $this;
    }

    public function getDataHash(): ?string
    {
        return $this->dataHash;
    }

    public function setDataHash(?string $dataHash): static
    {
        $this->dataHash = $dataHash;
        return $this;
    }
}
