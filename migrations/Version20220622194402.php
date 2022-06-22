<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220622194402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage ADD aws_access_key_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD aws_secret_access_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD aws_default_region VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage DROP aws_access_key_id');
        $this->addSql('ALTER TABLE storage DROP aws_secret_access_key');
        $this->addSql('ALTER TABLE storage DROP aws_default_region');
    }
}
