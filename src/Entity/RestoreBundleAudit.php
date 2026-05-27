<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RestoreBundleAuditRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RestoreBundleAuditRepository::class)]
#[ORM\Table(name: 'restore_bundle_audit')]
#[ORM\Index(columns: ['destination_id', 'generated_at'], name: 'idx_restore_audit_destination_time')]
#[ORM\Index(columns: ['backup_id', 'generated_at'], name: 'idx_restore_audit_backup_time')]
#[ORM\Index(columns: ['source', 'generated_at'], name: 'idx_restore_audit_source_time')]
class RestoreBundleAudit
{
    final public const string SOURCE_ADMIN = 'admin';
    final public const string SOURCE_CLI = 'cli';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: RestoreDestination::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?RestoreDestination $destination = null;

    #[ORM\ManyToOne(targetEntity: Backup::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Backup $backup = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $generatedAt = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    #[Assert\NotBlank]
    private ?string $sha256 = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    private ?string $filename = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    #[Assert\Choice(callback: [self::class, 'getAvailableSources'])]
    private ?string $source = null;

    public function __construct()
    {
        $this->generatedAt = new DateTimeImmutable();
    }

    /**
     * @return string[]
     */
    public static function getAvailableSources(): array
    {
        return [self::SOURCE_ADMIN, self::SOURCE_CLI];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getDestination(): ?RestoreDestination
    {
        return $this->destination;
    }

    public function setDestination(RestoreDestination $destination): self
    {
        $this->destination = $destination;

        return $this;
    }

    public function getBackup(): ?Backup
    {
        return $this->backup;
    }

    public function setBackup(Backup $backup): self
    {
        $this->backup = $backup;

        return $this;
    }

    public function getGeneratedAt(): ?DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(DateTimeImmutable $generatedAt): self
    {
        $this->generatedAt = $generatedAt;

        return $this;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function setSha256(string $sha256): self
    {
        $this->sha256 = $sha256;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }
}
