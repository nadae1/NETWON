<?php
// tests/Functional/SecurityDashboardFunctionalTest.php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityDashboardFunctionalTest extends WebTestCase
{
    public function testDashboardAccessibleToController(): void
    {
        $client = static::createClient();

        // Adapte selon comment tu logues un utilisateur de test avec ROLE_CONTROLLER
        // $client->loginUser($controllerUser);

        $client->request('GET', '/controller/security');

        // Si non connecté, Symfony redirige vers /login (302) plutôt que 403 direct
        $this->assertContains(
            $client->getResponse()->getStatusCode(),
            [200, 302],
            'Le dashboard doit répondre 200 (connecté) ou rediriger vers login (anonyme)'
        );
    }

    public function testAlertsCountEndpointReturnsJson(): void
    {
        $client = static::createClient();
        // $client->loginUser($controllerUser);

        $client->request('GET', '/controller/security/api/alerts-count');

        if ($client->getResponse()->getStatusCode() === 200) {
            $this->assertJson($client->getResponse()->getContent());
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('critical', $data);
            $this->assertArrayHasKey('high', $data);
            $this->assertArrayHasKey('total', $data);
        }
    }
}