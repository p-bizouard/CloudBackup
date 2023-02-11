<?php

namespace App\Entity;

use App\Repository\BackupConfigurationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=BackupConfigurationRepository::class)
 */
class BackupConfiguration
{
    use TimestampableEntity;

    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue
     *
     * @ORM\Column(type="integer")
     */
    private ?int $id;

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
    private ?Storage $storage;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $storageSubPath = null;

    /**
     * @ORM\ManyToOne(targetEntity=OSInstance::class, inversedBy="backupConfigurations")
     */
    private OSInstance $osInstance;

    /**
     * @ORM\ManyToOne(targetEntity=S3Bucket::class, inversedBy="backupConfigurations")
     */
    private S3Bucket $s3Bucket;

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
    private ?Host $host;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $remotePath = null;

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
     * @ORM\Column(type="smallint", nullable=true)
     */
    private ?int $notBefore = null;

    public const PERIODICITY_DAILY = 'daily';

    public const TYPE_OS_INSTANCE = 'os-instance';
    public const TYPE_MYSQL = 'mysql';
    public const TYPE_SQL_SERVER = 'sql-server';
    public const TYPE_POSTGRESQL = 'postgresql';
    public const TYPE_SSHFS = 'sshfs';
    public const TYPE_SSH_RESTIC = 'ssh-restic';
    public const TYPE_READ_RESTIC = 'read-restic';
    public const TYPE_SSH_CMD = 'ssh-cmd';
    public const TYPE_SFTP = 'sftp';
    public const TYPE_S3_BUCKET = 's3-bucket';

    public function __construct()
    {
        $this->backups = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getBackupDateFormat(): string
    {
        if (self::PERIODICITY_DAILY === $this->getPeriodicity()) {
            return 'Y-m-d';
        } else {
            throw new \Exception('Invalid periodicity');
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
            self::TYPE_S3_BUCKET,
        ];
    }

    public function getExtension(): ?string
    {
        if ($this->getCustomExtension()) {
            return $this->getCustomExtension();
        }

        switch ($this->getType()) {
            case BackupConfiguration::TYPE_OS_INSTANCE:
                return 'qcow2';
            case BackupConfiguration::TYPE_MYSQL:
                return 'sql';
            case BackupConfiguration::TYPE_SQL_SERVER:
                return 'bak';
            case BackupConfiguration::TYPE_POSTGRESQL:
                return 'sqlc';
            case BackupConfiguration::TYPE_SSH_CMD:
                return 'dump';
        }

        return null;
    }

    public function getResticForgetArgs(): string
    {
        $keepDaily = $this->getKeepDaily() ? sprintf('--keep-daily %s', $this->getKeepDaily()) : null;
        $keepWeekly = $this->getKeepDaily() ? sprintf('--keep-weekly %s', $this->getKeepWeekly()) : null;

        return sprintf('%s %s', $keepDaily, $keepWeekly);
    }

    public function getResticEnv(): array
    {
        return [
            'RESTIC_PASSWORD' => $this->getStorage()?->getResticPassword(),
            'RESTIC_REPOSITORY' => sprintf('%s/%s', rtrim($this->getStorage()->getResticRepo(), '/'), trim($this->getStorageSubPath(), '/')),
        ];
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
        if ($this->backups->removeElement($backup)) {
            // set the owning side to null (unless already changed)
            if ($backup->getBackupConfiguration() === $this) {
                $backup->setBackupConfiguration(null);
            }
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

    public function getS3Bucket(): ?S3Bucket
    {
        return $this->s3Bucket;
    }

    public function setS3Bucket(?S3Bucket $s3Bucket): self
    {
        $this->s3Bucket = $s3Bucket;

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
}
