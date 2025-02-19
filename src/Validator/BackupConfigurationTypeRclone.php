<?php

namespace App\Validator;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

#[Attribute]
class BackupConfigurationTypeRclone extends Constraint
{
    public string $rcloneTypeMandatoryIfrcloneStorage = 'An rclone type is mandatory if rclone storage is selected.';
    public string $rcloneStorageMandatoryIfrcloneType = 'An rclone storage is mandatory if rclone type is selected.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
