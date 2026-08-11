<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findByServices(array $services): array
    {
        if (empty($services)) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->andWhere('u.service IN (:services)')
            ->setParameter('services', $services)
            ->orderBy('u.service', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAssignableUsers(): array
    {
        $users = $this->createQueryBuilder('u')
            ->orderBy('u.service', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        return array_filter($users, function (User $user): bool {
            $roles = $user->getRoles();

            return !in_array('ROLE_ADMIN', $roles, true)
                && !in_array('ROLE_SUPERUSER', $roles, true);
        });
    }

    public function isControllerManagedUser(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array('ROLE_USER', $roles, true)
            && !in_array('ROLE_ADMIN', $roles, true)
            && !in_array('ROLE_SUPERUSER', $roles, true)
            && !in_array('ROLE_CONTROLLER', $roles, true);
    }

    /**
     * @return User[]
     */
    public function findControllerManagedUsers(): array
    {
        $users = array_filter($this->findAll(), fn (User $user) => $this->isControllerManagedUser($user));

        usort($users, static function (User $left, User $right): int {
            return strcmp((string) $left->getUsername(), (string) $right->getUsername());
        });

        return array_values($users);
    }

    public function findControllerManagedUserById(int $id): ?User
    {
        $user = $this->find($id);

        if (!$user instanceof User) {
            return null;
        }

        return $this->isControllerManagedUser($user) ? $user : null;
    }

    public function findDuplicateUsernameOrEmail(string $username, ?string $email, ?int $excludeId = null): array
    {
        $duplicates = [];

        $existingUsername = $this->findOneBy(['username' => $username]);
        if ($existingUsername instanceof User && $existingUsername->getId() !== $excludeId) {
            $duplicates['username'] = $existingUsername;
        }

        if ($email !== null && $email !== '') {
            $existingEmail = $this->findOneBy(['email' => $email]);
            if ($existingEmail instanceof User && $existingEmail->getId() !== $excludeId) {
                $duplicates['email'] = $existingEmail;
            }
        }

        return $duplicates;
    }

    /**
     * ✅ CORRIGÉ : la colonne 'roles' est stockée en PostgreSQL comme
     * type 'json' (Doctrine type: 'json'). PostgreSQL interdit
     * l'opérateur LIKE (~~) directement sur du json sans cast explicite
     * -- d'où l'erreur "l'opérateur n'existe pas : json ~~ unknown".
     * DQL ne sachant pas caster proprement une colonne json vers text
     * de façon portable, on passe par du SQL natif avec ::text (même
     * pattern que CheckObservationCommand.php / CheckSupervisionCommand.php
     * dans ce projet), puis on hydrate les entités User via Doctrine.
     *
     * @return User[]
     */
    public function findUsersByRole(string $role): array
    {
        $ids = $this->fetchUserIdsMatchingRoleSql($role);
        return $this->hydrateByIds($ids);
    }

    /**
     * ✅ CORRIGÉ : même bug que findUsersByRole() (LIKE sur colonne json).
     *
     * @return User[]
     */
    public function findUsersByRoleAndService(string $role, string $service): array
    {
        $ids = $this->fetchUserIdsMatchingRoleSql($role, $service);
        return $this->hydrateByIds($ids);
    }

    /**
     * Exécute la recherche par rôle (et service optionnel) en SQL natif,
     * avec un cast roles::text explicite, et retourne les IDs correspondants.
     */
    private function fetchUserIdsMatchingRoleSql(string $role, ?string $service = null): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = 'SELECT id FROM "user" WHERE roles::text LIKE :role';
        $params = ['role' => '%"' . $role . '"%'];

        if ($service !== null) {
            $sql .= ' AND service = :service';
            $params['service'] = $service;
        }

        return $conn->fetchFirstColumn($sql, $params);
    }

    /**
     * Hydrate une liste d'entités User à partir de leurs IDs, en
     * conservant l'ordre alphabétique par username pour un affichage
     * stable (les autres méthodes du repository trient de la même façon).
     *
     * @param array<int|string> $ids
     * @return User[]
     */
    private function hydrateByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }
}