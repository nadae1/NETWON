<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260430062751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE processed_site RENAME COLUMN service_name TO service');
        $this->addSql('CREATE INDEX idx_service ON processed_site (service)');
        $this->addSql('CREATE INDEX idx_classification ON processed_site (classification)');
        $this->addSql('CREATE INDEX idx_is_critical ON processed_site (is_critical)');
        $this->addSql('ALTER TABLE workflow_ticket DROP CONSTRAINT fk_b6a51760f4bd7827');
        $this->addSql('DROP INDEX idx_b6a51760f4bd7827');
        $this->addSql('ALTER TABLE workflow_ticket DROP assigned_to_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_service');
        $this->addSql('DROP INDEX idx_classification');
        $this->addSql('DROP INDEX idx_is_critical');
        $this->addSql('ALTER TABLE processed_site RENAME COLUMN service TO service_name');
        $this->addSql('ALTER TABLE workflow_ticket ADD assigned_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_ticket ADD CONSTRAINT fk_b6a51760f4bd7827 FOREIGN KEY (assigned_to_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_b6a51760f4bd7827 ON workflow_ticket (assigned_to_id)');
    }
}
