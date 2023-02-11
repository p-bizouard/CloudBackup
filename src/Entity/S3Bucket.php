<?php

namespace App\Entity;

use App\Repository\S3BucketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * @ORM\Entity(repositoryClass=S3BucketRepository::class)
 */
class S3Bucket
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
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $accessKey = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $secretKey = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $region = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $bucket = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $endpointUrl = null;

    /**
     * @ORM\Column(type="boolean", options={"default" : true})
     */
    private ?bool $usePathRequestStyle = true;

    /**
     * @ORM\OneToMany(targetEntity=BackupConfiguration::class, mappedBy="s3Bucket")
     *
     * @var Collection<int, BackupConfiguration>
     */
    private Collection $backupConfigurations;

    public function __construct()
    {
        $this->backupConfigurations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
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

    public function getAccessKey(): ?string
    {
        return $this->accessKey;
    }

    public function setAccessKey(?string $accessKey): self
    {
        $this->accessKey = $accessKey;

        return $this;
    }

    public function getSecretKey(): ?string
    {
        return $this->secretKey;
    }

    public function setSecretKey(?string $secretKey): self
    {
        $this->secretKey = $secretKey;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): self
    {
        $this->region = $region;

        return $this;
    }

    public function getEndpointUrl(): ?string
    {
        return $this->endpointUrl;
    }

    public function setEndpointUrl(?string $endpointUrl): self
    {
        $this->endpointUrl = $endpointUrl;

        return $this;
    }

    public function getBucket(): ?string
    {
        return $this->bucket;
    }

    public function setBucket(string $bucket): self
    {
        $this->bucket = $bucket;

        return $this;
    }

    /**
     * @return Collection<int, BackupConfiguration>
     */
    public function getBackupConfigurations(): Collection
    {
        return $this->backupConfigurations;
    }

    public function addBackupConfiguration(BackupConfiguration $backupConfiguration): self
    {
        if (!$this->backupConfigurations->contains($backupConfiguration)) {
            $this->backupConfigurations->add($backupConfiguration);
            $backupConfiguration->setS3Bucket($this);
        }

        return $this;
    }

    public function removeBackupConfiguration(BackupConfiguration $backupConfiguration): self
    {
        if ($this->backupConfigurations->removeElement($backupConfiguration)) {
            // set the owning side to null (unless already changed)
            if ($backupConfiguration->getS3Bucket() === $this) {
                $backupConfiguration->setS3Bucket(null);
            }
        }

        return $this;
    }

    public function isUsePathRequestStyle(): ?bool
    {
        return $this->usePathRequestStyle;
    }

    public function setUsePathRequestStyle(bool $usePathRequestStyle): self
    {
        $this->usePathRequestStyle = $usePathRequestStyle;

        return $this;
    }
}
