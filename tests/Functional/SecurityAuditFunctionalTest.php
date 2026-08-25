<?php
// tests/Functional/SecurityAuditFunctionalTest.php

namespace App\Tests\Functional;

use App\Entity\Security\SecurityEvent;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityAuditFunctionalTest extends WebTestCase
{
    public function testFailedLoginCreatesSecurityEvent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        // ⚠️ Adapte les noms de champs ci-dessous une fois que tu as vérifié
        // le HTML réel du formulaire (voir diagnostic ci-dessus).
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'utilisateur_qui_nexiste_pas@test.com',
            '_password' => 'mauvais_mot_de_passe',
        ]);
        $client->submit($form);

        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $repository = $entityManager->getRepository(SecurityEvent::class);

        $events = $repository->findBy(['attemptedIdentifier' => 'utilisateur_qui_nexiste_pas@test.com']);

        $this->assertNotEmpty($events, 'Un SecurityEvent doit être créé après un échec de connexion');
        $this->assertSame(SecurityEvent::TYPE_LOGIN_FAILED, $events[0]->getType());
    }

    // Ce test est désactivé tant que le SecurityController (Axe 4) n'est pas créé.
    // On le réactivera quand la route /admin/security/dashboard existera.
    public function testAccessDeniedIsLogged(): void
    {
        $this->markTestSkipped('En attente du SecurityController (Axe 4 - dashboard admin).');
    }
}