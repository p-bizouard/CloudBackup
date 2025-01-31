<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Stringable;

#[ORM\Entity]
class Log implements Stringable
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue]
    protected ?int $id = null;

    #[ORM\Column(name: 'message', type: Types::TEXT)]
    private ?string $message = null;

    #[ORM\Column(name: 'level', type: Types::STRING, length: 50)]
    private ?string $level = null;

    #[ORM\ManyToOne(targetEntity: Backup::class, inversedBy: 'logs')]
    private ?Backup $backup = null;

    final public const string LOG_ERROR = 'error';
    final public const string LOG_WARNING = 'warning';
    final public const string LOG_NOTICE = 'notice';
    final public const string LOG_INFO = 'info';

    public function __toString(): string
    {
        return \sprintf('%s - %s', $this->level, $this->message);
    }

    public function getBootstrapColor(): ?string
    {
        return match ($this->level) {
            self::LOG_ERROR => 'danger',
            self::LOG_WARNING => 'warning',
            self::LOG_NOTICE => 'secondary',
            self::LOG_INFO => 'info',
            default => null,
        };
    }

    public function getMessageColor(): ?string
    {
        return match ($this->level) {
            self::LOG_ERROR => 'red',
            self::LOG_WARNING => 'orange',
            self::LOG_NOTICE => 'black',
            self::LOG_INFO => 'black',
            default => null,
        };
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
