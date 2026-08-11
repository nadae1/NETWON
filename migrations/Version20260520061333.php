<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520061333 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_task ALTER site_validated TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D502CB44E FOREIGN KEY (next_assigned_to_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_60A5CE1D502CB44E ON ticket_task (next_assigned_to_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D502CB44E');
        $this->addSql('DROP INDEX IDX_60A5CE1D502CB44E');
        $this->addSql('ALTER TABLE ticket_task ALTER site_validated TYPE BOOLEAN');
    }
}
