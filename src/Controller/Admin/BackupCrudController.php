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
            ->setPageTitle('index', 'backups')
            ->setPageTitle('new', 'New sauvegardes')
            ->setPageTitle('detail', fn (Backup $entity) => (string) $entity)
            ->setPageTitle('edit', fn (Backup $entity) => sprintf('Edit <b>%s</b>', $entity))

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
                ->setLabel('Scheduling')
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
                ->setChoices($this->workflowRegistry->get(new Backup())->getDefinition()->getPlaces())
                ->setTemplatePath('admin/fields/currentPlace.html.twig'),
            IntegerField::new('size')
                ->setLabel('Backup size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnForm(),
            IntegerField::new('resticSize')
                ->setLabel('Restic snapshot size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnForm(),
            IntegerField::new('resticDedupSize')
                ->setLabel('Restic snapshot deduplicated size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnIndex()
                ->hideOnForm(),
            IntegerField::new('resticTotalSize')
                ->setLabel('Restic total size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnIndex()
                ->hideOnForm(),
            IntegerField::new('resticTotalDedupSize')
                ->setLabel('Restic total deduplicated size')
                ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                ->hideOnIndex()
                ->hideOnForm(),
            AssociationField::new('logs')->hideOnForm(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
