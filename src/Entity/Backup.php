<?php

namespace App\Entity;

use App\Repository\BackupRepository;
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
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity=BackupConfiguration::class, inversedBy="backups")
     */
    private ?BackupConfiguration $backupConfiguration = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $currentPlace = 'initialized';

    /**
     * @ORM\OneToMany(targetEntity=Log::class, mappedBy="backup", cascade={"remove"})
     * @ORM\OrderBy({"id" = "DESC"})
     *
     * @var Collection<int, Log>
     */
    private Collection $logs;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $osImageId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $checksum = null;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     */
    private ?int $size = null;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     */
    private ?int $resticSize = null;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     */
    private ?int $resticDedupSize = null;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     */
    private ?int $resticTotalSize = null;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     */
    private ?int $resticTotalDedupSize = null;

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
                $this->getCreatedAt()->format('Y-m-d')
            );
        } else {
            return sprintf(
                '%s',
                $this->getBackupConfiguration()->getSlug(),
            );
        }
    }

    public function getLogsForReport(): string
    {
        return implode("\n", array_map(function (Log $log) {
            return sprintf('<pre style="color:%s">%s</pre>', $log->getMessageColor(), $log->getMessage());
        }, $this->logs->toArray()));
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
     * @return Collection<int, Log>
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

    public function getResticSize(): ?int
    {
        return $this->resticSize;
    }

    public function setResticSize(?int $resticSize): self
    {
        $this->resticSize = $resticSize;

        return $this;
    }

    public function getResticDedupSize(): ?int
    {
        return $this->resticDedupSize;
    }

    public function setResticDedupSize(?int $resticDedupSize): self
    {
        $this->resticDedupSize = $resticDedupSize;

        return $this;
    }

    public function getResticTotalSize(): ?int
    {
        return $this->resticTotalSize;
    }

    public function setResticTotalSize(?int $resticTotalSize): self
    {
        $this->resticTotalSize = $resticTotalSize;

        return $this;
    }

    public function getResticTotalDedupSize(): ?int
    {
        return $this->resticTotalDedupSize;
    }

    public function setResticTotalDedupSize(?int $resticTotalDedupSize): self
    {
        $this->resticTotalDedupSize = $resticTotalDedupSize;

        return $this;
    }
}
