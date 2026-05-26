<?php

declare(strict_types=1);

namespace App\Restore\Destination;

use App\Entity\Backup;
use App\Entity\RestoreDestination;

interface RestoreDestinationStrategyInterface
{
    public function supports(RestoreDestination $destination): bool;

    public function validateConnectivity(RestoreDestination $destination): ConnectivityReport;

    public function renderBundle(RestoreDestination $destination, Backup $snapshot): RestoreBundle;
}
