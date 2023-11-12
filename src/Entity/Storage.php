<?php

namespace App\Entity;

use App\Repository\StorageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Stringable;

/**
 * @ORM\Entity(repositoryClass=StorageRepository::class)
 */
class Storage implements Stringable
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
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $type = null;

    /**
     * @ORM\ManyToOne(targetEntity=OSProject::class, inversedBy="storages")
     */
    private ?OSProject $osProject = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $osRegionName = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $awsAccessKeyId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $awsSecretAccessKey = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $awsDefaultRegion = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $resticPassword = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $resticRepo = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $rcloneConfiguration = null;

    /**
     * @ORM\OneToMany(targetEntity=BackupConfiguration::class, mappedBy="storage")
     *
     * @var Collection<int, BackupConfiguration>
     */
    private Collection $backupConfigurations;

    final public const TYPE_RESTIC = 'restic';
    final public const TYPE_RCLONE = 'rclone';

    public function __construct()
    {
        $this->backupConfigurations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getOSEnv(): array
    {
        if (null !== $this->getOSProject()) {
            return [
                'OS_AUTH_URL' => $this->getOSProject()->getAuthUrl(),
                'OS_IDENTITY_API_VERSION' => $this->getOSProject()->getIdentityApiVersion(),
                'OS_USER_DOMAIN_NAME' => $this->getOSProject()->getUserDomainName(),
                'OS_PROJECT_DOMAIN_NAME' => $this->getOSProject()->getProjectDomainName(),
                'OS_TENANT_ID' => $this->getOSProject()->getTenantId(),
                'OS_TENANT_NAME' => $this->getOSProject()->getTenantName(),
                'OS_USERNAME' => $this->getOSProject()->getUsername(),
                'OS_PASSWORD' => $this->getOSProject()->getPassword(),
                'OS_REGION_NAME' => $this->getOSRegionName(),
            ];
        } else {
            return [];
        }
    }

    public function getAwsEnv(): array
    {
        if (null !== $this->getAwsAccessKeyId()) {
            return [
                'AWS_ACCESS_KEY_ID' => $this->getAwsAccessKeyId(),
                'AWS_SECRET_ACCESS_KEY' => $this->getAwsSecretAccessKey(),
                'AWS_DEFAULT_REGION' => $this->getAwsDefaultRegion(),
            ];
        } else {
            return [];
        }
    }

    public function getEnv(): array
    {
        return $this->getOSEnv() + $this->getAwsEnv();
    }

    public static function getAvailableTypes(): array
    {
        return [
            self::TYPE_RESTIC,
            self::TYPE_RCLONE,
        ];
    }

    public function isRestic(): bool
    {
        return Storage::TYPE_RESTIC === $this->getType();
    }

    public function isRclone(): bool
    {
        return Storage::TYPE_RCLONE === $this->getType();
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

    public function getOsProject(): ?OSProject
    {
        return $this->osProject;
    }

    public function setOsProject(?OSProject $osProject): self
    {
        $this->osProject = $osProject;

        return $this;
    }

    public function getOsRegionName(): ?string
    {
        return $this->osRegionName;
    }

    public function setOsRegionName(?string $osRegionName): self
    {
        $this->osRegionName = $osRegionName;

        return $this;
    }

    public function getResticPassword(): ?string
    {
        return $this->resticPassword;
    }

    public function setResticPassword(?string $resticPassword): self
    {
        $this->resticPassword = $resticPassword;

        return $this;
    }

    public function getResticRepo(): ?string
    {
        return $this->resticRepo;
    }

    public function setResticRepo(?string $resticRepo): self
    {
        $this->resticRepo = $resticRepo;

        return $this;
    }

    /**
     * @return Collection<int,BackupConfiguration>
     */
    public function getBackupConfigurations(): Collection
    {
        return $this->backupConfigurations;
    }

    public function addBackupConfiguration(BackupConfiguration $backupConfiguration): self
    {
        if (!$this->backupConfigurations->contains($backupConfiguration)) {
            $this->backupConfigurations[] = $backupConfiguration;
            $backupConfiguration->setStorage($this);
        }

        return $this;
    }

    public function removeBackupConfiguration(BackupConfiguration $backupConfiguration): self
    {
        // set the owning side to null (unless already changed)
        if ($this->backupConfigurations->removeElement($backupConfiguration) && $backupConfiguration->getStorage() === $this) {
            $backupConfiguration->setStorage(null);
        }

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getAwsAccessKeyId(): ?string
    {
        return $this->awsAccessKeyId;
    }

    public function setAwsAccessKeyId(?string $awsAccessKeyId): self
    {
        $this->awsAccessKeyId = $awsAccessKeyId;

        return $this;
    }

    public function getAwsSecretAccessKey(): ?string
    {
        return $this->awsSecretAccessKey;
    }

    public function setAwsSecretAccessKey(?string $awsSecretAccessKey): self
    {
        $this->awsSecretAccessKey = $awsSecretAccessKey;

        return $this;
    }

    public function getAwsDefaultRegion(): ?string
    {
        return $this->awsDefaultRegion;
    }

    public function setAwsDefaultRegion(?string $awsDefaultRegion): self
    {
        $this->awsDefaultRegion = $awsDefaultRegion;

        return $this;
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
}
