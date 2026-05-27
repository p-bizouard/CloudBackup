<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

#[Attribute]
class RestoreDestinationTypeRclone extends Constraint
{
    public string $storageMandatory = 'A target Storage is mandatory for rclone restore destinations.';
    public string $targetPathMandatory = 'A target path is mandatory for rclone restore destinations.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
