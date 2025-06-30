<?php

namespace App\Controller\Admin;

use App\Entity\Kubeconfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Override;

class KubeconfigCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Kubeconfig::class;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Kubeconfigs')
            ->setPageTitle('new', 'New Kubeconfig')
            ->setPageTitle('detail', fn (Kubeconfig $host) => (string) $host)
            // ->setPageTitle('edit', fn (Kubeconfig $host) => \sprintf('Edit <b>%s</b>', $host))
        ;
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name'),
            TextareaField::new('kubeconfig')
                ->hideOnIndex()
                ->addCssClass('blur-input'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }

    #[Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DETAIL)
        ;
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
