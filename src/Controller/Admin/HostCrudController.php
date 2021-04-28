<?php

namespace App\Controller\Admin;

use App\Entity\Host;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class HostCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Host::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Liste des hôtes SSH')
            ->setPageTitle('new', 'Nouvel hôte SSH')
            ->setPageTitle('detail', fn (Host $entity) => (string) $entity)
            ->setPageTitle('edit', fn (Host $entity) => sprintf('Modification de <b>%s</b>', $entity))
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name'),
            TextField::new('ip'),
            TextField::new('login'),
            TextField::new('password')->hideOnIndex(),
            IntegerField::new('port')->hideOnIndex(),
            TextareaField::new('privateKey')->hideOnIndex(),
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
