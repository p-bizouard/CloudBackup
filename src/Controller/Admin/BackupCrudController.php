<?php

namespace App\Controller\Admin;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Workflow\Registry;

class BackupCrudController extends AbstractCrudController
{
    public function __construct(private Registry $workflowRegistry)
    {
    }

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

            ->setSearchFields(['backupConfiguration.name', 'backupConfiguration.type', 'backup.currentPlace'])
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('backupConfiguration')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('backupConfiguration')
                ->setLabel('Programmation')
                ->hideOnForm(),
            ChoiceField::new('backupConfiguration.type')
                ->setChoices(function () {
                    $return = [];
                    foreach (BackupConfiguration::getAvailableTypes() as $type) {
                        $return[$type] = $type;
                    }

                    return $return;
                })
                ->hideOnForm(),
            ChoiceField::new('currentPlace')
                ->setChoices($this->workflowRegistry->get(new Backup())->getDefinition()->getPlaces()),
            IntegerField::new('resticSize')
                ->setLabel('Backup size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnForm(),
            IntegerField::new('resticDedupSize')
                ->setLabel('Backup deduplicated size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnIndex()
                ->hideOnForm(),
            IntegerField::new('resticTotalSize')
                ->setLabel('Repository virtual size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnIndex()
                ->hideOnForm(),
            IntegerField::new('resticTotalDedupSize')
                ->setLabel('Repository deduplicated size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnIndex()
                ->hideOnForm(),
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
