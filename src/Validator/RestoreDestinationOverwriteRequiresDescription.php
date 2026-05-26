<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

#[Attribute]
class RestoreDestinationOverwriteRequiresDescription extends Constraint
{
    public string $message = 'A description is mandatory when allow_overwrite is enabled (forces explicit operator deliberation on destructive paths).';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
