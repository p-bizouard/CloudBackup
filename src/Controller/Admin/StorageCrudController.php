<?php

namespace App\Controller\Admin;

use App\Entity\Storage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class StorageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Storage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Liste des espaces de stockage')
            ->setPageTitle('new', 'Nouvel espace de stockage')
            ->setPageTitle('detail', fn (Storage $entity) => (string) $entity)
            ->setPageTitle('edit', fn (Storage $entity) => sprintf('Modification de <b>%s</b>', $entity))
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name'),
            ChoiceField::new('type')->setChoices(function () {
                $return = [];
                foreach (Storage::getAvailableTypes() as $type) {
                    $return[$type] = $type;
                }

                return $return;
            }),
            AssociationField::new('osProject'),
            AssociationField::new('backupConfigurations'),
            TextField::new('osRegionName'),
            TextField::new('resticPassword')->hideOnIndex(),
            TextField::new('resticRepo')->hideOnIndex(),
            TextField::new('host')->hideOnIndex(),
            TextField::new('username')->hideOnIndex(),
            TextField::new('path')->hideOnIndex(),
            DateTimeField::new('created')->hideOnForm(),
            DateTimeField::new('updated')->hideOnForm(),
        ];
    }
}
