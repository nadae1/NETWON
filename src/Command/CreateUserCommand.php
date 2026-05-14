<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Créer un utilisateur NetWON avec mot de passe hashé'
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $output->writeln('');
        $output->writeln('<info>=== Création d\'un utilisateur NetWON ===</info>');
        $output->writeln('');

        $usernameQuestion = new Question('Username: ');
        $usernameQuestion->setValidator(function (?string $value) {
            $value = trim((string) $value);

            if ($value === '') {
                throw new \RuntimeException('Le username est obligatoire.');
            }

            if (mb_strlen($value) > 100) {
                throw new \RuntimeException('Le username est trop long.');
            }

            if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
                throw new \RuntimeException('Le username contient des caractères non autorisés.');
            }

            return $value;
        });

        $username = $helper->ask($input, $output, $usernameQuestion);

        $existingUser = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        if ($existingUser) {
            $output->writeln('<error>Un utilisateur avec ce username existe déjà.</error>');
            return Command::FAILURE;
        }

        $passwordQuestion = new Question('Password: ');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);
        $passwordQuestion->setValidator(function (?string $value) {
            $value = (string) $value;

            if ($value === '') {
                throw new \RuntimeException('Le mot de passe est obligatoire.');
            }

            if (mb_strlen($value) < 6) {
                throw new \RuntimeException('Le mot de passe doit contenir au moins 6 caractères.');
            }

            if (mb_strlen($value) > 255) {
                throw new \RuntimeException('Le mot de passe est trop long.');
            }

            return $value;
        });

        $password = $helper->ask($input, $output, $passwordQuestion);

        $roleQuestion = new ChoiceQuestion(
            'Choisir le rôle:',
            [
                'ROLE_USER' => 'ROLE_USER',
                'ROLE_ADMIN' => 'ROLE_ADMIN',
                'ROLE_SUPERUSER' => 'ROLE_SUPERUSER',
            ],
            'ROLE_USER'
        );
        $roleQuestion->setErrorMessage('Rôle invalide.');

        $selectedRole = $helper->ask($input, $output, $roleQuestion);

        $service = null;
        $department = null;

        if ($selectedRole === 'ROLE_USER') {
            $serviceQuestion = new ChoiceQuestion(
                'Choisir le service:',
                [
                    'FO' => 'FO',
                    'FH' => 'FH',
                    'SHARED' => 'SHARED',
                ],
                'FO'
            );
            $serviceQuestion->setErrorMessage('Service invalide.');

            $service = $helper->ask($input, $output, $serviceQuestion);

            $departmentChoices = match ($service) {
                'FO' => [
                    'support_fo' => 'support_fo',
                    'ingenierie_ip' => 'ingenierie_ip',
                ],
                'FH' => [
                    'support_fh' => 'support_fh',
                    'ingenierie_fh' => 'ingenierie_fh',
                ],
                'SHARED' => [
                    'deploiement' => 'deploiement',
                ],
                default => [],
            };

            $departmentQuestion = new ChoiceQuestion(
                'Choisir le département:',
                $departmentChoices,
                array_key_first($departmentChoices)
            );
            $departmentQuestion->setErrorMessage('Département invalide.');

            $department = $helper->ask($input, $output, $departmentQuestion);
        }

        $emailQuestion = new Question('Email (optionnel): ', '');
        $emailQuestion->setValidator(function (?string $value) {
            $value = trim((string) $value);

            if ($value === '') {
                return null;
            }

            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Email invalide.');
            }

            if (mb_strlen($value) > 180) {
                throw new \RuntimeException('Email trop long.');
            }

            return $value;
        });

        $email = $helper->ask($input, $output, $emailQuestion);

        $user = new User();
        $user->setUsername($username);
        $user->setRoles([$selectedRole]);
        $user->setService($service);
        $user->setDepartment($department);
        $user->setEmail($email);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln('');
        $output->writeln('<info>Utilisateur créé avec succès ✅</info>');
        $output->writeln('Username: ' . $username);
        $output->writeln('Role: ' . $selectedRole);
        $output->writeln('Service: ' . ($service ?? 'NULL'));
        $output->writeln('Department: ' . ($department ?? 'NULL'));
        $output->writeln('');

        return Command::SUCCESS;
    }
}