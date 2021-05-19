<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * @ORM\Entity()
 */
class Log
{
    use TimestampableEntity;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(name="message", type="text")
     */
    private $message;

    /**
     * @ORM\Column(name="level", type="string", length=50)
     */
    private $level;

    /**
     * @ORM\ManyToOne(targetEntity=Backup::class, inversedBy="logs")
     */
    private $backup;

    const LOG_ERROR = 'error';
    const LOG_WARNING = 'warning';
    const LOG_NOTICE = 'notice';
    const LOG_INFO = 'info';

    public function __toString(): string
    {
        return sprintf('%s - %s', $this->level, $this->message);
    }

    public function getBootstrapColor(): string
    {
        switch ($this->level) {
            case self::LOG_ERROR:
                return 'danger';
            case self::LOG_WARNING:
                return 'warning';
            case self::LOG_NOTICE:
                return 'info';
            case self::LOG_INFO:
                return 'secondary';
        }
    }

    public function getMessageColor(): string
    {
        switch ($this->level) {
            case self::LOG_ERROR:
                return 'red';
            case self::LOG_WARNING:
                return 'orange';
            case self::LOG_NOTICE:
                return 'black';
            case self::LOG_INFO:
                return 'black';
        }
    }

    /**
     * Get the value of id.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set the value of id.
     */
    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the value of message.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Set the value of message.
     */
    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Get the value of level.
     */
    public function getLevel(): ?string
    {
        return $this->level;
    }

    /**
     * Set the value of level.
     */
    public function setLevel(?string $level): self
    {
        $this->level = $level;

        return $this;
    }

    public function getBackup(): ?Backup
    {
        return $this->backup;
    }

    public function setBackup(?Backup $backup): self
    {
        $this->backup = $backup;

        return $this;
    }
}
