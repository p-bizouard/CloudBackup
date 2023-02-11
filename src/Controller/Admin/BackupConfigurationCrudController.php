<?php

namespace App\Controller\Admin;

use App\Entity\BackupConfiguration;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

class BackupConfigurationCrudController extends AbstractCrudController
{
    public function __construct(private AdminUrlGenerator $adminUrlGenerator)
    {
    }

    public static function getEntityFqcn(): string
    {
        return BackupConfiguration::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'backup schedulings')
            ->setPageTitle('new', 'New backup scheduling')
            ->setPageTitle('detail', fn (BackupConfiguration $entity) => (string) $entity)
            ->setPageTitle('edit', fn (BackupConfiguration $entity) => sprintf('Edit <b>%s</b>', $entity))

            ->overrideTemplate('crud/detail', 'admin/backup_configuration/detail.html.twig')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $copyEntity = Action::new('copyEntity', 'Duplicate')
            ->linkToCrudAction('copyEntity');

        return $actions
            ->add(Crud::PAGE_DETAIL, $copyEntity)
            ->add(Crud::PAGE_EDIT, $copyEntity)
            ->add(Crud::PAGE_INDEX, $copyEntity)
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('General configuration')
                ->setIcon('fas fa-cogs'),

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
                IntegerField::new('keepDaily')->hideOnIndex(),
                IntegerField::new('keepWeekly')->hideOnIndex(),

                IntegerField::new('notBefore')
                    ->hideOnIndex()
                    ->setHelp('Schedule after this hour'),

                DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex(),
                DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex(),

            FormField::addPanel('Backup storage')
                ->setIcon('fas fa-hdd'),

                AssociationField::new('storage'),
                TextField::new('storageSubPath')->hideOnIndex(),

            FormField::addPanel('Backup source')
                ->hideOnIndex()
                ->setIcon('fas fa-server'),

                AssociationField::new('osInstance')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field os-instance')
                    ->setHelp('Openstack Instance to backup'),

                AssociationField::new('s3Bucket')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field s3-bucket')
                    ->setHelp('S3 Bucket to backup'),

                AssociationField::new('host')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field postgresql mysql ssh-restic sshfs ssh-cmd sftp')
                    ->setHelp('Host to backup'),

                TextField::new('dumpCommand')
                    ->hideOnIndex()
                    ->addCssClass('blur-input backupConfigurationType-field postgresql mysql ssh-restic sshfs ssh-cmd sftp')
                    ->setHelp('MySQL/PostgreSQL dump command, or SSHFS options'),

                TextField::new('remoteCleanCommand')
                    ->hideOnIndex()
                    ->addCssClass('blur-input backupConfigurationType-field ssh-cmd')
                    ->setHelp('Command to clean the remote host after backup'),

                TextField::new('customExtension')
                    ->hideOnIndex()
                    ->setHelp('Suffix the backup with custom extension')
                    ->addCssClass('backupConfigurationType-field os-instance postgresql mysql sql-server ssh-cmd'),

                TextField::new('remotePath')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field ssh-restic sshfs sftp')
                    ->setHelp('Folder to backup'),

                IntegerField::new('minimumBackupSize')
                    ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field postgresql mysql ssh-cmd sftp')
                    ->setHelp('Minimal size of the backup (in bytes)'),
        ];
    }

    public function copyEntity(AdminContext $context): RedirectResponse
    {
        /** @var BackupConfiguration */
        $backupConfiguration = $context->getEntity()->getInstance();
        $newBackupConfiguration = clone $backupConfiguration;

        $newBackupConfiguration->setName(sprintf('Copy %s', $newBackupConfiguration->getName()));
        $newBackupConfiguration->setEnabled(false);

        $entityManager = $this->getDoctrine()->getManagerForClass(self::getEntityFqcn());
        $entityManager->persist($newBackupConfiguration);
        $entityManager->flush();

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($newBackupConfiguration->getId())
            ->generateUrl();

        return new RedirectResponse($url);
    }
}
