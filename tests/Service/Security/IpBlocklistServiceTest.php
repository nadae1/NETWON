<?php
// tests/Service/Security/IpBlocklistServiceTest.php

namespace App\Tests\Service\Security;

use App\Repository\Security\BlockedIpRepository;
use App\Service\Security\IpBlocklistService;
use PHPUnit\Framework\TestCase;

class IpBlocklistServiceTest extends TestCase
{
    public function testCannotBlockWhitelistedLocalIp(): void
    {
        $repository = $this->createMock(BlockedIpRepository::class);
        $repository->expects($this->never())->method('save'); // ne doit JAMAIS être appelé

        $service = new IpBlocklistService($repository, ['127.0.0.1', '::1', 'localhost']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('whitelist');

        $service->blockIp('127.0.0.1', 'test', null);
    }

    public function testCanBlockNonWhitelistedIp(): void
    {
        $repository = $this->createMock(BlockedIpRepository::class);
        $repository->method('isBlocked')->willReturn(false);
        $repository->expects($this->once())->method('save');

        $service = new IpBlocklistService($repository, ['127.0.0.1', '::1']);

        $blockedIp = $service->blockIp('203.0.113.42', 'brute-force détecté', null);

        $this->assertSame('203.0.113.42', $blockedIp->getIpAddress());
    }

    public function testWhitelistedIpNeverReportedAsBlocked(): void
    {
        $repository = $this->createMock(BlockedIpRepository::class);
        // Même si la BDD dit "bloqué" par erreur, isBlocked() du service doit renvoyer false
        $repository->method('isBlocked')->willReturn(true);

        $service = new IpBlocklistService($repository, ['127.0.0.1']);

        $this->assertFalse($service->isBlocked('127.0.0.1'));
    }
}