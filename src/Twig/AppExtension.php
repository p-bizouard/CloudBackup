<?php

namespace App\Twig;

use App\Entity\Backup;
use App\Utils\StringUtils;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function getFilters()
    {
        return [
            new TwigFilter('humanizeFilesize', [StringUtils::class, 'humanizeFilesize']),
            new TwigFilter('bootstrapColor', [Backup::class, 'staticBootstrapColor']),
        ];
    }
}
