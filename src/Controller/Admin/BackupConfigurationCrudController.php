<?php

namespace App\Controller\Admin;

use App\Entity\BackupConfiguration;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BackupConfigurationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BackupConfiguration::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Liste des programmations')
            ->setPageTitle('new', 'Nouvelle programmation')
            ->setPageTitle('detail', fn (BackupConfiguration $entity) => (string) $entity)
            ->setPageTitle('edit', fn (BackupConfiguration $entity) => sprintf('Modification de <b>%s</b>', $entity))

            ->overrideTemplate('crud/detail', 'admin/backup_configuration/detail.html.twig')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name'),
            ChoiceField::new('type')->setChoices(function () {
                $return = [];
                foreach (BackupConfiguration::getAvailableTypes() as $type) {
                    $return[$type] = $type;
                }

                return $return;
            }),
            ChoiceField::new('periodicity')->setChoices(function () {
                $return = [];
                foreach (BackupConfiguration::getAvailablePeriodicity() as $periodicity) {
                    $return[$periodicity] = $periodicity;
                }

                return $return;
            }),
            BooleanField::new('enabled'),
            IntegerField::new('keepDaily'),
            IntegerField::new('keepWeekly'),
            DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex(),
            AssociationField::new('osInstance'),
            AssociationField::new('host'),
            TextField::new('remotePath')->hideOnIndex(),
            AssociationField::new('storage'),
            TextField::new('storageSubPath')->hideOnIndex(),
            TextField::new('dumpCommand')->hideOnIndex(),
        ];
    }
}
