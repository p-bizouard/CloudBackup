<?php

namespace App\Controller\Admin;

use App\Entity\Storage;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminAction;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @extends AbstractCrudController<Storage>
 */
class StorageCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Storage::class;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Storages')
            ->setPageTitle('new', 'New storage')
            ->setPageTitle('detail', fn (Storage $storage) => (string) $storage)
            ->setPageTitle('edit', fn (Storage $storage) => \sprintf('Edit <b>%s</b>', $storage))
        ;
    }

    #[Override]
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

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addFieldset('General configuration'),
            TextField::new('name'),
            TextareaField::new('description'),
            ChoiceField::new('type')->setChoices(function () {
                $return = [];
                foreach (Storage::getAvailableTypes() as $type) {
                    $return[$type] = $type;
                }

                return $return;
            }),
            AssociationField::new('backupConfigurations')->hideOnForm(),
            FormField::addFieldset('Restic configuration')->addCssClass('storage-type-panel type-restic'),
            TextField::new('resticRepo')
                ->setHelp(\sprintf('%s<br />%s<br />%s',
                    'Local : /data',
                    'S3 : s3:https://minio/bucket/subdirectory',
                    'Swift : swift:container:/subdirectory'
                )),
            TextField::new('resticPassword')->hideOnIndex(),
            FormField::addFieldset('Restic with Openstack Swift storage')->addCssClass('storage-type-panel type-restic'),
            AssociationField::new('osProject'),
            TextField::new('osRegionName'),
            FormField::addFieldset('Restic with S3 storage')->addCssClass('storage-type-panel type-restic'),
            TextField::new('awsAccessKeyId')->hideOnIndex(),
            TextField::new('awsSecretAccessKey')->hideOnIndex(),
            TextField::new('awsDefaultRegion')->hideOnIndex(),
            FormField::addFieldset('Rclone configuration')->addCssClass('storage-type-panel type-rclone'),
            TextareaField::new('rcloneConfiguration')
                ->hideOnIndex()
                ->setHelp('See <a href="https://rclone.org/docs/">https://rclone.org/docs/</a> and paste your configuration here.<br />Must not contain password and password2, we will add them automatically from password and salt fields.'),
        ];
    }

    #[AdminAction('{entityId}/copy_entity', 'admin_storage_copy_entity')]
    public function copyEntity(AdminContext $adminContext): RedirectResponse
    {
        /** @var Storage */
        $storage = $adminContext->getEntity()->getInstance();
        $newstorage = clone $storage;

        $newstorage->setName(\sprintf('Copy %s', $newstorage->getName()));

        $this->entityManager->persist($newstorage);
        $this->entityManager->flush();

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($newstorage->getId())
            ->generateUrl();

        return new RedirectResponse($url);
    }
}
