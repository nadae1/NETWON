<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261205000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workflow columns step_code and decision to ticket_task (safe)';
    }

    public function up(Schema $schema): void
    {
        // ✅ PostgreSQL safe: add only if not exists
        $this->addSql("
            ALTER TABLE ticket_task 
            ADD COLUMN IF NOT EXISTS step_code VARCHAR(100) DEFAULT NULL
        ");

        $this->addSql("
            ALTER TABLE ticket_task 
            ADD COLUMN IF NOT EXISTS decision VARCHAR(50) DEFAULT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // ✅ rollback safe
        $this->addSql("
            ALTER TABLE ticket_task 
            DROP COLUMN IF EXISTS step_code
        ");

        $this->addSql("
            ALTER TABLE ticket_task 
            DROP COLUMN IF EXISTS decision
        ");
    }
}