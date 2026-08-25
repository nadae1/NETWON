<?php
// src/Controller/SecurityController.php

namespace App\Controller;

use App\Entity\Security\SecurityEvent;
use App\Entity\User;
use App\Repository\Security\BlockedIpRepository;
use App\Repository\Security\SecurityEventRepository;
use App\Repository\UserRepository;
use App\Service\Security\AccountLockService;
use App\Service\Security\IpBlocklistService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/controller/security')]
class SecurityController extends AbstractController
{
    #[Route('', name: 'controller_security_dashboard', methods: ['GET'])]
    public function dashboard(SecurityEventRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $since24h = new \DateTimeImmutable('-24 hours');
        $since7d = new \DateTimeImmutable('-7 days');

        $countsLast24h = $repository->countByTypeSince($since24h);
        $countsLast7d = $repository->countByTypeSince($since7d);

        return $this->render('dashboard/controller/security_dashboard.html.twig', [
            'countsLast24h' => $countsLast24h,
            'countsLast7d' => $countsLast7d,
            'totalLast24h' => array_sum($countsLast24h),
            'totalLast7d' => array_sum($countsLast7d),
            'unresolvedBySeverity' => $repository->countUnresolvedBySeverity(),
            'recentEvents' => $repository->findRecent(limit: 15),
            'recentAlerts' => $repository->findRecentUnresolvedAlerts(10),
        ]);
    }

    #[Route('/events', name: 'controller_security_events', methods: ['GET'])]
    public function events(Request $request, SecurityEventRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $type = $request->query->get('type') ?: null;
        $severity = $request->query->get('severity') ?: null;
        $resolvedParam = $request->query->get('resolved');
        $resolved = match ($resolvedParam) {
            '1' => true,
            '0' => false,
            default => null,
        };
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;

        $events = $repository->findFiltered($type, $severity, $resolved, $page, $perPage);
        $total = $repository->countFiltered($type, $severity, $resolved);

        return $this->render('dashboard/controller/security_events.html.twig', [
            'events' => $events,
            'types' => SecurityEvent::TYPES,
            'severities' => SecurityEvent::SEVERITIES,
            'currentType' => $type,
            'currentSeverity' => $severity,
            'currentResolved' => $resolvedParam,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
            'total' => $total,
        ]);
    }

    #[Route('/user/{id}', name: 'controller_security_user_activity', methods: ['GET'])]
    public function userActivity(
        int $id,
        UserRepository $userRepository,
        SecurityEventRepository $repository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $user = $userRepository->find($id);

        if (!$user instanceof User) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        return $this->render('dashboard/controller/security_user_activity.html.twig', [
            'managedUser' => $user,
            'events' => $repository->findByUser($id, 100),
        ]);
    }

    #[Route('/events/{id}/resolve', name: 'controller_security_event_resolve', methods: ['POST'])]
    public function resolve(
        int $id,
        SecurityEventRepository $repository,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $event = $repository->find($id);

        if (!$event instanceof SecurityEvent) {
            throw $this->createNotFoundException('Événement introuvable.');
        }

        $event->setResolved(true);
        $entityManager->flush();

        $this->addFlash('success', 'Événement marqué comme traité.');

        return $this->redirectToRoute('controller_security_events');
    }

    #[Route('/events/{id}/ban-ip', name: 'controller_security_ban_ip', methods: ['POST'])]
    public function banIp(
        int $id,
        SecurityEventRepository $eventRepository,
        IpBlocklistService $blocklistService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $event = $eventRepository->find($id);

        if (!$event instanceof SecurityEvent) {
            throw $this->createNotFoundException('Événement introuvable.');
        }

        try {
            /** @var User $controller */
            $controller = $this->getUser();
            $blocklistService->blockIp(
                $event->getIpAddress(),
                sprintf('Banni suite à l\'événement #%d (%s)', $event->getId(), $event->getType()),
                $controller instanceof User ? $controller : null
            );

            $event->setResolved(true);
            $this->addFlash('success', sprintf('L\'adresse %s a été bannie.', $event->getIpAddress()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('controller_security_events');
    }

    #[Route('/events/{id}/lock-account', name: 'controller_security_lock_account', methods: ['POST'])]
    public function lockAccount(
        int $id,
        SecurityEventRepository $eventRepository,
        AccountLockService $lockService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $event = $eventRepository->find($id);

        if (!$event instanceof SecurityEvent) {
            throw $this->createNotFoundException('Événement introuvable.');
        }

        $reason = sprintf('Verrouillé suite à l\'événement #%d (%s)', $event->getId(), $event->getType());

        $user = $event->getUser();
        if ($user instanceof User) {
            $lockService->lockUser($user, $reason);
        } elseif ($event->getAttemptedIdentifier() !== null) {
            $user = $lockService->lockByIdentifier($event->getAttemptedIdentifier(), $reason);
        }

        if ($user === null) {
            $this->addFlash('error', 'Aucun compte correspondant n\'a été trouvé pour cet événement.');
        } else {
            $event->setResolved(true);
            $this->addFlash('success', sprintf('Le compte %s a été verrouillé.', $user->getUsername()));
        }

        return $this->redirectToRoute('controller_security_events');
    }

    /**
     * Déverrouille un compte directement depuis sa fiche d'activité.
     */
    #[Route('/user/{id}/unlock', name: 'controller_security_unlock_account', methods: ['POST'])]
    public function unlockAccount(
        int $id,
        UserRepository $userRepository,
        AccountLockService $lockService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $user = $userRepository->find($id);

        if (!$user instanceof User) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        if (!$user->isLocked()) {
            $this->addFlash('error', 'Ce compte n\'est pas verrouillé.');
            return $this->redirectToRoute('controller_security_user_activity', ['id' => $id]);
        }

        $lockService->unlockUser($user);
        $this->addFlash('success', sprintf('Le compte %s a été déverrouillé.', $user->getUsername()));

        return $this->redirectToRoute('controller_security_user_activity', ['id' => $id]);
    }

    /**
     * Vue d'ensemble de tous les comptes actuellement verrouillés,
     * symétrique à la page "IPs bannies" — permet un déverrouillage
     * groupé sans devoir chercher chaque utilisateur individuellement.
     */
    #[Route('/locked-accounts', name: 'controller_security_locked_accounts', methods: ['GET'])]
    public function lockedAccounts(AccountLockService $lockService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        return $this->render('dashboard/controller/security_locked_accounts.html.twig', [
            'lockedUsers' => $lockService->findLockedUsers(),
        ]);
    }

    #[Route('/blocked-ips', name: 'controller_security_blocked_ips', methods: ['GET'])]
    public function blockedIps(BlockedIpRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        return $this->render('dashboard/controller/security_blocked_ips.html.twig', [
            'blockedIps' => $repository->findAllOrdered(),
        ]);
    }

    #[Route('/blocked-ips/{ip}/unblock', name: 'controller_security_unblock_ip', methods: ['POST'])]
    public function unblockIp(string $ip, IpBlocklistService $blocklistService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        if ($blocklistService->unblockIp($ip)) {
            $this->addFlash('success', sprintf('L\'adresse %s a été débloquée.', $ip));
        } else {
            $this->addFlash('error', 'Cette adresse n\'était pas bannie.');
        }

        return $this->redirectToRoute('controller_security_blocked_ips');
    }

    #[Route('/api/alerts-count', name: 'controller_security_alerts_count', methods: ['GET'])]
    public function alertsCount(SecurityEventRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $counts = $repository->countUnresolvedBySeverity();

        return $this->json([
            'critical' => $counts[SecurityEvent::SEVERITY_CRITICAL],
            'high' => $counts[SecurityEvent::SEVERITY_HIGH],
            'total' => $counts[SecurityEvent::SEVERITY_CRITICAL] + $counts[SecurityEvent::SEVERITY_HIGH],
        ]);
    }

    #[Route('/events/export', name: 'controller_security_events_export', methods: ['GET'])]
    public function exportEventsCsv(Request $request, SecurityEventRepository $repository): StreamedResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $type = $request->query->get('type') ?: null;
        $severity = $request->query->get('severity') ?: null;
        $resolvedParam = $request->query->get('resolved');
        $resolved = match ($resolvedParam) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $events = $repository->findFiltered($type, $severity, $resolved, page: 1, perPage: 10000);

        $response = new StreamedResponse(function () use ($events) {
            $handle = fopen('php://output', 'w+');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'ID', 'Type', 'Sévérité', 'Utilisateur', 'Identifiant tenté',
                'Adresse IP', 'Route', 'Méthode HTTP', 'Statut', 'Date',
            ], ';');

            foreach ($events as $event) {
                fputcsv($handle, [
                    $event->getId(),
                    $event->getType(),
                    $event->getSeverity(),
                    $event->getUser()?->getUsername() ?? '-',
                    $event->getAttemptedIdentifier() ?? '-',
                    $event->getIpAddress(),
                    $event->getRoute() ?? '-',
                    $event->getHttpMethod() ?? '-',
                    $event->isResolved() ? 'Traité' : 'Non traité',
                    $event->getCreatedAt()->format('d/m/Y H:i:s'),
                ], ';');
            }

            fclose($handle);
        });

        $filename = sprintf('security_events_%s.csv', (new \DateTimeImmutable())->format('Y-m-d_His'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }

    #[Route('/stats/export', name: 'controller_security_stats_export', methods: ['GET'])]
    public function exportStatsCsv(SecurityEventRepository $repository): StreamedResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $since24h = new \DateTimeImmutable('-24 hours');
        $since7d = new \DateTimeImmutable('-7 days');

        $countsLast24h = $repository->countByTypeSince($since24h);
        $countsLast7d = $repository->countByTypeSince($since7d);
        $unresolvedBySeverity = $repository->countUnresolvedBySeverity();

        $response = new StreamedResponse(function () use ($countsLast24h, $countsLast7d, $unresolvedBySeverity) {
            $handle = fopen('php://output', 'w+');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['Statistiques de sécurité NetWON — généré le '.(new \DateTimeImmutable())->format('d/m/Y H:i:s')], ';');
            fputcsv($handle, [], ';');

            fputcsv($handle, ['Répartition par type d\'événement'], ';');
            fputcsv($handle, ['Type', 'Occurrences (24h)', 'Occurrences (7 jours)'], ';');
            foreach ($countsLast24h as $type => $count24h) {
                fputcsv($handle, [$type, $count24h, $countsLast7d[$type] ?? 0], ';');
            }

            fputcsv($handle, [], ';');
            fputcsv($handle, ['Alertes non traitées par sévérité'], ';');
            fputcsv($handle, ['Sévérité', 'Nombre'], ';');
            foreach ($unresolvedBySeverity as $severity => $count) {
                fputcsv($handle, [$severity, $count], ';');
            }

            fclose($handle);
        });

        $filename = sprintf('security_stats_%s.csv', (new \DateTimeImmutable())->format('Y-m-d_His'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }
}