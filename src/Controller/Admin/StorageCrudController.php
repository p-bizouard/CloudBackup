<?php

namespace App\Controller\Admin;

use App\Entity\Storage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

class StorageCrudController extends AbstractCrudController
{
    public function __construct(private AdminUrlGenerator $adminUrlGenerator)
    {
    }

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
            TextField::new('name'),
            ChoiceField::new('type')->setChoices(function () {
                $return = [];
                foreach (Storage::getAvailableTypes() as $type) {
                    $return[$type] = $type;
                }

                return $return;
            }),
            AssociationField::new('backupConfigurations')->hideOnForm(),
            AssociationField::new('osProject'),
            TextField::new('osRegionName'),
            TextField::new('resticPassword')->hideOnIndex()->setRequired(true),
            TextField::new('resticRepo')->setRequired(true),
        ];
    }

    public function copyEntity(AdminContext $context)
    {
        /** @var Storage */
        $storage = $context->getEntity()->getInstance();
        $newstorage = clone $storage;

        $newstorage->setName(sprintf('Copie de %s', $newstorage->getName()));

        $entityManager = $this->getDoctrine()->getManagerForClass(self::getEntityFqcn());
        $entityManager->persist($newstorage);
        $entityManager->flush();

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($newstorage->getId())
            ->generateUrl();

        return new RedirectResponse($url);
    }
}
