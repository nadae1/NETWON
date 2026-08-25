<?php
// src/EventSubscriber/IpBlocklistSubscriber.php

namespace App\EventSubscriber;

use App\Service\Security\IpBlocklistService;
use App\Service\Security\SecurityAuditService;
use App\Entity\Security\SecurityEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class IpBlocklistSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly IpBlocklistService $blocklistService,
        private readonly SecurityAuditService $auditService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité haute : on veut bloquer AVANT le firewall Symfony et le routing
            KernelEvents::REQUEST => ['onKernelRequest', 100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $ip = $request->getClientIp();

        if ($ip === null || !$this->blocklistService->isBlocked($ip)) {
            return;
        }

        // On journalise la tentative d'une IP bannie qui persiste à accéder au site
        $this->auditService->log(
            SecurityEvent::TYPE_ACCESS_DENIED,
            SecurityEvent::SEVERITY_HIGH,
            null,
            null,
            ['reason' => 'IP bannie a tenté d\'accéder à la plateforme']
        );

        $event->setResponse(new Response(
            'Accès refusé : votre adresse IP a été bloquée par mesure de sécurité.',
            Response::HTTP_FORBIDDEN
        ));
    }
}