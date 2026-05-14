<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260427153750 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_site DROP CONSTRAINT fk_5b981cdc700047d2');
        $this->addSql('ALTER TABLE ticket_site ADD CONSTRAINT FK_5B981CDC700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT fk_60a5ce1d700047d2');
        $this->addSql('ALTER TABLE ticket_task ADD step_code VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD decision VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_site DROP CONSTRAINT FK_5B981CDC700047D2');
        $this->addSql('ALTER TABLE ticket_site ADD CONSTRAINT fk_5b981cdc700047d2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D700047D2');
        $this->addSql('ALTER TABLE ticket_task DROP step_code');
        $this->addSql('ALTER TABLE ticket_task DROP decision');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT fk_60a5ce1d700047d2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
