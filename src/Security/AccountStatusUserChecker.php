<?php
// src/Security/AccountStatusUserChecker.php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class AccountStatusUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isLocked()) {
            throw new CustomUserMessageAccountStatusException(
                'Ce compte a été verrouillé suite à une activité suspecte. Contactez votre administrateur.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Rien de spécifique après authentification pour le moment
    }
}