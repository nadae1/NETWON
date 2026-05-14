<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423092023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Skip duplicate analyse_resultat creation because table already exists';
    }

    public function up(Schema $schema): void
    {
        // table analyse_resultat existe deja
    }

    public function down(Schema $schema): void
    {
        // rien a revert
    }
}