<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RestoreDestinationRepository;
use App\Validator as AppAssert;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Stringable;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RestoreDestinationRepository::class)]
#[ORM\Table(name: 'restore_destination')]
#[ORM\Index(columns: ['type', 'enabled'], name: 'idx_restore_destination_type_enabled')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('name')]
#[UniqueEntity('slug')]
#[AppAssert\RestoreDestinationTypeRclone]
#[AppAssert\RestoreDestinationTypeMysql]
#[AppAssert\RestoreDestinationTypePostgresql]
#[AppAssert\RestoreDestinationOverwriteRequiresDescription]
class RestoreDestination implements Stringable
{
    use TimestampableEntity;

    final public const string TYPE_RCLONE = 'rclone';
    final public const string TYPE_MYSQL = 'mysql';
    final public const string TYPE_POSTGRESQL = 'postgresql';

    final public const string CONNECTION_SSH = 'ssh';
    final public const string CONNECTION_DIRECT = 'direct';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    private ?string $slug = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'getAvailableTypes'])]
    private ?string $type = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\ManyToOne(targetEntity: Host::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Host $host = null;

    #[ORM\ManyToOne(targetEntity: Storage::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Storage $storage = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $targetDatabase = null;

    #[ORM\Column(type: Types::STRING, length: 1024, nullable: true)]
    private ?string $targetPath = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $extraOptions = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $targetDbUser = null;

    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    private ?string $targetDbPassword = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    #[Assert\Choice(callback: [self::class, 'getAvailableConnectionModes'])]
    private ?string $connectionMode = self::CONNECTION_SSH;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $directHost = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $directPort = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $bringOwnCredentials = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $allowOverwrite = false;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    /**
     * @return string[]
     */
    public static function getAvailableTypes(): array
    {
        return [
            self::TYPE_RCLONE,
            self::TYPE_MYSQL,
            self::TYPE_POSTGRESQL,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getHost(): ?Host
    {
        return $this->host;
    }

    public function setHost(?Host $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getStorage(): ?Storage
    {
        return $this->storage;
    }

    public function setStorage(?Storage $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    public function getTargetDatabase(): ?string
    {
        return $this->targetDatabase;
    }

    public function setTargetDatabase(?string $targetDatabase): self
    {
        $this->targetDatabase = $targetDatabase;

        return $this;
    }

    public function getTargetPath(): ?string
    {
        return $this->targetPath;
    }

    public function setTargetPath(?string $targetPath): self
    {
        $this->targetPath = $targetPath;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExtraOptions(): ?array
    {
        return $this->extraOptions;
    }

    /**
     * @param array<string, mixed>|null $extraOptions
     */
    public function setExtraOptions(?array $extraOptions): self
    {
        $this->extraOptions = $extraOptions;

        return $this;
    }

    public function getTargetDbUser(): ?string
    {
        return $this->targetDbUser;
    }

    public function setTargetDbUser(?string $targetDbUser): self
    {
        $this->targetDbUser = $targetDbUser;

        return $this;
    }

    public function getTargetDbPassword(): ?string
    {
        return $this->targetDbPassword;
    }

    public function setTargetDbPassword(?string $targetDbPassword): self
    {
        $this->targetDbPassword = $targetDbPassword;

        return $this;
    }

    public function getConnectionMode(): ?string
    {
        return $this->connectionMode;
    }

    public function setConnectionMode(?string $connectionMode): self
    {
        $this->connectionMode = $connectionMode;

        return $this;
    }

    public function getDirectHost(): ?string
    {
        return $this->directHost;
    }

    public function setDirectHost(?string $directHost): self
    {
        $this->directHost = $directHost;

        return $this;
    }

    public function getDirectPort(): ?int
    {
        return $this->directPort;
    }

    public function setDirectPort(?int $directPort): self
    {
        $this->directPort = $directPort;

        return $this;
    }

    public function isBringOwnCredentials(): bool
    {
        return $this->bringOwnCredentials;
    }

    public function setBringOwnCredentials(bool $bringOwnCredentials): self
    {
        $this->bringOwnCredentials = $bringOwnCredentials;

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getAvailableConnectionModes(): array
    {
        return [self::CONNECTION_SSH, self::CONNECTION_DIRECT];
    }

    public function isAllowOverwrite(): bool
    {
        return $this->allowOverwrite;
    }

    public function setAllowOverwrite(bool $allowOverwrite): self
    {
        $this->allowOverwrite = $allowOverwrite;

        return $this;
    }

    /**
     * Null out fields irrelevant for the current type / mode / byo combo before save.
     * Mirrors the admin form's visibility rules so persisted data matches what the user sees.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function clearIrrelevantFields(): void
    {
        if (self::TYPE_RCLONE === $this->type) {
            $this->connectionMode = null;
            $this->bringOwnCredentials = false;
            $this->host = null;
            $this->directHost = null;
            $this->directPort = null;
            $this->targetDatabase = null;
            $this->targetDbUser = null;
            $this->targetDbPassword = null;

            return;
        }

        // mysql / postgresql
        $this->storage = null;
        $this->targetPath = null;

        if (self::CONNECTION_SSH === $this->connectionMode) {
            $this->directHost = null;
            $this->directPort = null;
        } elseif (self::CONNECTION_DIRECT === $this->connectionMode) {
            $this->host = null;
        }

        if ($this->bringOwnCredentials) {
            $this->targetDbUser = null;
            $this->targetDbPassword = null;
        }
    }
}
