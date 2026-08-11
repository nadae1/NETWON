<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add enriched processed_site metrics for site tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS max_trafic_tdd DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS max_trafic_fdd DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS capacite_tdd_mbps DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS capacite_fdd_mbps DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS nombre_occurrences_tdd INT DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS nombre_occurrences_fdd INT DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS site_status VARCHAR(20) DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS final_action_plan VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS taux_utilisation DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS taux_utilisation_tdd DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS taux_utilisation_fdd DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS dropcong_tdd INT DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS dropcong_fdd INT DEFAULT NULL");
        $this->addSql("ALTER TABLE processed_site ADD COLUMN IF NOT EXISTS dropcong_tf INT DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS dropcong_tf");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS dropcong_fdd");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS dropcong_tdd");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS taux_utilisation_fdd");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS taux_utilisation_tdd");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS taux_utilisation");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS final_action_plan");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS site_status");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS nombre_occurrences_fdd");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS nombre_occurrences_tdd");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS capacite_fdd_mbps");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS capacite_tdd_mbps");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS max_trafic_fdd");
        $this->addSql("ALTER TABLE processed_site DROP COLUMN IF EXISTS max_trafic_tdd");
    }
}
