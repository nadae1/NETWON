<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class AppAuthenticator extends AbstractLoginFormAuthenticator
{
    public const LOGIN_ROUTE = 'app_login';

    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function authenticate(Request $request): Passport
    {
        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');
        $csrfToken = (string) $request->request->get('_csrf_token', '');
        $service = trim((string) $request->request->get('service', ''));
        $department = trim((string) $request->request->get('department', ''));

        if ($username === '' || mb_strlen($username) > 100) {
            throw new CustomUserMessageAuthenticationException('Username invalide.');
        }

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            throw new CustomUserMessageAuthenticationException('Format du username invalide.');
        }

        if ($password === '' || mb_strlen($password) > 255) {
            throw new CustomUserMessageAuthenticationException('Mot de passe invalide.');
        }

        $allowedServices = ['', 'FO', 'FH', 'SHARED'];
        $allowedDepartments = [
            '',
            'support_fo',
            'ingenierie_ip',
            'support_fh',
            'ingenierie_fh',
            'deploiement'
        ];

        if (!in_array($service, $allowedServices, true)) {
            throw new CustomUserMessageAuthenticationException('Service invalide.');
        }

        if (!in_array($department, $allowedDepartments, true)) {
            throw new CustomUserMessageAuthenticationException('Département invalide.');
        }

        $request->getSession()->set('login_service', $service);
        $request->getSession()->set('login_department', $department);

        return new Passport(
            new UserBadge($username),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException('Utilisateur invalide.');
        }

        $selectedService = (string) $request->getSession()->get('login_service', '');
        $selectedDepartment = (string) $request->getSession()->get('login_department', '');

        if (in_array('ROLE_USER', $user->getRoles(), true)) {
            if ($selectedService === '' || $selectedDepartment === '') {
                throw new CustomUserMessageAuthenticationException(
                    'Service et département obligatoires pour un compte ingénieur.'
                );
            }

            if ($selectedService !== (string) $user->getService()) {
                throw new AccessDeniedException('Service invalide.');
            }

            if ($selectedDepartment !== (string) $user->getDepartment()) {
                throw new AccessDeniedException('Département invalide.');
            }

            return new RedirectResponse(
                $this->urlGenerator->generate('user_dashboard_home')
            );
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new RedirectResponse(
                $this->urlGenerator->generate('admin_dashboard_home')
            );
        }

        if (in_array('ROLE_SUPERUSER', $user->getRoles(), true)) {
            return new RedirectResponse(
                $this->urlGenerator->generate('superuser_dashboard_home')
            );
        }

        throw new AccessDeniedException('Rôle non autorisé.');
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}