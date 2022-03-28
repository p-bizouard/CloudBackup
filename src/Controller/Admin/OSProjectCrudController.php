<?php

namespace App\Controller\Admin;

use App\Entity\OSProject;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class OSProjectCrudController extends AbstractCrudController
{
    public function __construct(
        private string $authUrl,
        private int $identityApiVersion,
        private string $userDomainName,
        private string $projectDomainName
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return OSProject::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Liste des projets Openstack')
            ->setPageTitle('new', 'Nouveau projet Openstack')
            ->setPageTitle('detail', fn (OSProject $entity) => (string) $entity)
            ->setPageTitle('edit', fn (OSProject $entity) => sprintf('Modification de <b>%s</b>', $entity))
        ;
    }

    public function createEntity(string $entityFqcn): OSProject
    {
        $project = new OSProject();
        $project->setAuthUrl($this->authUrl);
        $project->setIdentityApiVersion($this->identityApiVersion);
        $project->setUserDomainName($this->userDomainName);
        $project->setProjectDomainName($this->projectDomainName);

        return $project;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name'),
            TextField::new('authUrl')->hideOnIndex(),
            IntegerField::new('identityApiVersion')->hideOnIndex(),
            TextField::new('userDomainName')->hideOnIndex(),
            TextField::new('projectDomainName')->hideOnIndex(),
            TextField::new('tenantId'),
            TextField::new('tenantName'),
            TextField::new('username')->hideOnIndex(),
            TextField::new('password')
                ->hideOnIndex()
                ->addCssClass('blur-input'),
        ];
    }
}
