<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TicketSite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ticketSites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\Column(length: 255)]
    private ?string $siteName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $typeTrans = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $serviceName = null;

    public function getId(): ?int { return $this->id; }

    public function getTicket(): ?Ticket { return $this->ticket; }
    public function setTicket(?Ticket $ticket): static { $this->ticket = $ticket; return $this; }

    public function getSiteName(): ?string { return $this->siteName; }
    public function setSiteName(string $siteName): static { $this->siteName = $siteName; return $this; }

    public function getTypeTrans(): ?string { return $this->typeTrans; }
    public function setTypeTrans(?string $typeTrans): static { $this->typeTrans = $typeTrans; return $this; }

    public function getServiceName(): ?string { return $this->serviceName; }
    public function setServiceName(?string $serviceName): static { $this->serviceName = $serviceName; return $this; }
}