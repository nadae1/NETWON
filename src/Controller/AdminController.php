<?php

namespace App\Controller;

use App\Entity\Service;
use App\Entity\User;
use App\Form\AdminUserType;
use App\Form\ServiceType;
use App\Repository\ServiceRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function listUsers(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $users = $userRepository->findAll();
        return $this->render('dashboard/admin/users.html.twig', ['users' => $users]);
    }

    #[Route('/user/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function newUser(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = new User();
        $form = $this->createForm(AdminUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }
            $em->persist($user);
            $em->flush();
            $this->addFlash('success', sprintf('Utilisateur %s créé avec succès.', $user->getUsername()));
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('dashboard/admin/user_form.html.twig', [
            'form' => $form->createView(),
            'title' => 'Créer un nouvel utilisateur',
        ]);
    }

    #[Route('/user/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function editUser(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $form = $this->createForm(AdminUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }
            $em->flush();
            $this->addFlash('success', sprintf('Utilisateur %s mis à jour.', $user->getUsername()));
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('dashboard/admin/user_form.html.twig', [
            'form' => $form->createView(),
            'title' => 'Éditer l\'utilisateur ' . $user->getUsername(),
        ]);
    }

    #[Route('/user/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $username = $user->getUsername();
        $em->remove($user);
        $em->flush();
        $this->addFlash('success', sprintf('Utilisateur %s supprimé.', $username));
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/services', name: 'admin_services', methods: ['GET'])]
    public function listServices(ServiceRepository $serviceRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $services = $serviceRepository->findAll();
        return $this->render('dashboard/admin/services.html.twig', ['services' => $services]);
    }

    #[Route('/service/new', name: 'admin_service_new', methods: ['GET', 'POST'])]
    public function newService(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $service = new Service();
        $form = $this->createForm(ServiceType::class, $service);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($service);
            $em->flush();
            $this->addFlash('success', sprintf('Service %s créé avec succès.', $service->getName()));
            return $this->redirectToRoute('admin_services');
        }

        return $this->render('dashboard/admin/service_form.html.twig', [
            'form' => $form->createView(),
            'title' => 'Créer un nouveau service',
        ]);
    }

    #[Route('/service/{id}/edit', name: 'admin_service_edit', methods: ['GET', 'POST'])]
    public function editService(
        Service $service,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $form = $this->createForm(ServiceType::class, $service);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', sprintf('Service %s mis à jour.', $service->getName()));
            return $this->redirectToRoute('admin_services');
        }

        return $this->render('dashboard/admin/service_form.html.twig', [
            'form' => $form->createView(),
            'title' => 'Éditer le service ' . $service->getName(),
        ]);
    }

  #[Route('/stats', name: 'admin_stats', methods: ['GET'])]
    public function stats(
        TicketRepository $ticketRepository,
        UserRepository $userRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $statsByService = [];
        $statsByStatus = [];
        $allTickets = $ticketRepository->findAll();

        foreach ($allTickets as $ticket) {
            $service = $ticket->getService() ?? 'N/A';
            $statsByService[$service] = ($statsByService[$service] ?? 0) + 1;
            $status = $ticket->getStatus() ?? 'N/A';
            $statsByStatus[$status] = ($statsByStatus[$status] ?? 0) + 1;
        }

        return $this->render('dashboard/admin/stats.html.twig', [
            'statsByService' => $statsByService,
            'statsByStatus' => $statsByStatus,
            'totalUsers' => count($userRepository->findAll()),
        ]);
    }
}