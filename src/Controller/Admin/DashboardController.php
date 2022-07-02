<?php

namespace App\Controller\Admin;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Host;
use App\Entity\Log;
use App\Entity\OSInstance;
use App\Entity\OSProject;
use App\Entity\Storage;
use App\Entity\User;
use App\Repository\BackupConfigurationRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private BackupConfigurationRepository $backupConfigurationRepository
    ) {
    }

    /**
     * @Route("/", name="admin_dashboard")
     */
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'backupConfigurationTypes' => BackupConfiguration::getAvailableTypes(),
            'backupConfigurations' => $this->backupConfigurationRepository->findAll(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Gestionnaire de backups');
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDefaultSort(['id' => 'DESC'])
        ;
    }

    public function configureAssets(): Assets
    {
        $assets = parent::configureAssets();

        return $assets
            ->addWebpackEncoreEntry('admin-app')
        ;
    }

    public function configureActions(): Actions
    {
        $actions = parent::configureActions();

        return $actions
        ->add(Crud::PAGE_INDEX, Action::DETAIL)
        ->add(Crud::PAGE_EDIT, Action::INDEX)
        ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
        ;
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linktoDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Configuration');
        yield MenuItem::linkToCrud('Utilisateurs', 'fas fa-users', User::class);
        yield MenuItem::linkToCrud('Project Openstack', 'fas fa-folder-open', OSProject::class);
        yield MenuItem::linkToCrud('Stockage', 'fas fa-hdd', Storage::class);
        yield MenuItem::linkToCrud('Logs', 'fas fa-volume-up', Log::class);

        yield MenuItem::section('Sources');
        yield MenuItem::linkToCrud('Instances Openstack', 'fas fa-cloud', OSInstance::class);
        yield MenuItem::linkToCrud('Serveurs SSH', 'fas fa-server', Host::class);

        yield MenuItem::section('Sauvegardes');
        yield MenuItem::linkToCrud('Programmation', 'fas fa-cogs', BackupConfiguration::class);
        yield MenuItem::linkToCrud('Sauvegardes réalisées', 'fas fa-save', Backup::class);
        // yield MenuItem::linkToCrud('The Label', 'fas fa-list', EntityClass::class);
    }
}
