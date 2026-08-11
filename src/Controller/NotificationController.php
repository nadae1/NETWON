<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/notifications')]
class NotificationController extends AbstractController
{
    #[Route('', name: 'dashboard_notifications', methods: ['GET'])]
    public function index(
        Request $request,
        NotificationRepository $notificationRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        $typeFilter = $request->query->get('type', '');
        $limit = 50;

        // Récupérer les notifications filtrées
        if ($typeFilter !== '') {
            $notifications = $notificationRepository->findLatestForUserByType($user, $typeFilter, $limit);
        } else {
            $notifications = $notificationRepository->findLatestForUser($user, $limit);
        }

        // Statistiques
        $stats = [
            'total' => $notificationRepository->countForUser($user),
            'unread' => $notificationRepository->countUnreadForUser($user),
            'types' => $notificationRepository->countByTypeForUser($user),
        ];

        return $this->render('dashboard/notifications/index.html.twig', [
            'notifications' => $notifications,
            'stats' => $stats,
            'currentType' => $typeFilter,
        ]);
    }

    #[Route('/{id}/read', name: 'dashboard_notification_read', methods: ['POST'])]
    public function markRead(Notification $notification, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();
        if ($notification->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $notification->markRead();
        $em->flush();

        $redirect = $request->headers->get('referer');
        if (is_string($redirect) && $redirect !== '') {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('dashboard_notifications');
    }

    #[Route('/mark-all-read', name: 'dashboard_notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        $notifications = $em->getRepository(Notification::class)
            ->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        foreach ($notifications as $n) {
            $n->markRead();
        }
        $em->flush();

        $this->addFlash('success', 'Toutes les notifications ont été marquées comme lues.');
        return $this->redirectToRoute('dashboard_notifications');
    }
}