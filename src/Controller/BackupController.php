<?php

namespace App\Controller;

use App\Command\BackupStartCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

class BackupController extends AbstractController
{
    #[Route('/command/backup/start')]
    public function executeBackupStart(Request $request, KernelInterface $kernel): Response
    {
        set_time_limit(0);

        $arrayInput = new ArrayInput([
            'command' => BackupStartCommand::getDefaultName(),
        ]);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->run($arrayInput);

        $route = $request->headers->get('referer', $this->generateUrl('admin_dashboard_backup_index'));

        $this->addFlash('success', 'Backup done');

        return $this->redirect($route);
    }
}
