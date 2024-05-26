<?php

namespace App\Entity;

use App\Repository\BackupConfigurationRepository;
use App\Validator as AppAssert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=BackupConfigurationRepository::class)
 */
#[AppAssert\BackupConfigurationTypeRclone]
class BackupConfiguration implements Stringable
{
    use TimestampableEntity;

    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue
     *
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $name = null;

    /**
     * @ORM\Column(type="string", length=255, unique=true)
     *
     * @Gedmo\Slug(fields={"name"})
     */
    private ?string $slug = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $type = null;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Assert\Choice(callback={BackupConfiguration::class, "getAvailablePeriodicity"})
     */
    private string $periodicity = 'daily';

    /**
     * @ORM\Column(type="integer")
     */
    private int $keepDaily = 7;

    /**
     * @ORM\Column(type="integer")
     */
    private int $keepWeekly = 4;

    /**
     * @ORM\ManyToOne(targetEntity=Storage::class, inversedBy="backupConfigurations")
     *
     * @ORM\JoinColumn(nullable=false)
     */
    private ?Storage $storage = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $storageSubPath = null;

    /**
     * @ORM\ManyToOne(targetEntity=OSInstance::class, inversedBy="backupConfigurations")
     */
    private OSInstance $osInstance;

    /**
     * @ORM\OneToMany(targetEntity=Backup::class, mappedBy="backupConfiguration")
     *
     * @ORM\OrderBy({"id" = "DESC"})
     *
     * @var Collection<int, Backup>
     */
    private Collection $backups;

    /**
     * @ORM\Column(type="boolean", options={"default" : true})
     */
    private bool $enabled = true;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $dumpCommand = null;

    /**
     * @ORM\ManyToOne(targetEntity=Host::class, inversedBy="backupConfigurations")
     */
    private ?Host $host = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $remotePath = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $rcloneBackupDir = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $rcloneFlags = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $resticCheckTags = null;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     */
    private ?string $minimumBackupSize = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $remoteCleanCommand = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $customExtension = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $stdErrIgnore = null;

    /**
     * @ORM\Column(type="smallint", nullable=true)
     */
    private ?int $notBefore = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $rcloneConfiguration = null;

    /**
     * @ORM\Column(type="smallint", options={"default" : 1})
     */
    private int $notifyEvery = 1;

    final public const PERIODICITY_DAILY = 'daily';

    final public const TYPE_OS_INSTANCE = 'os-instance';
    final public const TYPE_MYSQL = 'mysql';
    final public const TYPE_SQL_SERVER = 'sql-server';
    final public const TYPE_POSTGRESQL = 'postgresql';
    final public const TYPE_SSHFS = 'sshfs';
    final public const TYPE_SSH_RESTIC = 'ssh-restic';
    final public const TYPE_READ_RESTIC = 'read-restic';
    final public const TYPE_SSH_CMD = 'ssh-cmd';
    final public const TYPE_SFTP = 'sftp';
    final public const TYPE_RCLONE = 'rclone';

    public function __construct()
    {
        $this->backups = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getBackupDateFormat(): string
    {
        if (self::PERIODICITY_DAILY === $this->getPeriodicity()) {
            return 'Y-m-d';
        } else {
            throw new Exception('Invalid periodicity');
        }
    }

    public static function getAvailablePeriodicity(): array
    {
        return [
            self::PERIODICITY_DAILY,
        ];
    }

    public static function getAvailableTypes(): array
    {
        return [
            self::TYPE_OS_INSTANCE,
            self::TYPE_MYSQL,
            self::TYPE_SQL_SERVER,
            self::TYPE_POSTGRESQL,
            self::TYPE_SSHFS,
            self::TYPE_SSH_RESTIC,
            self::TYPE_READ_RESTIC,
            self::TYPE_SSH_CMD,
            self::TYPE_SFTP,
            self::TYPE_RCLONE,
        ];
    }

    /**
     * @param string[] $except
     */
    public static function getAvailableTypesExept(array $except): array
    {
        return array_filter(self::getAvailableTypes(), function ($type) use ($except) {
            return !in_array($type, $except);
        });
    }

    public function getExtension(): ?string
    {
        if ($this->getCustomExtension()) {
            return $this->getCustomExtension();
        }

        return match ($this->getType()) {
            BackupConfiguration::TYPE_OS_INSTANCE => 'qcow2',
            BackupConfiguration::TYPE_MYSQL => 'sql',
            BackupConfiguration::TYPE_SQL_SERVER => 'bak',
            BackupConfiguration::TYPE_POSTGRESQL => 'sqlc',
            BackupConfiguration::TYPE_SSH_CMD => 'dump',
            default => null,
        };
    }

    public function getResticForgetArgs(): string
    {
        $keepDaily = 0 !== $this->getKeepDaily() ? sprintf('--keep-daily %s', (int) $this->getKeepDaily()) : null;
        $keepWeekly = 0 !== $this->getKeepDaily() ? sprintf('--keep-weekly %s', (int) $this->getKeepWeekly()) : null;

        return sprintf('%s %s', $keepDaily, $keepWeekly);
    }

    public function getResticEnv(): array
    {
        if (is_null($this->getStorage()) || is_null($this->getStorage()->getResticRepo())) {
            return [];
        }

        return [
            'RESTIC_PASSWORD' => $this->getStorage()->getResticPassword(),
            'RESTIC_REPOSITORY' => sprintf('%s/%s', rtrim($this->getStorage()->getResticRepo(), '/'), trim($this->getStorageSubPath(), '/')),
        ];
    }

    public function getCompleteRcloneConfiguration(): ?string
    {
        return sprintf("%s\n%s", $this->getStorage()->getRcloneConfiguration(), $this->rcloneConfiguration);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getPeriodicity(): string
    {
        return $this->periodicity;
    }

    public function setPeriodicity(string $periodicity): self
    {
        $this->periodicity = $periodicity;

        return $this;
    }

    public function getKeepDaily(): int
    {
        return $this->keepDaily;
    }

    public function setKeepDaily(int $keepDaily): self
    {
        $this->keepDaily = $keepDaily;

        return $this;
    }

    public function getKeepWeekly(): ?int
    {
        return $this->keepWeekly;
    }

    public function setKeepWeekly(?int $keepWeekly): self
    {
        $this->keepWeekly = $keepWeekly;

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

    public function getStorageSubPath(): ?string
    {
        return $this->storageSubPath;
    }

    public function setStorageSubPath(?string $subPath): self
    {
        $this->storageSubPath = $subPath;

        return $this;
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

    public function getOsInstance(): ?OSInstance
    {
        return $this->osInstance;
    }

    public function setOsInstance(?OSInstance $osInstance): self
    {
        $this->osInstance = $osInstance;

        return $this;
    }

    /**
     * @return Collection<int, Backup>
     */
    public function getBackups(): Collection
    {
        return $this->backups;
    }

    public function addBackup(Backup $backup): self
    {
        if (!$this->backups->contains($backup)) {
            $this->backups[] = $backup;
            $backup->setBackupConfiguration($this);
        }

        return $this;
    }

    public function removeBackup(Backup $backup): self
    {
        // set the owning side to null (unless already changed)
        if ($this->backups->removeElement($backup) && $backup->getBackupConfiguration() === $this) {
            $backup->setBackupConfiguration(null);
        }

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getDumpCommand(): ?string
    {
        return $this->dumpCommand;
    }

    public function setDumpCommand(?string $dumpCommand): self
    {
        $this->dumpCommand = $dumpCommand;

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

    public function getRemotePath(): ?string
    {
        return $this->remotePath;
    }

    public function setRemotePath(?string $remotePath): self
    {
        $this->remotePath = $remotePath;

        return $this;
    }

    public function getMinimumBackupSize(): ?string
    {
        return $this->minimumBackupSize;
    }

    public function setMinimumBackupSize(?string $minimumBackupSize): self
    {
        $this->minimumBackupSize = $minimumBackupSize;

        return $this;
    }

    public function getRemoteCleanCommand(): ?string
    {
        return $this->remoteCleanCommand;
    }

    public function setRemoteCleanCommand(?string $remoteCleanCommand): self
    {
        $this->remoteCleanCommand = $remoteCleanCommand;

        return $this;
    }

    public function getCustomExtension(): ?string
    {
        return $this->customExtension;
    }

    public function setCustomExtension(?string $customExtension): self
    {
        $this->customExtension = $customExtension;

        return $this;
    }

    public function getNotBefore(): ?int
    {
        return $this->notBefore;
    }

    public function setNotBefore(?int $notBefore): self
    {
        $this->notBefore = $notBefore;

        return $this;
    }

    public function isEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function getRcloneConfiguration(): ?string
    {
        return $this->rcloneConfiguration;
    }

    public function setRcloneConfiguration(?string $rcloneConfiguration): self
    {
        $this->rcloneConfiguration = $rcloneConfiguration;

        return $this;
    }

    public function getRcloneBackupDir(): ?string
    {
        return $this->rcloneBackupDir;
    }

    public function setRcloneBackupDir(?string $rcloneBackupDir): self
    {
        $this->rcloneBackupDir = $rcloneBackupDir;

        return $this;
    }

    public function getRcloneFlags(): ?string
    {
        return $this->rcloneFlags;
    }

    public function setRcloneFlags(?string $rcloneFlags): self
    {
        $this->rcloneFlags = $rcloneFlags;

        return $this;
    }

    public function getResticCheckTags(): ?string
    {
        return $this->resticCheckTags;
    }

    public function setResticCheckTags(?string $resticCheckTags): self
    {
        $this->resticCheckTags = $resticCheckTags;

        return $this;
    }

    public function getStdErrIgnore(): ?string
    {
        return $this->stdErrIgnore;
    }

    public function setStdErrIgnore(?string $stdErrIgnore): static
    {
        $this->stdErrIgnore = $stdErrIgnore;

        return $this;
    }

    public function getNotifyEvery(): int
    {
        return $this->notifyEvery;
    }

    public function setNotifyEvery(int $notifyEvery): static
    {
        $this->notifyEvery = $notifyEvery;

        return $this;
    }
}
