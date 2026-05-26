<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\RestoreDestination;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class RestoreDestinationTypePostgresqlValidator extends ConstraintValidator
{
    /**
     * @param RestoreDestination|mixed $destination
     */
    public function validate($destination, Constraint $constraint): void
    {
        if (!$destination instanceof RestoreDestination) {
            throw new UnexpectedValueException($destination, RestoreDestination::class);
        }

        if (!$constraint instanceof RestoreDestinationTypePostgresql) {
            throw new UnexpectedValueException($constraint, RestoreDestinationTypePostgresql::class);
        }

        if (RestoreDestination::TYPE_POSTGRESQL !== $destination->getType()) {
            return;
        }

        $mode = $destination->getConnectionMode();
        if (null === $mode) {
            $this->context->buildViolation($constraint->connectionModeMandatory)
                ->atPath('connectionMode')
                ->addViolation();
        } elseif (RestoreDestination::CONNECTION_SSH === $mode) {
            if (null === $destination->getHost()) {
                $this->context->buildViolation($constraint->hostMandatory)
                    ->atPath('host')
                    ->addViolation();
            }
        } else {
            if (null === $destination->getDirectHost() || '' === trim((string) $destination->getDirectHost())) {
                $this->context->buildViolation($constraint->directHostMandatory)
                    ->atPath('directHost')
                    ->addViolation();
            }
            if (null === $destination->getDirectPort()) {
                $this->context->buildViolation($constraint->directPortMandatory)
                    ->atPath('directPort')
                    ->addViolation();
            }
        }

        if (null === $destination->getTargetDatabase() || '' === trim((string) $destination->getTargetDatabase())) {
            $this->context->buildViolation($constraint->databaseMandatory)
                ->atPath('targetDatabase')
                ->addViolation();
        }

        if ($destination->isBringOwnCredentials()) {
            return;
        }

        if (null === $destination->getTargetDbUser() || '' === trim((string) $destination->getTargetDbUser())) {
            $this->context->buildViolation($constraint->userMandatory)
                ->atPath('targetDbUser')
                ->addViolation();
        }

        if (null === $destination->getTargetDbPassword() || '' === $destination->getTargetDbPassword()) {
            $this->context->buildViolation($constraint->passwordMandatory)
                ->atPath('targetDbPassword')
                ->addViolation();
        }
    }
}
