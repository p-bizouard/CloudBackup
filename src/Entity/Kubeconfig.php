<?php

namespace App\Entity;

use App\Repository\KubeconfigRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Stringable;

#[ORM\Entity(repositoryClass: KubeconfigRepository::class)]
#[ORM\Table(name: '`kubeconfig`')]
class Kubeconfig implements Stringable
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $kubeconfig = null;

    /**
     * @var Collection<int, BackupConfiguration>
     */
    #[ORM\OneToMany(targetEntity: BackupConfiguration::class, mappedBy: 'kubeconfig')]
    private Collection $backupConfigurations;

    public function __construct()
    {
        $this->backupConfigurations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getKubeconfig(): ?string
    {
        return $this->kubeconfig;
    }

    public function setKubeconfig(?string $kubeconfig): static
    {
        $this->kubeconfig = $kubeconfig;

        return $this;
    }

    /**
     * @return Collection<int, BackupConfiguration>
     */
    public function getBackupConfigurations(): Collection
    {
        return $this->backupConfigurations;
    }

    public function addBackupConfiguration(BackupConfiguration $backupConfiguration): static
    {
        if (!$this->backupConfigurations->contains($backupConfiguration)) {
            $this->backupConfigurations->add($backupConfiguration);
            $backupConfiguration->setKubeconfig($this);
        }

        return $this;
    }

    public function removeBackupConfiguration(BackupConfiguration $backupConfiguration): static
    {
        // set the owning side to null (unless already changed)
        if ($this->backupConfigurations->removeElement($backupConfiguration) && $backupConfiguration->getKubeconfig() === $this) {
            $backupConfiguration->setKubeconfig(null);
        }

        return $this;
    }
}
