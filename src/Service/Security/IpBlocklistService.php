<?php
// src/Service/Security/IpBlocklistService.php

namespace App\Service\Security;

use App\Entity\Security\BlockedIp;
use App\Entity\User;
use App\Repository\Security\BlockedIpRepository;

class IpBlocklistService
{
    public function __construct(
        private readonly BlockedIpRepository $repository,
        private readonly array $ipWhitelist, // injecté depuis le paramètre security_ip_whitelist
    ) {
    }

    /**
     * Vérifie si une IP est protégée (jamais bannissable), quoi qu'il arrive.
     */
    public function isWhitelisted(string $ipAddress): bool
    {
        return in_array($ipAddress, $this->ipWhitelist, true);
    }

    public function isBlocked(string $ipAddress): bool
    {
        // Une IP whitelistée n'est JAMAIS considérée comme bloquée, même si elle
        // se retrouve par erreur dans la table (sécurité en profondeur).
        if ($this->isWhitelisted($ipAddress)) {
            return false;
        }

        return $this->repository->isBlocked($ipAddress);
    }

    /**
     * @throws \InvalidArgumentException si l'IP est whitelistée (protection dev/démo)
     */
    public function blockIp(string $ipAddress, ?string $reason, ?User $blockedBy): BlockedIp
    {
        if ($this->isWhitelisted($ipAddress)) {
            throw new \InvalidArgumentException(sprintf(
                'Impossible de bannir l\'adresse %s : elle fait partie de la whitelist de sécurité (IP locale/dev).',
                $ipAddress
            ));
        }

        if ($this->repository->isBlocked($ipAddress)) {
            throw new \InvalidArgumentException(sprintf('L\'adresse %s est déjà bannie.', $ipAddress));
        }

        $blockedIp = new BlockedIp();
        $blockedIp->setIpAddress($ipAddress)
            ->setReason($reason)
            ->setBlockedBy($blockedBy);

        $this->repository->save($blockedIp);

        return $blockedIp;
    }

    public function unblockIp(string $ipAddress): bool
    {
        return $this->repository->removeByIp($ipAddress);
    }
}