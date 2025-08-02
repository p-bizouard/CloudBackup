<?php

namespace App\Controller\Admin;

use App\Entity\OSProject;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Override;

/**
 * @extends AbstractCrudController<OSProject>
 */
class OSProjectCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly string $authUrl,
        private readonly int $identityApiVersion,
        private readonly string $userDomainName,
        private readonly string $projectDomainName,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return OSProject::class;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Openstack projects (for Swift storage or Instance backup)')
            ->setPageTitle('new', 'New Openstack project')
            ->setPageTitle('detail', fn (OSProject $osProject) => (string) $osProject)
            ->setPageTitle('edit', fn (OSProject $osProject) => \sprintf('Edit <b>%s</b>', $osProject))
        ;
    }

    #[Override]
    public function createEntity(string $entityFqcn): OSProject
    {
        $osProject = new OSProject();
        $osProject->setAuthUrl($this->authUrl);
        $osProject->setIdentityApiVersion($this->identityApiVersion);
        $osProject->setUserDomainName($this->userDomainName);
        $osProject->setProjectDomainName($this->projectDomainName);

        return $osProject;
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name'),
            AssociationField::new('osInstances')->hideOnForm(),
            AssociationField::new('storages')->hideOnForm(),
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
