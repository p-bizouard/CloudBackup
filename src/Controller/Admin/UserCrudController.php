<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Liste des utilisateurs')
            ->setPageTitle('new', 'Nouvel utilisateur')
            ->setPageTitle('detail', fn (User $user) => (string) $user)
            ->setPageTitle('edit', fn (User $user) => sprintf('Modification de <b>%s</b>', $user->getEmail()))
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            EmailField::new('email'),
            TextField::new('plainPassword')
                ->onlyOnForms()
                ->addCssClass('blur-input'),
        ];
    }

    public function createEntity(string $entityFqcn): User
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        return $user;
    }
}
