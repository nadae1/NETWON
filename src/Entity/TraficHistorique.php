<?php
// src/Entity/TraficHistorique.php

namespace App\Entity;

use App\Repository\TraficHistoriqueRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ✅ CORRIGÉ : les colonnes précédentes (trafic, capacite, tauxUtilisation,
 * typeTrans) n'existaient pas dans la table réellement créée par
 * traitement.py::_ensure_trafic_historique_table(). Alignement strict sur
 * le schéma réel : site, date_jour, max_trafic, capacite_mbps, max_speed,
 * date_heure, date_importation.
 */
#[ORM\Entity(repositoryClass: TraficHistoriqueRepository::class)]
#[ORM\Table(name: 'trafic_historique')]
#[ORM\Index(name: 'idx_th_site', columns: ['site'])]
#[ORM\Index(name: 'idx_th_date_heure', columns: ['date_heure'])]
class TraficHistorique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $site = null;

    #[ORM\Column(name: 'date_jour', type: 'date', nullable: true)]
    private ?\DateTimeInterface $dateJour = null;

    #[ORM\Column(name: 'max_trafic', type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?float $maxTrafic = null;

    #[ORM\Column(name: 'capacite_mbps', type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?float $capaciteMbps = null;

    #[ORM\Column(name: 'max_speed', type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?float $maxSpeed = null;

    #[ORM\Column(name: 'date_heure', type: 'datetime')]
    private ?\DateTimeInterface $dateHeure = null;

    #[ORM\Column(name: 'date_importation', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateImportation = null;

    public function getId(): ?int { return $this->id; }

    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): static { $this->site = $site; return $this; }

    public function getDateJour(): ?\DateTimeInterface { return $this->dateJour; }
    public function setDateJour(?\DateTimeInterface $dateJour): static { $this->dateJour = $dateJour; return $this; }

    public function getMaxTrafic(): ?float { return $this->maxTrafic; }
    public function setMaxTrafic(?float $maxTrafic): static { $this->maxTrafic = $maxTrafic; return $this; }

    public function getCapaciteMbps(): ?float { return $this->capaciteMbps; }
    public function setCapaciteMbps(?float $capaciteMbps): static { $this->capaciteMbps = $capaciteMbps; return $this; }

    public function getMaxSpeed(): ?float { return $this->maxSpeed; }
    public function setMaxSpeed(?float $maxSpeed): static { $this->maxSpeed = $maxSpeed; return $this; }

    public function getDateHeure(): ?\DateTimeInterface { return $this->dateHeure; }
    public function setDateHeure(\DateTimeInterface $dateHeure): static { $this->dateHeure = $dateHeure; return $this; }

    public function getDateImportation(): ?\DateTimeInterface { return $this->dateImportation; }
    public function setDateImportation(?\DateTimeInterface $dateImportation): static { $this->dateImportation = $dateImportation; return $this; }
}