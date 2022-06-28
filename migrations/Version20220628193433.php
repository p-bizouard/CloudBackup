<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220628193433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE backup ADD restic_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup ADD restic_dedup_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup ADD restic_total_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup ADD restic_total_dedup_size BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE backup DROP restic_size');
        $this->addSql('ALTER TABLE backup DROP restic_dedup_size');
        $this->addSql('ALTER TABLE backup DROP restic_total_size');
        $this->addSql('ALTER TABLE backup DROP restic_total_dedup_size');
    }
}
