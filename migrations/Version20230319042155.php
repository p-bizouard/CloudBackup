<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230319042155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE backup_configuration ADD rclone_backup_dir VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD rclone_configuration TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD rclone_configuration TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE backup_configuration DROP rclone_backup_dir');
        $this->addSql('ALTER TABLE backup_configuration DROP rclone_configuration');
        $this->addSql('ALTER TABLE storage DROP description');
        $this->addSql('ALTER TABLE storage DROP rclone_configuration');
    }
}
