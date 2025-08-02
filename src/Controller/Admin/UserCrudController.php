<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Override;

/**
 * @extends AbstractCrudController<User>
 */
class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Users list')
            ->setPageTitle('new', 'New user')
            ->setPageTitle('detail', fn (User $user) => (string) $user)
            ->setPageTitle('edit', fn (User $user) => \sprintf('Edit <b>%s</b>', $user->getEmail()))
        ;
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        $return = [EmailField::new('email')];

        if ('' === $this->getParameter('cas_base_url')) {
            $return[] = TextField::new('plainPassword')
                ->onlyOnForms()
                ->addCssClass('blur-input');
        }

        return $return;
    }

    #[Override]
    public function createEntity(string $entityFqcn): User
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        return $user;
    }
}
