<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Kopia storage support fields
 */
final class Version20260302135656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Kopia password and repository fields to storage table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage ADD kopia_password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD kopia_repo VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage DROP kopia_password');
        $this->addSql('ALTER TABLE storage DROP kopia_repo');
    }
}
