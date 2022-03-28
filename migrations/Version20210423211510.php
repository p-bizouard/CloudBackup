<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210423211510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE "host_id_seq" INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE "host" (id INT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, ip VARCHAR(255) NOT NULL, login VARCHAR(255) NOT NULL, password VARCHAR(255) DEFAULT NULL, private_key TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CF2713FD989D9B62 ON "host" (slug)');
        $this->addSql('ALTER TABLE backup ALTER size TYPE BIGINT');
        $this->addSql('ALTER TABLE backup ALTER size DROP DEFAULT');
        $this->addSql('ALTER TABLE backup_configuration ADD host_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD enabled BOOLEAN DEFAULT \'true\' NOT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD dump_command TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_configuration ADD CONSTRAINT FK_E53979C31FB8D185 FOREIGN KEY (host_id) REFERENCES "host" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_E53979C31FB8D185 ON backup_configuration (host_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE backup_configuration DROP CONSTRAINT FK_E53979C31FB8D185');
        $this->addSql('DROP SEQUENCE "host_id_seq" CASCADE');
        $this->addSql('DROP TABLE "host"');
        $this->addSql('DROP INDEX IDX_E53979C31FB8D185');
        $this->addSql('ALTER TABLE backup_configuration DROP host_id');
        $this->addSql('ALTER TABLE backup_configuration DROP enabled');
        $this->addSql('ALTER TABLE backup_configuration DROP dump_command');
        $this->addSql('ALTER TABLE backup ALTER size TYPE INT');
        $this->addSql('ALTER TABLE backup ALTER size DROP DEFAULT');
    }
}
