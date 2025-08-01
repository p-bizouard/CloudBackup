<?php

namespace App\Controller\Admin;

use App\Entity\Log;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Override;

/**
 * @extends AbstractCrudController<Log>
 */
class LogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Log::class;
    }

    #[Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->remove(Crud::PAGE_DETAIL, Action::EDIT)
        ;
    }

    #[Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('backup')
        ;
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('level'),
            TextField::new('message')->setMaxLength(400),
            AssociationField::new('backup'),
            DateTimeField::new('createdAt'),
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
