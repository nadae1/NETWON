<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261220120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workflow enhancements: workflow_type to ticket, requiresCapture/capturePath to ticket_task, status to processed_site';
    }

    public function up(Schema $schema): void
    {
        // Add workflow_type to ticket table
        $this->addSql("
            ALTER TABLE ticket 
            ADD COLUMN IF NOT EXISTS workflow_type VARCHAR(20) DEFAULT NULL
        ");

        // Add requiresCapture and capturePath to ticket_task table
        $this->addSql("
            ALTER TABLE ticket_task 
            ADD COLUMN IF NOT EXISTS requires_capture BOOLEAN DEFAULT FALSE
        ");

        $this->addSql("
            ALTER TABLE ticket_task 
            ADD COLUMN IF NOT EXISTS capture_path VARCHAR(255) DEFAULT NULL
        ");

        // Add status to processed_site table
        $this->addSql("
            ALTER TABLE processed_site 
            ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // Rollback
        $this->addSql("
            ALTER TABLE ticket 
            DROP COLUMN IF EXISTS workflow_type
        ");

        $this->addSql("
            ALTER TABLE ticket_task 
            DROP COLUMN IF EXISTS requires_capture
        ");

        $this->addSql("
            ALTER TABLE ticket_task 
            DROP COLUMN IF EXISTS capture_path
        ");

        $this->addSql("
            ALTER TABLE processed_site 
            DROP COLUMN IF EXISTS status
        ");
    }
}
