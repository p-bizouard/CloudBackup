<?php

namespace App\Controller\Admin;

use App\Entity\OSInstance;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Override;

/**
 * @extends AbstractCrudController<OSInstance>
 */
class OSInstanceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OSInstance::class;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Openstack instances')
            ->setPageTitle('new', 'New Openstack instance')
            ->setPageTitle('detail', fn (OSInstance $osInstance) => (string) $osInstance)
            ->setPageTitle('edit', fn (OSInstance $osInstance) => \sprintf('Edit <b>%s</b>', $osInstance))
        ;
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('id'),
            TextField::new('name'),
            TextField::new('osRegionName'),
            AssociationField::new('osProject'),
            AssociationField::new('backupConfigurations')->hideOnForm(),
        ];
    }

    /*
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id'),
            TextField::new('title'),
            TextEditorField::new('description'),
        ];
    }
    */
}
