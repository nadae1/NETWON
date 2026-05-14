<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420124404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Skip duplicate processed_site data_hash changes already applied';
    }

    public function up(Schema $schema): void
    {
        // colonne data_hash existe deja, rien a faire
    }

    public function down(Schema $schema): void
    {
        // rien a revert
    }
}