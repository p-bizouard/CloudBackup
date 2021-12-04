<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20211204143205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE backup_configuration ADD custom_extension VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD not_before SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_configuration ALTER keep_daily SET NOT NULL');
        $this->addSql('ALTER TABLE backup_configuration ALTER keep_weekly SET NOT NULL');
        $this->addSql('ALTER TABLE storage DROP username');
        $this->addSql('ALTER TABLE storage DROP host');
        $this->addSql('ALTER TABLE storage DROP path');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE backup_configuration DROP custom_extension');
        $this->addSql('ALTER TABLE backup_configuration DROP not_before');
        $this->addSql('ALTER TABLE backup_configuration ALTER keep_daily DROP NOT NULL');
        $this->addSql('ALTER TABLE backup_configuration ALTER keep_weekly DROP NOT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD username VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD host VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD path VARCHAR(255) DEFAULT NULL');
    }
}
