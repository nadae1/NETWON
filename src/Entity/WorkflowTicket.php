<?php

namespace App\Entity;

use App\Repository\WorkflowTicketRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkflowTicketRepository::class)]
class WorkflowTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $reference;

    #[ORM\Column(length: 255)]
    private string $siteName;

    #[ORM\Column(length: 50)]
    private string $service = 'FO';

    #[ORM\Column(length: 100)]
    private string $status = 'SENT_TO_IP';

    #[ORM\Column(length: 100)]
    private string $currentStep = 'IP_ANALYSIS';

    #[ORM\Column(length: 100)]
    private string $assignedService = 'INGENIERIE_IP';

    #[ORM\Column(length: 50)]
    private string $priority = 'NORMAL';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?float $trafficBefore = null;

    #[ORM\Column(nullable: true)]
    private ?float $trafficAfter = null;

    #[ORM\Column]
    private bool $isClosed = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->reference = 'TK-' . date('Ymd-His') . '-' . random_int(100, 999);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getSiteName(): string { return $this->siteName; }
    public function setSiteName(string $siteName): self { $this->siteName = $siteName; return $this; }
    public function getService(): string { return $this->service; }
    public function setService(string $service): self { $this->service = $service; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getCurrentStep(): string { return $this->currentStep; }
    public function setCurrentStep(string $currentStep): self { $this->currentStep = $currentStep; return $this; }
    public function getAssignedService(): string { return $this->assignedService; }
    public function setAssignedService(string $assignedService): self { $this->assignedService = $assignedService; return $this; }
    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $priority): self { $this->priority = $priority; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getTrafficBefore(): ?float { return $this->trafficBefore; }
    public function setTrafficBefore(?float $trafficBefore): self { $this->trafficBefore = $trafficBefore; return $this; }
    public function getTrafficAfter(): ?float { return $this->trafficAfter; }
    public function setTrafficAfter(?float $trafficAfter): self { $this->trafficAfter = $trafficAfter; return $this; }
    public function isClosed(): bool { return $this->isClosed; }
    public function setIsClosed(bool $isClosed): self { $this->isClosed = $isClosed; return $this; }
    public function getCreatedBy() { return $this->createdBy; }
    public function setCreatedBy($createdBy): self { $this->createdBy = $createdBy; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}