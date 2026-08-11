<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add started_at and validated_at columns to ticket table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ADD COLUMN IF NOT EXISTS started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD COLUMN IF NOT EXISTS validated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket DROP COLUMN IF EXISTS started_at');
        $this->addSql('ALTER TABLE ticket DROP COLUMN IF EXISTS validated_at');
    }
}
