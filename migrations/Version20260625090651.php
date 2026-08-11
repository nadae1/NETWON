<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625090651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_history ADD site VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE ticket_history ADD date_jour DATE NOT NULL');
        $this->addSql('ALTER TABLE ticket_history ADD max_trafic DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_history ADD capacite_mbps DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_history DROP site');
        $this->addSql('ALTER TABLE ticket_history DROP date_jour');
        $this->addSql('ALTER TABLE ticket_history DROP max_trafic');
        $this->addSql('ALTER TABLE ticket_history DROP capacite_mbps');
    }
}
