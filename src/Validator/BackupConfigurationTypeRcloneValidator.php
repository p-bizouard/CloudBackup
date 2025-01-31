<?php

namespace App\Validator;

use App\Entity\BackupConfiguration;
use App\Entity\Storage;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class BackupConfigurationTypeRcloneValidator extends ConstraintValidator
{
    /**
     * @param BackupConfiguration|mixed $backupConfiguration
     */
    public function validate($backupConfiguration, Constraint $constraint): void
    {
        if (!$backupConfiguration instanceof BackupConfiguration) {
            throw new UnexpectedValueException($backupConfiguration, BackupConfiguration::class);
        }

        if (!$constraint instanceof BackupConfigurationTypeRclone) {
            throw new UnexpectedValueException($constraint, BackupConfigurationTypeRclone::class);
        }

        $backupConfigurationType = $backupConfiguration->getType();
        $storageType = $backupConfiguration->getStorage()?->getType();

        if (BackupConfiguration::TYPE_RCLONE === $backupConfigurationType && $backupConfigurationType !== $storageType) {
            $this->context
                ->buildViolation($constraint->rcloneStorageMandatoryIfrcloneType)
                ->atPath('storage')
                ->addViolation();
        } elseif (Storage::TYPE_RCLONE === $storageType && $backupConfigurationType !== $storageType) {
            $this->context
                ->buildViolation($constraint->rcloneTypeMandatoryIfrcloneStorage)
                ->atPath('type')
                ->addViolation();
        }
    }
}
