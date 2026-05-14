<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427111025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update ticket_site to store site info directly';
    }

    public function up(Schema $schema): void
    {
        // 1) نزيدو colonnes nullable الأول
        $this->addSql('ALTER TABLE ticket_site ADD site_name VARCHAR(255)');
        $this->addSql('ALTER TABLE ticket_site ADD type_trans VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_site ADD service_name VARCHAR(50) DEFAULT NULL');

        // 2) نعمروا site_name/service_name من table site القديمة
        $this->addSql("
            UPDATE ticket_site ts
            SET 
                site_name = s.name,
                service_name = s.service
            FROM site s
            WHERE ts.site_id = s.id
        ");

        // 3) أي row ما تعمّرش نخليوه UNKNOWN باش ما يطيحش NOT NULL
        $this->addSql("
            UPDATE ticket_site
            SET site_name = 'UNKNOWN_SITE'
            WHERE site_name IS NULL
        ");

        // 4) توا نعملو NOT NULL
        $this->addSql('ALTER TABLE ticket_site ALTER site_name SET NOT NULL');

        // 5) نحذف العلاقة القديمة مع site
        $this->addSql('ALTER TABLE ticket_site DROP CONSTRAINT IF EXISTS fk_9bf08d8df6bd1646');
        $this->addSql('DROP INDEX IF EXISTS idx_9bf08d8df6bd1646');
        $this->addSql('ALTER TABLE ticket_site DROP COLUMN site_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_site ADD site_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_site ADD CONSTRAINT fk_9bf08d8df6bd1646 FOREIGN KEY (site_id) REFERENCES site (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_9bf08d8df6bd1646 ON ticket_site (site_id)');

        $this->addSql('ALTER TABLE ticket_site DROP site_name');
        $this->addSql('ALTER TABLE ticket_site DROP type_trans');
        $this->addSql('ALTER TABLE ticket_site DROP service_name');
    }
}