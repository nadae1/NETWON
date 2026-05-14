<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260430194424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ticket_assigned_users (ticket_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (ticket_id, user_id))');
        $this->addSql('CREATE INDEX IDX_29F38C68700047D2 ON ticket_assigned_users (ticket_id)');
        $this->addSql('CREATE INDEX IDX_29F38C68A76ED395 ON ticket_assigned_users (user_id)');
        $this->addSql('ALTER TABLE ticket_assigned_users ADD CONSTRAINT FK_29F38C68700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_assigned_users ADD CONSTRAINT FK_29F38C68A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_assigned_users DROP CONSTRAINT FK_29F38C68700047D2');
        $this->addSql('ALTER TABLE ticket_assigned_users DROP CONSTRAINT FK_29F38C68A76ED395');
        $this->addSql('DROP TABLE ticket_assigned_users');
    }
}
