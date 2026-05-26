<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

#[Attribute]
class RestoreDestinationTypePostgresql extends Constraint
{
    public string $connectionModeMandatory = 'A connection mode is mandatory for postgresql restore destinations.';
    public string $hostMandatory = 'A target SSH Host is mandatory for postgresql restore destinations in ssh mode.';
    public string $directHostMandatory = 'A target host (FQDN/IP) is mandatory for postgresql restore destinations in direct mode.';
    public string $directPortMandatory = 'A target port is mandatory for postgresql restore destinations in direct mode.';
    public string $databaseMandatory = 'A target database is mandatory for postgresql restore destinations.';
    public string $userMandatory = 'A target DB user is mandatory for postgresql restore destinations.';
    public string $passwordMandatory = 'A target DB password is mandatory for postgresql restore destinations.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
