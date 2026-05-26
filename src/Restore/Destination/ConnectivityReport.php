<?php

declare(strict_types=1);

namespace App\Restore\Destination;

final class ConnectivityReport
{
    public const string STATUS_PASS = 'pass';
    public const string STATUS_FAIL = 'fail';
    public const string STATUS_SKIP = 'skip';

    /**
     * @param list<array{label: string, status: string, details: string}> $checks
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $checks,
    ) {
    }

    public static function passing(): self
    {
        return new self(true, []);
    }

    public static function notImplemented(string $type): self
    {
        return new self(false, [[
            'label' => 'Connectivity probe',
            'status' => self::STATUS_SKIP,
            'details' => \sprintf('Probe not implemented for type "%s" yet.', $type),
        ]]);
    }

    /**
     * @return array{ok: bool, checks: list<array{label: string, status: string, details: string}>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'checks' => $this->checks,
        ];
    }
}
