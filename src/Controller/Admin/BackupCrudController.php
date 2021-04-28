<?php

namespace App\Controller\Admin;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BackupCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Backup::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Liste des sauvegardes')
            ->setPageTitle('new', 'Nouvelle sauvegardes')
            ->setPageTitle('detail', fn (Backup $entity) => (string) $entity)
            ->setPageTitle('edit', fn (Backup $entity) => sprintf('Modification de <b>%s</b>', $entity))

            ->overrideTemplate('crud/detail', 'admin/backup/detail.html.twig')
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('backupConfiguration')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->remove(Crud::PAGE_INDEX, ACTION::EDIT)
            ->remove(Crud::PAGE_DETAIL, ACTION::EDIT)
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('backupConfiguration')->setLabel('Programmation'),
            ChoiceField::new('backupConfiguration.type')->setChoices(array_flip(BackupConfiguration::getAvailableTypes()))->hideOnForm(),
            TextField::new('currentPlace'),
            AssociationField::new('logs')->hideOnForm(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
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
