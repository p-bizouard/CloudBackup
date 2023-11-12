<?php

namespace App\Entity;

use App\Repository\OSProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=OSProjectRepository::class)
 */
class OSProject implements Stringable
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
     *
     * @Assert\NotNull()
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
     *
     * @Assert\NotNull()
     */
    private ?string $authUrl = null;

    /**
     * @ORM\Column(type="integer")
     *
     * @Assert\NotNull()
     */
    private ?int $identityApiVersion = null;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Assert\NotNull()
     */
    private ?string $userDomainName = null;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Assert\NotNull()
     */
    private ?string $projectDomainName = null;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Assert\NotNull()
     */
    private ?string $tenantId = null;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Assert\NotNull()
     */
    private ?string $tenantName = null;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Assert\NotNull()
     */
    private ?string $username = null;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Assert\NotNull()
     */
    private ?string $password = null;

    /**
     * @var Collection<int, OSInstance>
     *
     * @ORM\OneToMany(targetEntity=OSInstance::class, mappedBy="osProject")
     */
    private Collection $osInstances;

    /**
     * @var Collection<int, Storage>
     *
     * @ORM\OneToMany(targetEntity=Storage::class, mappedBy="osProject")
     */
    private Collection $storages;

    public function __construct()
    {
        $this->osInstances = new ArrayCollection();
        $this->storages = new ArrayCollection();
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

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getAuthUrl(): ?string
    {
        return $this->authUrl;
    }

    public function setAuthUrl(string $authUrl): self
    {
        $this->authUrl = $authUrl;

        return $this;
    }

    public function getIdentityApiVersion(): ?int
    {
        return $this->identityApiVersion;
    }

    public function setIdentityApiVersion(int $identityApiVersion): self
    {
        $this->identityApiVersion = $identityApiVersion;

        return $this;
    }

    public function getUserDomainName(): ?string
    {
        return $this->userDomainName;
    }

    public function setUserDomainName(string $userDomainName): self
    {
        $this->userDomainName = $userDomainName;

        return $this;
    }

    public function getProjectDomainName(): ?string
    {
        return $this->projectDomainName;
    }

    public function setProjectDomainName(string $projectDomainName): self
    {
        $this->projectDomainName = $projectDomainName;

        return $this;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setTenantId(string $tenantId): self
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function getTenantName(): ?string
    {
        return $this->tenantName;
    }

    public function setTenantName(string $tenantName): self
    {
        $this->tenantName = $tenantName;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function addOSInstance(OSInstance $osInstance): self
    {
        if (!$this->osInstances->contains($osInstance)) {
            $this->osInstances[] = $osInstance;
            $osInstance->setOSProject($this);
        }

        return $this;
    }

    public function removeOSInstance(OSInstance $osInstance): self
    {
        // set the owning side to null (unless already changed)
        if ($this->osInstances->removeElement($osInstance) && $osInstance->getOSProject() === $this) {
            $osInstance->setOSProject(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, OSInstance>
     */
    public function getOsInstances(): Collection
    {
        return $this->osInstances;
    }

    /**
     * @return Collection<int, Storage>
     */
    public function getStorages(): Collection
    {
        return $this->storages;
    }

    public function addStorage(Storage $storage): self
    {
        if (!$this->storages->contains($storage)) {
            $this->storages[] = $storage;
            $storage->setOsProject($this);
        }

        return $this;
    }

    public function removeStorage(Storage $storage): self
    {
        // set the owning side to null (unless already changed)
        if ($this->storages->removeElement($storage) && $storage->getOsProject() === $this) {
            $storage->setOsProject(null);
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
