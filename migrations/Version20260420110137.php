<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420110137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Skip duplicate processed_site data_hash migration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processed_site ALTER service_name DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processed_site ALTER service_name SET NOT NULL');
    }
}