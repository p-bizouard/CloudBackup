<?php

namespace App\Twig;

use App\Entity\Backup;
use App\Utils\StringUtils;
use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    #[Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('humanizeFileSize', StringUtils::humanizeFileSize(...)),
            new TwigFilter('bootstrapColor', Backup::staticBootstrapColor(...)),
        ];
    }
}
