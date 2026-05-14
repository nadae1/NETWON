<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ResetPasswordController extends AbstractController
{
    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): Response {
        if ($request->isMethod('POST')) {
            $username = trim((string) $request->request->get('username', ''));
            $email = trim((string) $request->request->get('email', ''));

            if ($username === '' || $email === '') {
                $this->addFlash('error', 'Username et email sont obligatoires.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Email invalide.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $user = $userRepository->findOneBy([
                'username' => $username,
                'email' => $email,
            ]);

            if (!$user instanceof User) {
                $this->addFlash('error', 'Aucun utilisateur trouvé avec ces informations.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $code = (string) random_int(100000, 999999);

            $user->setResetCode($code);
            $user->setResetCodeExpiresAt(new \DateTime('+15 minutes'));

            $em->persist($user);
            $em->flush();

            $message = (new Email())
                ->from('noreply@localhost')
                ->to((string) $user->getEmail())
                ->subject('NetWON - Code de réinitialisation')
                ->html(
                    '<div style="font-family:Arial,sans-serif;padding:20px;">'
                    . '<h2 style="color:#ff7900;">NetWON</h2>'
                    . '<p>Bonjour <strong>' . htmlspecialchars((string) $user->getUsername(), ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
                    . '<p>Voici votre code de réinitialisation :</p>'
                    . '<div style="font-size:32px;font-weight:bold;color:#111;margin:20px 0;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<p>Ce code expire dans 15 minutes.</p>'
                    . '</div>'
                );

            $mailer->send($message);

            $request->getSession()->set('reset_username', $user->getUsername());
            $request->getSession()->set('reset_verified', false);

            $this->addFlash('success', 'Un code a été envoyé à votre email.');
            return $this->redirectToRoute('app_verify_reset_code');
        }

        return $this->render('reset_password/forgot_password.html.twig');
    }

    #[Route('/verify-reset-code', name: 'app_verify_reset_code')]
    public function verifyCode(
        Request $request,
        UserRepository $userRepository
    ): Response {
        $username = (string) $request->getSession()->get('reset_username', '');

        if ($username === '') {
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $code = trim((string) $request->request->get('code', ''));

            if (!preg_match('/^\d{6}$/', $code)) {
                $this->addFlash('error', 'Code invalide.');
                return $this->redirectToRoute('app_verify_reset_code');
            }

            $user = $userRepository->findOneBy(['username' => $username]);

            if (!$user instanceof User || !$user->getResetCode() || !$user->getResetCodeExpiresAt()) {
                $this->addFlash('error', 'Demande invalide.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if ($user->getResetCodeExpiresAt() < new \DateTime()) {
                $this->addFlash('error', 'Le code a expiré.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if ($code !== $user->getResetCode()) {
                $this->addFlash('error', 'Code invalide.');
                return $this->redirectToRoute('app_verify_reset_code');
            }

            $request->getSession()->set('reset_verified', true);

            return $this->redirectToRoute('app_reset_password');
        }

        return $this->render('reset_password/verify_code.html.twig');
    }

    #[Route('/reset-password', name: 'app_reset_password')]
    public function resetPassword(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $username = (string) $request->getSession()->get('reset_username', '');
        $verified = (bool) $request->getSession()->get('reset_verified', false);

        if ($username === '' || !$verified) {
            return $this->redirectToRoute('app_forgot_password');
        }

        $user = $userRepository->findOneBy(['username' => $username]);

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            if ($password === '' || $confirmPassword === '') {
                $this->addFlash('error', 'Tous les champs sont obligatoires.');
                return $this->redirectToRoute('app_reset_password');
            }

            if ($password !== $confirmPassword) {
                $this->addFlash('error', 'Les mots de passe ne correspondent pas.');
                return $this->redirectToRoute('app_reset_password');
            }

            if (mb_strlen($password) < 8) {
                $this->addFlash('error', 'Le mot de passe doit contenir au moins 8 caractères.');
                return $this->redirectToRoute('app_reset_password');
            }

            $user->setPassword($passwordHasher->hashPassword($user, $password));
            $user->setResetCode(null);
            $user->setResetCodeExpiresAt(null);

            $em->persist($user);
            $em->flush();

            $request->getSession()->remove('reset_username');
            $request->getSession()->remove('reset_verified');

            $this->addFlash('success', 'Mot de passe réinitialisé avec succès.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset_password.html.twig');
    }
}