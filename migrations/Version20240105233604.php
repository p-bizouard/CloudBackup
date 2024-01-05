<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240105233604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE backup_configuration DROP CONSTRAINT fk_e53979c3b3512950');
        $this->addSql('DROP SEQUENCE s3_bucket_id_seq CASCADE');
        $this->addSql('DROP TABLE s3_bucket');
        $this->addSql('DROP INDEX idx_e53979c3b3512950');
        $this->addSql('ALTER TABLE backup_configuration DROP s3_bucket_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SEQUENCE s3_bucket_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE s3_bucket (id INT NOT NULL, name VARCHAR(255) NOT NULL, access_key VARCHAR(255) DEFAULT NULL, secret_key VARCHAR(255) DEFAULT NULL, region VARCHAR(255) DEFAULT NULL, bucket VARCHAR(255) NOT NULL, endpoint_url VARCHAR(255) DEFAULT NULL, use_path_request_style BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE backup_configuration ADD s3_bucket_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD CONSTRAINT fk_e53979c3b3512950 FOREIGN KEY (s3_bucket_id) REFERENCES s3_bucket (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_e53979c3b3512950 ON backup_configuration (s3_bucket_id)');
    }
}
