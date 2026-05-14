<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514095817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_task ADD card_type VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD measured_capacity DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD ip_decision VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD wo_ip_reference VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ALTER step_order DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_task DROP card_type');
        $this->addSql('ALTER TABLE ticket_task DROP measured_capacity');
        $this->addSql('ALTER TABLE ticket_task DROP ip_decision');
        $this->addSql('ALTER TABLE ticket_task DROP wo_ip_reference');
        $this->addSql('ALTER TABLE ticket_task ALTER step_order SET DEFAULT 0');
    }
}
