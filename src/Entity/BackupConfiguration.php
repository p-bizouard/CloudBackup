<?php

namespace App\Entity;

use App\Repository\BackupConfigurationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @ORM\Entity(repositoryClass=BackupConfigurationRepository::class)
 */
class BackupConfiguration
{
    use TimestampableEntity;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $name;

    /**
     * @ORM\Column(type="string", length=255, unique=true)
     * @Gedmo\Slug(fields={"name"})
     */
    private $slug;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $type;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\Choice(callback={BackupConfiguration::class, "getAvailablePeriodicity"})
     */
    private $periodicity = 'daily';

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $keepDaily = 7;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $keepWeekly = 4;

    /**
     * @ORM\ManyToOne(targetEntity=Storage::class, inversedBy="backupConfigurations")
     * @ORM\JoinColumn(nullable=false)
     */
    private $storage;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $storageSubPath;

    /**
     * @ORM\ManyToOne(targetEntity=OSInstance::class, inversedBy="backupConfigurations")
     */
    private $osInstance;

    /**
     * @ORM\OneToMany(targetEntity=Backup::class, mappedBy="backupConfiguration")
     * @ORM\OrderBy({"id" = "DESC"})
     */
    private $backups;

    /**
     * @ORM\Column(type="boolean", options={"default" : true})
     */
    private $enabled = true;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $dumpCommand;

    /**
     * @ORM\ManyToOne(targetEntity=Host::class, inversedBy="backupConfigurations")
     */
    private $host;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $remotePath;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     */
    private $minimumBackupSize;

    const PERIODICITY_DAILY = 'daily';

    const TYPE_OS_INSTANCE = 'os-instance';
    const TYPE_MYSQL = 'mysql';
    const TYPE_POSTGRESQL = 'postgresql';
    const TYPE_SSHFS = 'sshfs';
    const TYPE_SSH_RESTIC = 'ssh-restic';
    const TYPE_READ_RESTIC = 'read-restic';

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
            self::TYPE_POSTGRESQL,
            self::TYPE_SSHFS,
            self::TYPE_SSH_RESTIC,
            self::TYPE_READ_RESTIC,
        ];
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
            'RESTIC_PASSWORD' => $this->getStorage()->getResticPassword(),
            'RESTIC_REPOSITORY' => sprintf('%s/%s', trim($this->getStorage()->getResticRepo(), '/'), trim($this->getStorageSubPath(), '/')),
        ];
    }

    /**
     * @Assert\Callback
     */
    public function validate(ExecutionContextInterface $context, $payload)
    {
        // if (null !== $this->getOsInstance()) {
        //     return;
        // }
        // if (null !== $this->getHost()) {
        //     return;
        // }

        // $context->buildViolation('One of field OS Instance or Host is mandatory')
        //     ->atPath('osInstance')
        //     ->atPath('host')
        //     ->addViolation();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getPeriodicity(): ?string
    {
        return $this->periodicity;
    }

    public function setPeriodicity(string $periodicity): self
    {
        $this->periodicity = $periodicity;

        return $this;
    }

    public function getKeepDaily(): ?int
    {
        return $this->keepDaily;
    }

    public function setKeepDaily(?int $keepDaily): self
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
     * @return Collection|Backup[]
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
}
