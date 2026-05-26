<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\RestoreDestination;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class RestoreDestinationOverwriteRequiresDescriptionValidator extends ConstraintValidator
{
    /**
     * @param RestoreDestination|mixed $destination
     */
    public function validate($destination, Constraint $constraint): void
    {
        if (!$destination instanceof RestoreDestination) {
            throw new UnexpectedValueException($destination, RestoreDestination::class);
        }

        if (!$constraint instanceof RestoreDestinationOverwriteRequiresDescription) {
            throw new UnexpectedValueException($constraint, RestoreDestinationOverwriteRequiresDescription::class);
        }

        if (!$destination->isAllowOverwrite()) {
            return;
        }

        if (null === $destination->getDescription() || '' === trim((string) $destination->getDescription())) {
            $this->context->buildViolation($constraint->message)
                ->atPath('description')
                ->addViolation();
        }
    }
}
