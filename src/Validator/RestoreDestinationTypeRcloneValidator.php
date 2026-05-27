<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\RestoreDestination;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class RestoreDestinationTypeRcloneValidator extends ConstraintValidator
{
    /**
     * @param RestoreDestination|mixed $destination
     */
    public function validate($destination, Constraint $constraint): void
    {
        if (!$destination instanceof RestoreDestination) {
            throw new UnexpectedValueException($destination, RestoreDestination::class);
        }

        if (!$constraint instanceof RestoreDestinationTypeRclone) {
            throw new UnexpectedValueException($constraint, RestoreDestinationTypeRclone::class);
        }

        if (RestoreDestination::TYPE_RCLONE !== $destination->getType()) {
            return;
        }

        if (null === $destination->getStorage()) {
            $this->context->buildViolation($constraint->storageMandatory)
                ->atPath('storage')
                ->addViolation();
        }

        if (null === $destination->getTargetPath() || '' === trim((string) $destination->getTargetPath())) {
            $this->context->buildViolation($constraint->targetPathMandatory)
                ->atPath('targetPath')
                ->addViolation();
        }
    }
}
