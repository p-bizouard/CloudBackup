<?php

namespace App\Entity;

use App\Repository\OSInstanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Stringable;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OSInstanceRepository::class)]
class OSInstance implements Stringable
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotNull]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    private ?string $slug = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotNull]
    private ?string $osRegionName = null;

    #[ORM\ManyToOne(targetEntity: OSProject::class, inversedBy: 'osInstances')]
    #[Assert\NotNull]
    #[ORM\JoinColumn(nullable: false)]
    private ?OSProject $osProject = null;

    /**
     * @var Collection<int, BackupConfiguration>
     */
    #[ORM\OneToMany(targetEntity: BackupConfiguration::class, mappedBy: 'osInstance')]
    private Collection $backupConfigurations;

    public function __construct()
    {
        $this->backupConfigurations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    /**
     * @return array<string, mixed>
     */
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
            'OS_REGION_NAME' => $this->getOSRegionName(),
        ];
    }

    public function setId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): ?string
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
     * @return Collection<int, BackupConfiguration>
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
        // set the owning side to null (unless already changed)
        if ($this->backupConfigurations->removeElement($backupConfiguration) && $backupConfiguration->getOsInstance() === $this) {
            $backupConfiguration->setOsInstance(null);
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
