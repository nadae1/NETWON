<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709075654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyse_resultat DROP stats_json');
        $this->addSql('ALTER TABLE analyse_resultat DROP max_trafic_fdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP max_trafic_tdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP nombre_occurrences_tdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP nombre_occurrences_fdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP capacite_mbps');
        $this->addSql('ALTER TABLE analyse_resultat DROP duree_jours');
        $this->addSql('ALTER TABLE analyse_resultat DROP dropcong_tdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP dropcong_fdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP dropcong_tf');
        $this->addSql('ALTER TABLE analyse_resultat DROP taux_utilisation');
        $this->addSql('ALTER TABLE analyse_resultat DROP taux_utilisation_tdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP taux_utilisation_fdd');
        $this->addSql('ALTER TABLE ticket_history DROP date_jour');
        $this->addSql('ALTER TABLE ticket_history DROP max_trafic');
        $this->addSql('ALTER TABLE ticket_history DROP capacite_mbps');
        $this->addSql('ALTER TABLE ticket_history ALTER site DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyse_resultat ADD stats_json JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE analyse_resultat ADD max_trafic_fdd NUMERIC(15, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyse_resultat ADD max_trafic_tdd NUMERIC(15, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyse_resultat ADD nombre_occurrences_tdd INT DEFAULT 0');
        $this->addSql('ALTER TABLE analyse_resultat ADD nombre_occurrences_fdd INT DEFAULT 0');
        $this->addSql('ALTER TABLE analyse_resultat ADD capacite_mbps NUMERIC(15, 4) DEFAULT \'0\'');
        $this->addSql('ALTER TABLE analyse_resultat ADD duree_jours INT DEFAULT 7');
        $this->addSql('ALTER TABLE analyse_resultat ADD dropcong_tdd INT DEFAULT 0');
        $this->addSql('ALTER TABLE analyse_resultat ADD dropcong_fdd INT DEFAULT 0');
        $this->addSql('ALTER TABLE analyse_resultat ADD dropcong_tf INT DEFAULT 0');
        $this->addSql('ALTER TABLE analyse_resultat ADD taux_utilisation NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyse_resultat ADD taux_utilisation_tdd NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyse_resultat ADD taux_utilisation_fdd NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_history ADD date_jour DATE NOT NULL');
        $this->addSql('ALTER TABLE ticket_history ADD max_trafic DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_history ADD capacite_mbps DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_history ALTER site SET NOT NULL');
    }
}
