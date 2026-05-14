<?php

namespace App\Controller;

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
    if ($this->isGranted('ROLE_ADMIN')) {
        return $this->redirectToRoute('admin_dashboard_home');
    }
    if ($this->isGranted('ROLE_SUPERUSER')) {
        return $this->redirectToRoute('superuser_dashboard_home');
    }
    // Utilisateur standard
    /** @var User $user */
    $user = $this->getUser();
    $service = $user->getService();
    
    return match ($service) {
        'IP' => $this->redirectToRoute('user_ip_dashboard'),
        'TRANSMISSION' => $this->redirectToRoute('user_transmission_tickets'),
        'DEPLOIEMENT' => $this->redirectToRoute('user_deploiement_tickets'),
        'INGENIERIE_CAPILLAIRE' => $this->redirectToRoute('user_capillaire_tickets'),
        'RADIO' => $this->redirectToRoute('user_radio_tickets'),
        'BACKHAUL' => $this->redirectToRoute('user_backhaul_tickets'),
        'DEPLOIEMENT_TELECOM' => $this->redirectToRoute('user_deploiement_telecom_tickets'),
        default => $this->redirectToRoute('user_dashboard_home'),
    };
}

}