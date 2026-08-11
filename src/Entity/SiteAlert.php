<?php

namespace App\Entity;

use App\Repository\SiteAlertRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteAlertRepository::class)]
#[ORM\Table(name: 'site_alert')]
class SiteAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $site = null;

    #[ORM\Column(length: 50)]
    private ?string $etat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $trafic_j = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $capacite_mbps = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $taux_utilisation = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $variation_j1 = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $variation_j7 = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $type_trans = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $classification = null;

    #[ORM\Column(type: 'text')]
    private ?string $message = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $date_alerte = null;

    // Getters et setters
    public function getId(): ?int { return $this->id; }
    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): self { $this->site = $site; return $this; }
    public function getEtat(): ?string { return $this->etat; }
    public function setEtat(string $etat): self { $this->etat = $etat; return $this; }
    public function getTraficJ(): ?float { return $this->trafic_j; }
    public function setTraficJ(?float $trafic_j): self { $this->trafic_j = $trafic_j; return $this; }
    public function getCapaciteMbps(): ?float { return $this->capacite_mbps; }
    public function setCapaciteMbps(?float $capacite_mbps): self { $this->capacite_mbps = $capacite_mbps; return $this; }
    public function getTauxUtilisation(): ?float { return $this->taux_utilisation; }
    public function setTauxUtilisation(?float $taux_utilisation): self { $this->taux_utilisation = $taux_utilisation; return $this; }
    public function getVariationJ1(): ?float { return $this->variation_j1; }
    public function setVariationJ1(?float $variation_j1): self { $this->variation_j1 = $variation_j1; return $this; }
    public function getVariationJ7(): ?float { return $this->variation_j7; }
    public function setVariationJ7(?float $variation_j7): self { $this->variation_j7 = $variation_j7; return $this; }
    public function getTypeTrans(): ?string { return $this->type_trans; }
    public function setTypeTrans(?string $type_trans): self { $this->type_trans = $type_trans; return $this; }
    public function getClassification(): ?string { return $this->classification; }
    public function setClassification(?string $classification): self { $this->classification = $classification; return $this; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(string $message): self { $this->message = $message; return $this; }
    public function getDateAlerte(): ?\DateTime { return $this->date_alerte; }
    public function setDateAlerte(\DateTime $date_alerte): self { $this->date_alerte = $date_alerte; return $this; }
}