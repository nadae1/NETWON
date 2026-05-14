<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Site
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column]
    private float $latitude;

    #[ORM\Column]
    private float $longitude;

    #[ORM\Column(length: 50)]
    private string $status;

    #[ORM\Column(length: 50)]
    private string $service;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function getLatitude(): float { return $this->latitude; }
    public function getLongitude(): float { return $this->longitude; }
    public function getStatus(): string { return $this->status; }
    public function getService(): string { return $this->service; }
}