<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260513080845 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_4c013b0ccb8d3e5f RENAME TO IDX_910E9413E5C9BC4D');
        $this->addSql('ALTER INDEX idx_4c013b0ca7b4a0a8 RENAME TO IDX_910E9413E26B496B');
        $this->addSql('ALTER TABLE processed_site ADD recommended_action VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER INDEX idx_3a6d145f700047d2 RENAME TO IDX_3D704F6E700047D2');
        $this->addSql('ALTER INDEX idx_3a6d145f1d3eab4e RENAME TO IDX_3D704F6E4DA1E751');
        $this->addSql('ALTER INDEX idx_3a6d145fa7b4a0a8 RENAME TO IDX_3D704F6EE26B496B');
        $this->addSql('DROP INDEX idx_9d7e42e13b7b33e3');
        $this->addSql('DROP INDEX idx_9d7e42e14e6af5f3');
        $this->addSql('ALTER INDEX idx_9d7e42e1b03a8386 RENAME TO IDX_6E55259FB03A8386');
        $this->addSql('ALTER TABLE ticket ALTER priority DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_910e9413e26b496b RENAME TO idx_4c013b0ca7b4a0a8');
        $this->addSql('ALTER INDEX idx_910e9413e5c9bc4d RENAME TO idx_4c013b0ccb8d3e5f');
        $this->addSql('ALTER TABLE processed_site DROP recommended_action');
        $this->addSql('ALTER INDEX idx_3d704f6e700047d2 RENAME TO idx_3a6d145f700047d2');
        $this->addSql('ALTER INDEX idx_3d704f6e4da1e751 RENAME TO idx_3a6d145f1d3eab4e');
        $this->addSql('ALTER INDEX idx_3d704f6ee26b496b RENAME TO idx_3a6d145fa7b4a0a8');
        $this->addSql('CREATE INDEX idx_9d7e42e13b7b33e3 ON subworkflow (parent_ticket_id)');
        $this->addSql('CREATE INDEX idx_9d7e42e14e6af5f3 ON subworkflow (child_ticket_id)');
        $this->addSql('ALTER INDEX idx_6e55259fb03a8386 RENAME TO idx_9d7e42e1b03a8386');
        $this->addSql('ALTER TABLE ticket ALTER priority SET DEFAULT \'medium\'');
    }
}
