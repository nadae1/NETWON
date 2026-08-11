<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ControllerUserType;
use App\Repository\UserAccessLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/controller')]
class ControllerDashboardController extends AbstractController
{
    #[Route('', name: 'controller_dashboard_home', methods: ['GET'])]
    public function home(UserRepository $userRepository, UserAccessLogRepository $userAccessLogRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $users = $userRepository->findControllerManagedUsers();
        $logs = $userAccessLogRepository->findAllOrdered();
        $serviceNames = array_values(array_unique(array_filter(array_map(static fn (User $user) => $user->getService(), $users))));

        return $this->render('dashboard/controller/home.html.twig', [
            'users' => $users,
            'recentUsers' => array_slice($users, 0, 5),
            'recentLogs' => array_slice($logs, 0, 5),
            'totalUsers' => count($users),
            'totalServices' => count($serviceNames),
            'totalLogs' => count($logs),
        ]);
    }

    #[Route('/users', name: 'controller_users', methods: ['GET'])]
    public function users(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        return $this->render('dashboard/controller/users.html.twig', [
            'users' => $userRepository->findControllerManagedUsers(),
        ]);
    }

    #[Route('/users/new', name: 'controller_user_new', methods: ['GET', 'POST'])]
    public function newUser(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $form = $this->createForm(ControllerUserType::class, $user, [
            'password_required' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $duplicates = $userRepository->findDuplicateUsernameOrEmail(
                (string) $user->getUsername(),
                $user->getEmail(),
                null
            );

            if (isset($duplicates['username'])) {
                $form->get('username')->addError(new FormError('Un utilisateur avec ce username existe déjà.'));
            }

            if (isset($duplicates['email'])) {
                $form->get('email')->addError(new FormError('Un utilisateur avec cet email existe déjà.'));
            }

            if ($form->isValid()) {
                $this->applyManagedUserData($user, $form);
                $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', sprintf('Utilisateur %s créé avec succès.', $user->getUsername()));

                return $this->redirectToRoute('controller_users');
            }

            $this->addFlash('error', 'Impossible de créer cet utilisateur. Vérifiez les champs saisis.');
        }

        return $this->render('dashboard/controller/user_form.html.twig', [
            'form' => $form->createView(),
            'title' => 'Créer un utilisateur',
            'submitLabel' => 'Créer l’utilisateur',
        ]);
    }

    #[Route('/users/{id}/edit', name: 'controller_user_edit', methods: ['GET', 'POST'])]
    public function editUser(
        int $id,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $user = $userRepository->findControllerManagedUserById($id);

        if (!$user instanceof User) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        $form = $this->createForm(ControllerUserType::class, $user, [
            'password_required' => false,
        ]);
        
        // Pré-remplir les champs serviceChoice et departmentChoice
        $form->get('serviceChoice')->setData($user->getService());
        $form->get('departmentChoice')->setData($user->getDepartment());
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $duplicates = $userRepository->findDuplicateUsernameOrEmail(
                (string) $user->getUsername(),
                $user->getEmail(),
                $user->getId()
            );

            if (isset($duplicates['username'])) {
                $form->get('username')->addError(new FormError('Un utilisateur avec ce username existe déjà.'));
            }

            if (isset($duplicates['email'])) {
                $form->get('email')->addError(new FormError('Un utilisateur avec cet email existe déjà.'));
            }

            if ($form->isValid()) {
                $this->applyManagedUserData($user, $form);
                
                // Mettre à jour le mot de passe seulement si un nouveau est fourni
                $plainPassword = $form->get('plainPassword')->getData();
                if (!empty($plainPassword)) {
                    $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
                }
                
                $entityManager->flush();

                $this->addFlash('success', sprintf('Utilisateur %s mis à jour.', $user->getUsername()));

                return $this->redirectToRoute('controller_users');
            }

            $this->addFlash('error', 'Impossible de modifier cet utilisateur. Vérifiez les champs saisis.');
        }

        return $this->render('dashboard/controller/user_form.html.twig', [
            'form' => $form->createView(),
            'title' => sprintf('Modifier %s', $user->getUsername()),
            'submitLabel' => 'Enregistrer les modifications',
        ]);
    }

    #[Route('/users/{id}/delete', name: 'controller_user_delete', methods: ['POST'])]
    public function deleteUser(
        int $id,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $user = $userRepository->findControllerManagedUserById($id);

        if (!$user instanceof User) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        $username = $user->getUsername();
        $entityManager->remove($user);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Utilisateur %s supprimé avec succès.', $username));

        return $this->redirectToRoute('controller_users');
    }

    #[Route('/logs', name: 'controller_logs', methods: ['GET'])]
    public function logs(UserRepository $userRepository, UserAccessLogRepository $userAccessLogRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_CONTROLLER');

        $users = $userRepository->findControllerManagedUsers();
        $logsByUserId = [];

        foreach ($userAccessLogRepository->findAllOrdered() as $log) {
            $user = $log->getUser();
            if ($user instanceof User && $user->getId() !== null) {
                $logsByUserId[$user->getId()] = $log;
            }
        }

        return $this->render('dashboard/controller/logs.html.twig', [
            'users' => $users,
            'logsByUserId' => $logsByUserId,
        ]);
    }

    private function applyManagedUserData(User $user, FormInterface $form): void
    {
        $roles = array_values(array_unique(array_filter((array) $form->get('roles')->getData())));
        $allowedRoles = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPERUSER'];
        $filteredRoles = array_values(array_intersect($roles, $allowedRoles));
        if ($filteredRoles === []) {
            $filteredRoles = ['ROLE_USER'];
        }

        $service = $form->get('serviceChoice')->getData();
        $department = $form->get('departmentChoice')->getData();

        // Pour les départements partagés, on peut laisser le service vide ou SHARED
        // On ne force pas le service si le département est partagé
        $user->setRoles($filteredRoles);
        $user->setService($service);
        $user->setDepartment($department);
    }
}