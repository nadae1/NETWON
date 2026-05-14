<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour ajouter les champs de workflow amélioré
 * - siteValidated: Enregistre le site validé lors de la complétion d'une tâche
 * - startedAt: Enregistre la date de démarrage d'une tâche
 * - nextAssignedTo: Référence l'utilisateur assigné à la tâche suivante
 */
final class Version20260514000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add enhanced workflow fields to TicketTask table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_task ADD COLUMN site_validated VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD COLUMN started_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD COLUMN next_assigned_to_id INT DEFAULT NULL');
        
        // Ajouter la contrainte de clé étrangère
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_NEXT_ASSIGNED_TO FOREIGN KEY (next_assigned_to_id) REFERENCES user(id) ON DELETE SET NULL');
        
        // Ajouter un index pour les performances
        $this->addSql('CREATE INDEX IDX_TICKET_TASK_SITE_VALIDATED ON ticket_task (site_validated)');
        $this->addSql('CREATE INDEX IDX_TICKET_TASK_STARTED_AT ON ticket_task (started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_TICKET_TASK_SITE_VALIDATED ON ticket_task');
        $this->addSql('DROP INDEX IDX_TICKET_TASK_STARTED_AT ON ticket_task');
        $this->addSql('ALTER TABLE ticket_task DROP FOREIGN KEY FK_NEXT_ASSIGNED_TO');
        $this->addSql('ALTER TABLE ticket_task DROP COLUMN site_validated');
        $this->addSql('ALTER TABLE ticket_task DROP COLUMN started_at');
        $this->addSql('ALTER TABLE ticket_task DROP COLUMN next_assigned_to_id');
    }
}
