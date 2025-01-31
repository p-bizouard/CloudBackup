<?php

namespace App\Controller\Admin;

use App\Entity\Host;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Override;

class HostCrudController extends AbstractCrudController
{
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
