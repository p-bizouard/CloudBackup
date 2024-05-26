<?php

namespace App\Controller\Admin;

use App\Entity\BackupConfiguration;
use Doctrine\Persistence\ManagerRegistry;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

class BackupConfigurationCrudController extends AbstractCrudController
{
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator, private readonly ManagerRegistry $managerRegistry)
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
            ->setPageTitle('detail', fn (BackupConfiguration $backupConfiguration) => (string) $backupConfiguration)
            ->setPageTitle('edit', fn (BackupConfiguration $backupConfiguration) => sprintf('Edit <b>%s</b>', $backupConfiguration))

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
                IntegerField::new('keepDaily')
                    ->hideOnIndex()
                    ->setHelp(sprintf('%s<br />%s',
                        'Restic: how many time do we keep daily backups (keep at least 7)',
                        'Rclone: how many time do we keep deleted or overwritten files (keep at least 180)',
                    )),
                IntegerField::new('keepWeekly')
                    ->hideOnIndex()
                    ->addCssClass(sprintf('backupConfigurationType-field %s', implode(' ', BackupConfiguration::getAvailableTypesExept([BackupConfiguration::TYPE_RCLONE])))),

                IntegerField::new('notBefore')
                    ->hideOnIndex()
                    ->setHelp('Schedule after this hour'),

                IntegerField::new('notifyEvery')
                    ->setHelp('When error occured, notify every X runs. Use 0 to notifications'),

                DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex(),
                DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex(),

            FormField::addPanel('Backup storage')
                ->setIcon('fas fa-hdd'),

                AssociationField::new('storage'),
                TextField::new('storageSubPath')
                    ->hideOnIndex()
                    ->setHelp('Restic subdirectory or Rclone full path with remote'),
                TextField::new('rcloneBackupDir')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field rclone')
                    ->setHelp('Rclone backup directory. If valued, modified or deleted files will be moved to this directory instead of being deleted.'),
                TextareaField::new('rcloneConfiguration')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field rclone')
                    ->setHelp('See <a href="https://rclone.org/docs/">https://rclone.org/docs/</a> and paste your configuration here. It will be appended with the storage rclone configuration.'),
                TextField::new('rcloneFlags')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field rclone')
                    ->setHelp('Additional flags to pass to rclone sync command (--verbose, --ignore-errors, ...)'),
                TextField::new('resticCheckTags')
                    ->hideOnIndex()
                    ->addCssClass(sprintf('backupConfigurationType-field %s', implode(' ', BackupConfiguration::getAvailableTypesExept([BackupConfiguration::TYPE_RCLONE]))))
                    ->setHelp('Filter restic snapshot with provided tags. Usefull to check specific Velero volume'),

            FormField::addPanel('Backup source')
                ->hideOnIndex()
                ->setIcon('fas fa-server'),

                AssociationField::new('osInstance')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field os-instance')
                    ->setHelp('Openstack Instance to backup'),

                AssociationField::new('host')
                    ->setRequired(false)
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field postgresql mysql ssh-restic sshfs ssh-cmd sftp')
                    ->setHelp('Host to backup or to execute remote command'),

                TextField::new('dumpCommand')
                    ->hideOnIndex()
                    ->addCssClass('blur-input backupConfigurationType-field postgresql mysql ssh-restic sshfs ssh-cmd sftp')
                    ->setHelp('MySQL/PostgreSQL dump command, or SSHFS options'),

                TextField::new('remoteCleanCommand')
                    ->hideOnIndex()
                    ->addCssClass('blur-input backupConfigurationType-field ssh-cmd')
                    ->setHelp('Command to clean the remote host after backup'),

                TextField::new('stdErrIgnore')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationStorage-field rclone')
                    ->setHelp('Regex to ignore errors in stderr. If all lines are ignored, the backup will be considered as successful'),

                TextField::new('customExtension')
                    ->hideOnIndex()
                    ->setHelp('Suffix the backup with custom extension')
                    ->addCssClass('backupConfigurationType-field os-instance postgresql mysql sql-server ssh-cmd'),

                TextField::new('remotePath')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field ssh-restic sshfs sftp rclone')
                    ->setHelp('Folder to backup'),

                IntegerField::new('minimumBackupSize')
                    ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                    ->hideOnIndex()
                    ->addCssClass(sprintf('backupConfigurationType-field %s', implode(' ', BackupConfiguration::getAvailableTypes())))
                    ->setHelp('Minimal size of the backup (in bytes)'),
        ];
    }

    public function copyEntity(AdminContext $adminContext): RedirectResponse
    {
        /** @var BackupConfiguration */
        $backupConfiguration = $adminContext->getEntity()->getInstance();
        $newBackupConfiguration = clone $backupConfiguration;

        $newBackupConfiguration->setName(sprintf('Copy %s', $newBackupConfiguration->getName()));
        $newBackupConfiguration->setEnabled(false);

        $entityManager = $this->managerRegistry->getManagerForClass(self::getEntityFqcn());
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
