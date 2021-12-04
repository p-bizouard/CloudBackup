<?php

namespace App\Controller\Admin;

use App\Entity\OSInstance;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class OSInstanceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OSInstance::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Liste des instances Openstack')
            ->setPageTitle('new', 'Nouvelle instance Openstack')
            ->setPageTitle('detail', fn (OSInstance $entity) => (string) $entity)
            ->setPageTitle('edit', fn (OSInstance $entity) => sprintf('Modification de <b>%s</b>', $entity))
        ;
    }

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
