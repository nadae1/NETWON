<?php
// src/EventSubscriber/SecurityAuditSubscriber.php

namespace App\EventSubscriber;

use App\Entity\Security\SecurityEvent;
use App\Entity\User;
use App\Service\Security\SecurityAuditService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class SecurityAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SecurityAuditService $auditService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            // Priorité élevée pour intercepter l'exception avant les autres listeners
            'kernel.exception' => ['onKernelException', 10],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        $this->auditService->log(
            SecurityEvent::TYPE_LOGIN_SUCCESS,
            SecurityEvent::SEVERITY_LOW,
            $user instanceof User ? $user : null,
            $user->getUserIdentifier()
        );
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // Le passport contient le "badge" avec l'identifiant tenté (même si le user n'existe pas)
        $identifier = 'inconnu';
        $passport = $event->getPassport();

        if ($passport !== null && $passport->hasBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)) {
            /** @var \Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge $badge */
            $badge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);
            $identifier = $badge->getUserIdentifier();
        }

        $this->auditService->logLoginFailureAndCheckBruteForce($identifier);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof AccessDeniedException) {
            return;
        }

        $user = $event->getRequest()->attributes->get('_security_user'); // fallback si dispo
        $token = null;

        // Récupération plus fiable via le token storage n'est pas dispo ici directement,
        // donc on journalise ce qu'on a côté requête ; le user peut être null (anonyme).
        $this->auditService->log(
            SecurityEvent::TYPE_ACCESS_DENIED,
            SecurityEvent::SEVERITY_HIGH,
            $user instanceof User ? $user : null,
            null,
            [
                'exception_message' => $exception->getMessage(),
                'attempted_route' => $event->getRequest()->attributes->get('_route'),
            ]
        );
    }
}