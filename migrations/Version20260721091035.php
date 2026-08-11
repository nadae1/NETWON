<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721091035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_site_prediction_site_horizon');
        $this->addSql('DROP INDEX idx_site_prediction_etat');
        $this->addSql('ALTER TABLE site_prediction ADD projection_fiable BOOLEAN DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site_prediction DROP projection_fiable');
        $this->addSql('CREATE UNIQUE INDEX idx_site_prediction_site_horizon ON site_prediction (site, horizon)');
        $this->addSql('CREATE INDEX idx_site_prediction_etat ON site_prediction (etat_predit)');
    }
}
