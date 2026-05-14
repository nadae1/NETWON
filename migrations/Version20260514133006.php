<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514133006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_task ADD site_validated VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD next_assigned_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D502CB44E FOREIGN KEY (next_assigned_to_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_60A5CE1D502CB44E ON ticket_task (next_assigned_to_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D502CB44E');
        $this->addSql('DROP INDEX IDX_60A5CE1D502CB44E');
        $this->addSql('ALTER TABLE ticket_task DROP site_validated');
        $this->addSql('ALTER TABLE ticket_task DROP started_at');
        $this->addSql('ALTER TABLE ticket_task DROP next_assigned_to_id');
    }
}
