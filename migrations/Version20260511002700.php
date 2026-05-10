<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511002700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Kopia storage / backup-configuration / backup columns for monitoring-only Kopia support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storage ADD kopia_backend VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD kopia_connect_args TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD kopia_password VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE backup_configuration ADD kopia_check_tags VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE backup ADD kopia_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup ADD kopia_total_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup ADD kopia_total_dedup_size BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup DROP kopia_total_dedup_size');
        $this->addSql('ALTER TABLE backup DROP kopia_total_size');
        $this->addSql('ALTER TABLE backup DROP kopia_size');

        $this->addSql('ALTER TABLE backup_configuration DROP kopia_check_tags');

        $this->addSql('ALTER TABLE storage DROP kopia_password');
        $this->addSql('ALTER TABLE storage DROP kopia_connect_args');
        $this->addSql('ALTER TABLE storage DROP kopia_backend');
    }
}
