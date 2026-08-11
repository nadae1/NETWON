<?php
// src/Entity/SitePrediction.php

namespace App\Entity;

use App\Repository\SitePredictionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Résultats de l'IA de prédiction. Table dédiée, jamais mélangée avec
 * trafic_historique (données réelles) ni processed_site (résultat du
 * traitement métier). Lecture seule côté Symfony.
 */
#[ORM\Entity(repositoryClass: SitePredictionRepository::class)]
#[ORM\Table(name: 'site_prediction')]
#[ORM\Index(name: 'idx_sp_site', columns: ['site'])]
#[ORM\Index(name: 'idx_sp_etat', columns: ['etat_predit'])]
class SitePrediction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $site = null;

    #[ORM\Column(length: 20)]
    private ?string $horizon = null;

    #[ORM\Column(name: 'horizon_heures')]
    private ?int $horizonHeures = null;

    #[ORM\Column(name: 'trafic_actuel_mbps', type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?float $traficActuelMbps = null;

    #[ORM\Column(name: 'trafic_projete_mbps', type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?float $traficProjeteMbps = null;

    #[ORM\Column(name: 'capacite_mbps', type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?float $capaciteMbps = null;

    #[ORM\Column(name: 'taux_utilisation_projete_pct', type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?float $tauxUtilisationProjetePct = null;

    #[ORM\Column(name: 'facteur_croissance', type: 'decimal', precision: 8, scale: 4, nullable: true)]
    private ?float $facteurCroissance = null;

    #[ORM\Column(name: 'occurrences_projetees', nullable: true)]
    private ?int $occurrencesProjetees = null;

    #[ORM\Column(name: 'etat_predit', length: 40, nullable: true)]
    private ?string $etatPredit = null;

    #[ORM\Column(name: 'action_priorite', length: 20, nullable: true)]
    private ?string $actionPriorite = null;

    #[ORM\Column(name: 'action_code', length: 60, nullable: true)]
    private ?string $actionCode = null;

    #[ORM\Column(name: 'action_description', type: 'text', nullable: true)]
    private ?string $actionDescription = null;

    #[ORM\Column(name: 'projection_fiable', type: 'boolean', nullable: true, options: ['default' => true])]
    private ?bool $projectionFiable = true;

    #[ORM\Column(name: 'date_prediction', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $datePrediction = null;

    public function getId(): ?int { return $this->id; }

    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): static { $this->site = $site; return $this; }

    public function getHorizon(): ?string { return $this->horizon; }
    public function setHorizon(string $horizon): static { $this->horizon = $horizon; return $this; }

    public function getHorizonHeures(): ?int { return $this->horizonHeures; }
    public function setHorizonHeures(int $h): static { $this->horizonHeures = $h; return $this; }

    public function getTraficActuelMbps(): ?float { return $this->traficActuelMbps; }
    public function setTraficActuelMbps(?float $v): static { $this->traficActuelMbps = $v; return $this; }

    public function getTraficProjeteMbps(): ?float { return $this->traficProjeteMbps; }
    public function setTraficProjeteMbps(?float $v): static { $this->traficProjeteMbps = $v; return $this; }

    public function getCapaciteMbps(): ?float { return $this->capaciteMbps; }
    public function setCapaciteMbps(?float $v): static { $this->capaciteMbps = $v; return $this; }

    public function getTauxUtilisationProjetePct(): ?float { return $this->tauxUtilisationProjetePct; }
    public function setTauxUtilisationProjetePct(?float $v): static { $this->tauxUtilisationProjetePct = $v; return $this; }

    public function getFacteurCroissance(): ?float { return $this->facteurCroissance; }
    public function setFacteurCroissance(?float $v): static { $this->facteurCroissance = $v; return $this; }

    public function getOccurrencesProjetees(): ?int { return $this->occurrencesProjetees; }
    public function setOccurrencesProjetees(?int $v): static { $this->occurrencesProjetees = $v; return $this; }

    public function getEtatPredit(): ?string { return $this->etatPredit; }
    public function setEtatPredit(?string $v): static { $this->etatPredit = $v; return $this; }

    public function getActionPriorite(): ?string { return $this->actionPriorite; }
    public function setActionPriorite(?string $v): static { $this->actionPriorite = $v; return $this; }

    public function getActionCode(): ?string { return $this->actionCode; }
    public function setActionCode(?string $v): static { $this->actionCode = $v; return $this; }

    public function getActionDescription(): ?string { return $this->actionDescription; }
    public function setActionDescription(?string $v): static { $this->actionDescription = $v; return $this; }

    public function getProjectionFiable(): ?bool { return $this->projectionFiable; }
    public function setProjectionFiable(?bool $v): static { $this->projectionFiable = $v; return $this; }

    public function getDatePrediction(): ?\DateTimeInterface { return $this->datePrediction; }
    public function setDatePrediction(?\DateTimeInterface $v): static { $this->datePrediction = $v; return $this; }
}