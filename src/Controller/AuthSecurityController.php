<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthSecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        if ($this->getUser()) {
            return $this->redirectUserAfterLogin();
        }

        return $this->redirectToRoute('app_login');
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectUserAfterLogin();
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method is intercepted by the logout key on your firewall.');
    }

    private function redirectUserAfterLogin(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Vérifier d'abord les rôles (priorité)
        if (in_array('ROLE_SUPERUSER', $user->getRoles(), true)) {
            return $this->redirectToRoute('superuser_workflow_index');
        }

        if (in_array('ROLE_CONTROLLER', $user->getRoles(), true)) {
            return $this->redirectToRoute('controller_dashboard_home');
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->redirectToRoute('admin_workflow_index');
        }

        // Si l'utilisateur n'a pas de service, rediriger vers le dashboard générique
        if (!$user->getService()) {
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        $service = strtoupper($user->getService());
        $department = $user->getDepartment() ?? '';

        // Redirection selon le service
        if ($service === 'FO') {
            return $this->redirectToRoute('dashboard_fo_index');
        }

        if ($service === 'FH') {
            return $this->redirectToRoute('user_fh_tasks');
        }

        // Service DEPLOIEMENT – redirection vers le bon département
        if ($service === 'DEPLOIEMENT') {
            if ($department === 'support_radio') {
                return $this->redirectToRoute('user_support_radio_index');
            }
            if ($department === 'support_backhaul') {
                return $this->redirectToRoute('user_support_backhaul_index');
            }
            // Par défaut, Déploiement Télécom
            return $this->redirectToRoute('user_deploiement_index');
        }

        if ($service === 'SHARED') {
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        // Fallback
        return $this->redirectToRoute('user_tasks_dashboard');
    }
}