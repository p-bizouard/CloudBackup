<?php

namespace App\Controller\Admin;

use App\Entity\Host;
use Doctrine\Persistence\ManagerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminAction;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @extends AbstractCrudController<Host>
 */
class HostCrudController extends AbstractCrudController
{
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator, private readonly ManagerRegistry $managerRegistry)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Host::class;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'SSH hosts (sftp, sshfs, custom ssh command to stdout, ...)')
            ->setPageTitle('new', 'New SSH host')
            ->setPageTitle('detail', fn (Host $host) => (string) $host)
            ->setPageTitle('edit', fn (Host $host) => \sprintf('Edit <b>%s</b>', $host))
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
            TextField::new('name'),
            TextField::new('ip'),
            TextField::new('login'),
            TextField::new('password')
                ->hideOnIndex()
                ->addCssClass('blur-input'),
            IntegerField::new('port')->hideOnIndex(),
            TextareaField::new('privateKey')
                ->hideOnIndex()
                ->addCssClass('blur-input'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }

    #[AdminAction('{entityId}/copy_entity', 'admin_host_copy_entity')]
    public function copyEntity(AdminContext $adminContext): RedirectResponse
    {
        /** @var Host */
        $host = $adminContext->getEntity()->getInstance();
        $newHost = clone $host;

        $newHost->setName(\sprintf('Copy %s', $newHost->getName()));
        $newHost->setSlug(null);

        $entityManager = $this->managerRegistry->getManagerForClass(self::getEntityFqcn());
        $entityManager->persist($newHost);
        $entityManager->flush();

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($newHost->getId())
            ->generateUrl();

        return new RedirectResponse($url);
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
