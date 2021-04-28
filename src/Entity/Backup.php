<?php

namespace App\Entity;

use App\Repository\BackupRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * @ORM\Entity(repositoryClass=BackupRepository::class)
 */
class Backup
{
    use TimestampableEntity;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=BackupConfiguration::class, inversedBy="backups")
     */
    private $backupConfiguration;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $currentPlace = 'initialized';

    /**
     * @ORM\OneToMany(targetEntity=Log::class, mappedBy="backup", cascade={"remove"})
     * @ORM\OrderBy({"id" = "DESC"})
     */
    private $logs;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $osImageId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $checksum;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     */
    private $size;

    public function __construct()
    {
        $this->logs = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    public function getName(bool $timestamp = true): string
    {
        if ($timestamp) {
            return sprintf(
                '%s-%s',
                $this->getBackupConfiguration()->getSlug(),
                (null === $this->getCreatedAt() ? new DateTime() : $this->getCreatedAt())->format('Y-m-d')
            );
        } else {
            return sprintf(
                '%s',
                $this->getBackupConfiguration()->getSlug(),
            );
        }
    }

    public function getBootstrapColor(): string
    {
        switch ($this->currentPlace) {
            case 'failed':
                return 'danger';
            case 'dump':
                return 'info';
            case 'backuped':
                return 'success';
            default:
                return 'warning';
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBackupConfiguration(): ?BackupConfiguration
    {
        return $this->backupConfiguration;
    }

    public function setBackupConfiguration(?BackupConfiguration $backupConfiguration): self
    {
        $this->backupConfiguration = $backupConfiguration;

        return $this;
    }

    public function getCurrentPlace(): ?string
    {
        return $this->currentPlace;
    }

    public function setCurrentPlace(string $currentPlace): self
    {
        $this->currentPlace = $currentPlace;

        return $this;
    }

    /**
     * @return Collection|Log[]
     */
    public function getLogs(): Collection
    {
        return $this->logs;
    }

    public function addLog(Log $log): self
    {
        if (!$this->logs->contains($log)) {
            $this->logs[] = $log;
            $log->setBackup($this);
        }

        return $this;
    }

    public function removeLog(Log $log): self
    {
        if ($this->logs->removeElement($log)) {
            // set the owning side to null (unless already changed)
            if ($log->getBackup() === $this) {
                $log->setBackup(null);
            }
        }

        return $this;
    }

    public function getOsImageId(): ?string
    {
        return $this->osImageId;
    }

    public function setOsImageId(?string $osImageId): self
    {
        $this->osImageId = $osImageId;

        return $this;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(?string $checksum): self
    {
        $this->checksum = $checksum;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): self
    {
        $this->size = $size;

        return $this;
    }
}
