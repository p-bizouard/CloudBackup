<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210423123348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE backup_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE backup_configuration_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE log_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE osproject_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE storage_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE "user_id_seq" INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE backup (id INT NOT NULL, backup_configuration_id INT DEFAULT NULL, current_place VARCHAR(255) NOT NULL, os_image_id VARCHAR(255) DEFAULT NULL, checksum VARCHAR(255) DEFAULT NULL, size INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3FF0D1AC5450A77B ON backup (backup_configuration_id)');
        $this->addSql('CREATE TABLE backup_configuration (id INT NOT NULL, storage_id INT NOT NULL, os_instance_id UUID DEFAULT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, periodicity VARCHAR(255) NOT NULL, keep_daily INT DEFAULT NULL, keep_weekly INT DEFAULT NULL, storage_sub_path VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E53979C3989D9B62 ON backup_configuration (slug)');
        $this->addSql('CREATE INDEX IDX_E53979C35CC5DB90 ON backup_configuration (storage_id)');
        $this->addSql('CREATE INDEX IDX_E53979C313F554DE ON backup_configuration (os_instance_id)');
        $this->addSql('COMMENT ON COLUMN backup_configuration.os_instance_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE log (id INT NOT NULL, backup_id INT DEFAULT NULL, message TEXT NOT NULL, level VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8F3F68C593BD6749 ON log (backup_id)');
        $this->addSql('CREATE TABLE osinstance (id UUID NOT NULL, os_project_id INT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, os_region_name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2D5AA62C989D9B62 ON osinstance (slug)');
        $this->addSql('CREATE INDEX IDX_2D5AA62C6B8244E5 ON osinstance (os_project_id)');
        $this->addSql('COMMENT ON COLUMN osinstance.id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE osproject (id INT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, auth_url VARCHAR(255) NOT NULL, identity_api_version INT NOT NULL, user_domain_name VARCHAR(255) NOT NULL, project_domain_name VARCHAR(255) NOT NULL, tenant_id VARCHAR(255) NOT NULL, tenant_name VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F5D3C338989D9B62 ON osproject (slug)');
        $this->addSql('CREATE TABLE storage (id INT NOT NULL, os_project_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, os_region_name VARCHAR(255) DEFAULT NULL, restic_password VARCHAR(255) DEFAULT NULL, restic_repo VARCHAR(255) DEFAULT NULL, host VARCHAR(255) DEFAULT NULL, username VARCHAR(255) DEFAULT NULL, path VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_547A1B346B8244E5 ON storage (os_project_id)');
        $this->addSql('CREATE TABLE "user" (id INT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('ALTER TABLE backup ADD CONSTRAINT FK_3FF0D1AC5450A77B FOREIGN KEY (backup_configuration_id) REFERENCES backup_configuration (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE backup_configuration ADD CONSTRAINT FK_E53979C35CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE backup_configuration ADD CONSTRAINT FK_E53979C313F554DE FOREIGN KEY (os_instance_id) REFERENCES osinstance (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE log ADD CONSTRAINT FK_8F3F68C593BD6749 FOREIGN KEY (backup_id) REFERENCES backup (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE osinstance ADD CONSTRAINT FK_2D5AA62C6B8244E5 FOREIGN KEY (os_project_id) REFERENCES osproject (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE storage ADD CONSTRAINT FK_547A1B346B8244E5 FOREIGN KEY (os_project_id) REFERENCES osproject (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE log DROP CONSTRAINT FK_8F3F68C593BD6749');
        $this->addSql('ALTER TABLE backup DROP CONSTRAINT FK_3FF0D1AC5450A77B');
        $this->addSql('ALTER TABLE backup_configuration DROP CONSTRAINT FK_E53979C313F554DE');
        $this->addSql('ALTER TABLE osinstance DROP CONSTRAINT FK_2D5AA62C6B8244E5');
        $this->addSql('ALTER TABLE storage DROP CONSTRAINT FK_547A1B346B8244E5');
        $this->addSql('ALTER TABLE backup_configuration DROP CONSTRAINT FK_E53979C35CC5DB90');
        $this->addSql('DROP SEQUENCE backup_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE backup_configuration_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE log_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE osproject_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE storage_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE "user_id_seq" CASCADE');
        $this->addSql('DROP TABLE backup');
        $this->addSql('DROP TABLE backup_configuration');
        $this->addSql('DROP TABLE log');
        $this->addSql('DROP TABLE osinstance');
        $this->addSql('DROP TABLE osproject');
        $this->addSql('DROP TABLE storage');
        $this->addSql('DROP TABLE "user"');
    }
}
