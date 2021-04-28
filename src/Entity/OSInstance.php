<?php

namespace App\Entity;

use App\Repository\OSInstanceRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=OSInstanceRepository::class)
 */
class OSInstance
{
    use TimestampableEntity;

    /**
     * @ORM\Id
     * @ORM\Column(type="uuid")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotNull()
     */
    private $name;
    
    /**
    * @ORM\Column(type="string", length=255, unique=true)
    * @Gedmo\Slug(fields={"name"})
    */
    private $slug;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotNull()
     */
    private $osRegionName;

    /**
     * @ORM\ManyToOne(targetEntity=OSProject::class, inversedBy="osInstances")
     * @Assert\NotNull()
     * @ORM\JoinColumn(nullable=false)
     */
    private $osProject;

    /**
     * @ORM\OneToMany(targetEntity=BackupConfiguration::class, mappedBy="osInstance")
     */
    private $backupConfigurations;

    public function __construct()
    {
        $this->osInstanceBackups = new ArrayCollection();
        $this->backupConfigurations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getOSEnv(): array
    {
        return [
            'OS_AUTH_URL' => $this->getOSProject()->getAuthUrl(),
            'OS_IDENTITY_API_VERSION' => $this->getOSProject()->getIdentityApiVersion(),
            'OS_USER_DOMAIN_NAME' => $this->getOSProject()->getUserDomainName(),
            'OS_PROJECT_DOMAIN_NAME' => $this->getOSProject()->getProjectDomainName(),
            'OS_TENANT_ID' => $this->getOSProject()->getTenantId(),
            'OS_TENANT_NAME' => $this->getOSProject()->getTenantName(),
            'OS_USERNAME' => $this->getOSProject()->getUsername(),
            'OS_PASSWORD' => $this->getOSProject()->getPassword(),
            'OS_REGION_NAME' => $this->getOSRegionName()
        ];
    }

    public function setId($id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getId()
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

    public function getOSRegionName(): ?string
    {
        return $this->osRegionName;
    }

    public function setOSRegionName(string $regionName): self
    {
        $this->osRegionName = $regionName;

        return $this;
    }

    public function getOSProject(): ?OSProject
    {
        return $this->osProject;
    }

    public function setOSProject(?OSProject $osProject): self
    {
        $this->osProject = $osProject;

        return $this;
    }


    /**
     * @return Collection|BackupConfiguration[]
     */
    public function getBackupConfigurations(): Collection
    {
        return $this->backupConfigurations;
    }

    public function addBackupConfiguration(BackupConfiguration $backupConfiguration): self
    {
        if (!$this->backupConfigurations->contains($backupConfiguration)) {
            $this->backupConfigurations[] = $backupConfiguration;
            $backupConfiguration->setOsInstance($this);
        }

        return $this;
    }

    public function removeBackupConfiguration(BackupConfiguration $backupConfiguration): self
    {
        if ($this->backupConfigurations->removeElement($backupConfiguration)) {
            // set the owning side to null (unless already changed)
            if ($this === $this) {
                $backupConfiguration->setOsInstance(null);
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
}
