<?php

namespace App\Service\Security;

use App\Entity\Security\SecurityEvent;
use App\Entity\User;
use App\Repository\Security\SecurityEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class SecurityAuditService
{
    // Seuil de tentatives échouées avant déclenchement d'une alerte brute-force
    private const BRUTE_FORCE_THRESHOLD = 5;
    private const BRUTE_FORCE_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly SecurityEventRepository $repository,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $securityLogger, // channel "security" (voir monolog.yaml)
    ) {
    }

    public function log(
        string $type,
        string $severity,
        ?User $user = null,
        ?string $attemptedIdentifier = null,
        ?array $context = null
    ): SecurityEvent {
        $request = $this->requestStack->getCurrentRequest();

        $event = new SecurityEvent();
        $event->setType($type)
            ->setSeverity($severity)
            ->setUser($user)
            ->setAttemptedIdentifier($attemptedIdentifier)
            ->setIpAddress($request?->getClientIp() ?? '0.0.0.0')
            ->setUserAgent($request?->headers->get('User-Agent'))
            ->setRoute($request?->attributes->get('_route'))
            ->setHttpMethod($request?->getMethod())
            ->setContext($context);

        $this->repository->save($event);

        // Doublon dans les logs fichiers (redondance en cas de compromission BDD)
        $this->securityLogger->log(
            $this->mapSeverityToLogLevel($severity),
            sprintf('[%s] %s', $type, $attemptedIdentifier ?? $user?->getUserIdentifier() ?? 'anonyme'),
            [
                'ip' => $event->getIpAddress(),
                'route' => $event->getRoute(),
                'context' => $context,
            ]
        );

        return $event;
    }

    
/**
 * Enregistre un échec de connexion ET vérifie si le seuil de brute-force est dépassé.
 * Ne déclenche l'alerte BRUTE_FORCE qu'UNE SEULE FOIS par fenêtre de temps
 * (au moment précis où le seuil est franchi), pas à chaque échec supplémentaire.
 */
public function logLoginFailureAndCheckBruteForce(string $identifier): bool
{
    $since = new \DateTimeImmutable(sprintf('-%d minutes', self::BRUTE_FORCE_WINDOW_MINUTES));
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request?->getClientIp() ?? '0.0.0.0';

    // On compte AVANT d'enregistrer ce nouvel échec, pour connaître l'état précédent
    $failuresByIpBefore = $this->repository->countRecentFailuresByIp($ip, $since);
    $failuresByIdentifierBefore = $this->repository->countRecentFailuresByIdentifier($identifier, $since);

    $this->log(
        SecurityEvent::TYPE_LOGIN_FAILED,
        SecurityEvent::SEVERITY_MEDIUM,
        null,
        $identifier
    );

    $failuresByIpAfter = $failuresByIpBefore + 1;
    $failuresByIdentifierAfter = $failuresByIdentifierBefore + 1;

    $wasAlreadyOverThreshold = $failuresByIpBefore >= self::BRUTE_FORCE_THRESHOLD
        || $failuresByIdentifierBefore >= self::BRUTE_FORCE_THRESHOLD;
    $isNowOverThreshold = $failuresByIpAfter >= self::BRUTE_FORCE_THRESHOLD
        || $failuresByIdentifierAfter >= self::BRUTE_FORCE_THRESHOLD;

    // On ne déclenche l'alerte que lors du FRANCHISSEMENT du seuil, pas à chaque échec après
    if ($isNowOverThreshold && !$wasAlreadyOverThreshold) {
        $this->log(
            SecurityEvent::TYPE_BRUTE_FORCE,
            SecurityEvent::SEVERITY_CRITICAL,
            null,
            $identifier,
            [
                'failures_by_ip' => $failuresByIpAfter,
                'failures_by_identifier' => $failuresByIdentifierAfter,
                'window_minutes' => self::BRUTE_FORCE_WINDOW_MINUTES,
            ]
        );
        return true;
    }

    return $isNowOverThreshold; // reste "vrai" en interne, mais sans re-logger
}

    private function mapSeverityToLogLevel(string $severity): int
    {
        return match ($severity) {
            SecurityEvent::SEVERITY_CRITICAL => \Monolog\Level::Critical->value,
            SecurityEvent::SEVERITY_HIGH => \Monolog\Level::Error->value,
            SecurityEvent::SEVERITY_MEDIUM => \Monolog\Level::Warning->value,
            default => \Monolog\Level::Info->value,
        };
    }
}