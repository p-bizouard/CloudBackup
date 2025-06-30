<?php

namespace App\Controller\Admin;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Host;
use App\Entity\Kubeconfig;
use App\Entity\Log;
use App\Entity\OSInstance;
use App\Entity\OSProject;
use App\Entity\Storage;
use App\Entity\User;
use App\Repository\BackupConfigurationRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Override;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/', routeName: 'admin_dashboard')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly BackupConfigurationRepository $backupConfigurationRepository,
    ) {
    }

    #[Override]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'backupConfigurationTypes' => BackupConfiguration::getAvailableTypes(),
            'backupConfigurations' => $this->backupConfigurationRepository->findBy(['enabled' => true]),
        ]);
    }

    #[Override]
    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Backups manager');
    }

    #[Override]
    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDefaultSort(['id' => 'DESC'])
        ;
    }

    #[Override]
    public function configureAssets(): Assets
    {
        $assets = parent::configureAssets();

        return $assets
            ->addWebpackEncoreEntry('admin-app')
        ;
    }

    #[Override]
    public function configureActions(): Actions
    {
        $actions = parent::configureActions();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
        ;
    }

    #[Override]
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linktoDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Configuration');
        yield MenuItem::linkToCrud('Users', 'fas fa-users', User::class);
        yield MenuItem::linkToCrud('Openstack projects', 'fas fa-folder-open', OSProject::class);
        yield MenuItem::linkToCrud('Storages', 'fas fa-hdd', Storage::class);
        yield MenuItem::linkToCrud('Logs', 'fas fa-volume-up', Log::class);

        yield MenuItem::section('Sources');
        yield MenuItem::linkToCrud('Openstack instances', 'fas fa-cloud', OSInstance::class);
        yield MenuItem::linkToCrud('SSH hosts', 'fas fa-server', Host::class);
        yield MenuItem::linkToCrud('Kubeconfigs', 'far fa-life-ring', Kubeconfig::class);

        yield MenuItem::section('Backups');
        yield MenuItem::linkToCrud('Schedulings', 'fas fa-cogs', BackupConfiguration::class);
        yield MenuItem::linkToCrud('Backups', 'fas fa-save', Backup::class);
    }
}
