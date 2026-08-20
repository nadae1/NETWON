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

    #[ORM\Column(name: 'max_trafic_tdd', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?float $maxTraficTdd = null;

    #[ORM\Column(name: 'max_trafic_fdd', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?float $maxTraficFdd = null;

    #[ORM\Column(name: 'capacite_tdd_mbps', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?float $capaciteTddMbps = null;

    #[ORM\Column(name: 'capacite_fdd_mbps', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?float $capaciteFddMbps = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateMax = null;

    #[ORM\Column(nullable: true)]
    private ?float $seuilCritique = null;

    #[ORM\Column]
    private int $nombreOccurrences = 0;

    #[ORM\Column(name: 'nombre_occurrences_tdd', type: 'integer', nullable: true)]
    private ?int $nombreOccurrencesTdd = null;

    #[ORM\Column(name: 'nombre_occurrences_fdd', type: 'integer', nullable: true)]
    private ?int $nombreOccurrencesFdd = null;

    #[ORM\Column]
    private int $totalMeasures = 0;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $service = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'site_status', length: 20, nullable: true)]
    private ?string $siteStatus = null;

    #[ORM\Column]
    private bool $isCritical = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $dataHash = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $recommendedAction = null;

    #[ORM\Column(name: 'final_action_plan', length: 255, nullable: true)]
    private ?string $finalActionPlan = null;

    #[ORM\Column(name: 'taux_utilisation', type: 'float', nullable: true)]
    private ?float $tauxUtilisation = null;

    #[ORM\Column(name: 'taux_utilisation_tdd', type: 'float', nullable: true)]
    private ?float $tauxUtilisationTdd = null;

    #[ORM\Column(name: 'taux_utilisation_fdd', type: 'float', nullable: true)]
    private ?float $tauxUtilisationFdd = null;

    #[ORM\Column(name: 'dropcong_tdd', type: 'integer', nullable: true)]
    private ?int $dropCongTdd = null;

    #[ORM\Column(name: 'dropcong_fdd', type: 'integer', nullable: true)]
    private ?int $dropCongFdd = null;

    #[ORM\Column(name: 'dropcong_tf', type: 'integer', nullable: true)]
    private ?int $dropCongTf = null;

    /**
     * ✅ NOUVEAU : durée maximale (en secondes) d'indisponibilité du lien
     * S1 mesurée pour ce site (colonne L.Cell.Unavail.Dur.Sys.S1Fail(s)
     * du fichier trafic). > 0 signifie qu'aucun trafic ne transite
     * réellement entre le site et l'eNodeB pendant la période concernée.
     */
    #[ORM\Column(name: 's1_fail_duration', type: 'float', nullable: true)]
    private ?float $s1FailDuration = null;

    /**
     * ✅ NOUVEAU : date/heure de la dernière coupure S1 détectée.
     */
    #[ORM\Column(name: 's1_fail_date', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $s1FailDate = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $supervisionUntil = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reminderSnoozedUntil = null;

    #[ORM\Column(name: 'capacite_mbps', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?float $capaciteMbps = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $observationUntil = null;

    #[ORM\Column(name: 'capacity_reminder_until', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $capacityReminderUntil = null;

    #[ORM\Column(name: 'capacite_updated_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $capaciteUpdatedAt = null;

    #[ORM\Column(name: 'last_action_performed', length: 255, nullable: true)]
    private ?string $lastActionPerformed = null;

    public function getS1FailDuration(): ?float
    {
        return $this->s1FailDuration;
    }

    public function setS1FailDuration(?float $s1FailDuration): static
    {
        $this->s1FailDuration = $s1FailDuration;
        return $this;
    }

    public function getS1FailDate(): ?\DateTimeInterface
    {
        return $this->s1FailDate;
    }

    public function setS1FailDate(?\DateTimeInterface $s1FailDate): static
    {
        $this->s1FailDate = $s1FailDate;
        return $this;
    }

    public function isS1Down(): bool
    {
        return $this->s1FailDuration !== null && $this->s1FailDuration > 0;
    }

    public function getCapaciteUpdatedAt(): ?\DateTimeInterface
    {
        return $this->capaciteUpdatedAt;
    }

    public function setCapaciteUpdatedAt(?\DateTimeInterface $capaciteUpdatedAt): static
    {
        $this->capaciteUpdatedAt = $capaciteUpdatedAt;
        return $this;
    }

    public function getLastActionPerformed(): ?string
    {
        return $this->lastActionPerformed;
    }

    public function setLastActionPerformed(?string $lastActionPerformed): static
    {
        $this->lastActionPerformed = $lastActionPerformed;
        return $this;
    }

    public function getSupervisionUntil(): ?\DateTimeInterface
    {
        return $this->supervisionUntil;
    }
    public function setSupervisionUntil(?\DateTimeInterface $supervisionUntil): self
    {
        $this->supervisionUntil = $supervisionUntil;
        return $this;
    }

    public function getReminderSnoozedUntil(): ?\DateTimeInterface
    {
        return $this->reminderSnoozedUntil;
    }

    public function setReminderSnoozedUntil(?\DateTimeInterface $reminderSnoozedUntil): static
    {
        $this->reminderSnoozedUntil = $reminderSnoozedUntil;
        return $this;
    }

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

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }
    public function setLatitude(?float $lat): self
    {
        $this->latitude = $lat;
        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }
    public function setLongitude(?float $lng): self
    {
        $this->longitude = $lng;
        return $this;
    }

    public function getCapacityReminderUntil(): ?\DateTimeInterface
    {
        return $this->capacityReminderUntil;
    }

    public function setCapacityReminderUntil(?\DateTimeInterface $capacityReminderUntil): static
    {
        $this->capacityReminderUntil = $capacityReminderUntil;
        return $this;
    }

    public function needsCapacityOrTypeUpdate(): bool
    {
        $missingCapacity = $this->capaciteMbps === null || $this->capaciteMbps <= 0;
        $type = strtoupper(trim((string) $this->typeTrans));
        $missingType = $type === '' || in_array($type, ['NON_DEFINI', 'UNKNOWN', 'N/A', 'NA', '-'], true);

        if (!$missingCapacity && !$missingType) {
            return false;
        }

        if ($this->capacityReminderUntil !== null && $this->capacityReminderUntil > new \DateTime()) {
            return false;
        }

        return true;
    }

    public function getRecommendedAction(): ?string
    {
        return $this->recommendedAction;
    }
    public function setRecommendedAction(?string $action): self
    {
        $this->recommendedAction = $action;
        return $this;
    }

    public function getFinalActionPlan(): ?string
    {
        return $this->finalActionPlan;
    }

    public function setFinalActionPlan(?string $finalActionPlan): self
    {
        $this->finalActionPlan = $finalActionPlan;
        return $this;
    }

    public function getTauxUtilisation(): ?float
    {
        return $this->tauxUtilisation;
    }

    public function setTauxUtilisation(?float $tauxUtilisation): self
    {
        $this->tauxUtilisation = $tauxUtilisation;
        return $this;
    }

    public function getTauxUtilisationTdd(): ?float
    {
        return $this->tauxUtilisationTdd;
    }

    public function setTauxUtilisationTdd(?float $tauxUtilisationTdd): self
    {
        $this->tauxUtilisationTdd = $tauxUtilisationTdd;
        return $this;
    }

    public function getTauxUtilisationFdd(): ?float
    {
        return $this->tauxUtilisationFdd;
    }

    public function setTauxUtilisationFdd(?float $tauxUtilisationFdd): self
    {
        $this->tauxUtilisationFdd = $tauxUtilisationFdd;
        return $this;
    }

    public function getDropCongTdd(): ?int
    {
        return $this->dropCongTdd;
    }

    public function setDropCongTdd(?int $dropCongTdd): self
    {
        $this->dropCongTdd = $dropCongTdd;
        return $this;
    }

    public function getDropCongFdd(): ?int
    {
        return $this->dropCongFdd;
    }

    public function setDropCongFdd(?int $dropCongFdd): self
    {
        $this->dropCongFdd = $dropCongFdd;
        return $this;
    }

    public function getDropCongTf(): ?int
    {
        return $this->dropCongTf;
    }

    public function setDropCongTf(?int $dropCongTf): self
    {
        $this->dropCongTf = $dropCongTf;
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

    public function getMaxTraficTdd(): ?float
    {
        return $this->maxTraficTdd;
    }

    public function setMaxTraficTdd(?float $maxTraficTdd): static
    {
        $this->maxTraficTdd = $maxTraficTdd;
        return $this;
    }

    public function getMaxTraficFdd(): ?float
    {
        return $this->maxTraficFdd;
    }

    public function setMaxTraficFdd(?float $maxTraficFdd): static
    {
        $this->maxTraficFdd = $maxTraficFdd;
        return $this;
    }

    public function getCapaciteTddMbps(): ?float
    {
        return $this->capaciteTddMbps;
    }

    public function setCapaciteTddMbps(?float $capaciteTddMbps): static
    {
        $this->capaciteTddMbps = $capaciteTddMbps;
        return $this;
    }

    public function getCapaciteFddMbps(): ?float
    {
        return $this->capaciteFddMbps;
    }

    public function setCapaciteFddMbps(?float $capaciteFddMbps): static
    {
        $this->capaciteFddMbps = $capaciteFddMbps;
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

    public function getNombreOccurrencesTdd(): ?int
    {
        return $this->nombreOccurrencesTdd;
    }

    public function setNombreOccurrencesTdd(?int $nombreOccurrencesTdd): static
    {
        $this->nombreOccurrencesTdd = $nombreOccurrencesTdd;
        return $this;
    }

    public function getNombreOccurrencesFdd(): ?int
    {
        return $this->nombreOccurrencesFdd;
    }

    public function setNombreOccurrencesFdd(?int $nombreOccurrencesFdd): static
    {
        $this->nombreOccurrencesFdd = $nombreOccurrencesFdd;
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

    public function getSiteStatus(): ?string
    {
        return $this->siteStatus;
    }

    public function setSiteStatus(?string $siteStatus): static
    {
        $this->siteStatus = $siteStatus;
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