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
            ->setPageTitle('index', 'Liste des programmations')
            ->setPageTitle('new', 'Nouvelle programmation')
            ->setPageTitle('detail', fn (BackupConfiguration $entity) => (string) $entity)
            ->setPageTitle('edit', fn (BackupConfiguration $entity) => sprintf('Modification de <b>%s</b>', $entity))

            ->overrideTemplate('crud/detail', 'admin/backup_configuration/detail.html.twig')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $copyEntity = Action::new('copyEntity', 'Dupliquer')
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
                    ->setHelp('Exécuter le backup à partir d\'une certaine heure'),

                DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex(),
                DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex(),

            FormField::addPanel('Destination des sauvegardes')
                ->setIcon('fas fa-hdd'),

                AssociationField::new('storage'),
                TextField::new('storageSubPath')->hideOnIndex(),

            FormField::addPanel('Source à sauvegarder')
                ->hideOnIndex()
                ->setIcon('fas fa-server'),

                AssociationField::new('osInstance')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field os-instance')
                    ->setHelp('Sauvegarde des instances Opentack'),

                AssociationField::new('host')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field postgresql mysql ssh-restic sshfs ssh-cmd sftp')
                    ->setHelp('Serveur de la source à sauvegarder'),

                TextField::new('dumpCommand')
                    ->hideOnIndex()
                    ->addCssClass('blur-input backupConfigurationType-field postgresql mysql ssh-restic sshfs ssh-cmd sftp')
                    ->setHelp('Commande de dump MySQL/PostgreSQL, ou options de montage SSHFS'),

                TextField::new('remoteCleanCommand')
                    ->hideOnIndex()
                    ->addCssClass('blur-input backupConfigurationType-field ssh-cmd')
                    ->setHelp('Commande de nettoyage à exécuter sur l\'hôte distant après le backup'),

                TextField::new('customExtension')
                    ->hideOnIndex()
                    ->setHelp('Permet de suffixer le backup avec une extension particulière'),

                TextField::new('remotePath')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field ssh-restic sshfs sftp')
                    ->setHelp('Dossier à sauvegarder'),

                IntegerField::new('minimumBackupSize')
                    ->setTemplatePath('admin/fields/humanizedFilesize.html.twig')
                    ->hideOnIndex()
                    ->addCssClass('backupConfigurationType-field postgresql mysql ssh-cmd sftp')
                    ->setHelp('Taille minimale du backup en octets'),
        ];
    }

    public function copyEntity(AdminContext $context): RedirectResponse
    {
        /** @var BackupConfiguration */
        $backupConfiguration = $context->getEntity()->getInstance();
        $newBackupConfiguration = clone $backupConfiguration;

        $newBackupConfiguration->setName(sprintf('Copie de %s', $newBackupConfiguration->getName()));
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
