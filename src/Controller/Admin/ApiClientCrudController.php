<?php

namespace App\Controller\Admin;

use App\Entity\ApiClient;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Override;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @extends AbstractCrudController<ApiClient>
 */
class ApiClientCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ApiClient::class;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'API clients')
            ->setPageTitle('new', 'New API client')
            ->setPageTitle('detail', fn (ApiClient $apiClient) => (string) $apiClient)
            ->setPageTitle('edit', fn (ApiClient $apiClient) => \sprintf('Edit <b>%s</b>', $apiClient))
        ;
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        $isNew = Crud::PAGE_NEW === $pageName;
        $isEdit = Crud::PAGE_EDIT === $pageName;

        yield TextField::new('name');
        yield TextField::new('clientId')
            ->setHelp('Auto-generated. Cannot be changed.')
            ->setFormTypeOption('disabled', $isEdit);

        if ($isNew || $isEdit) {
            yield TextField::new('plainSecret')
                ->setLabel('Secret')
                ->setHelp($isEdit
                    ? 'Leave empty to keep current secret. Any value entered will replace it.'
                    : 'Auto-generated. Copy it now — it will only be shown once after creation.'
                )
                ->setFormTypeOption('required', false);
        }

        yield BooleanField::new('enabled');
        yield DateTimeField::new('lastUsedAt')->hideOnForm();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    #[Override]
    public function createEntity(string $entityFqcn): ApiClient
    {
        $apiClient = new ApiClient();
        $apiClient->setClientId(bin2hex(random_bytes(16)));
        $apiClient->setPlainSecret(bin2hex(random_bytes(24)));

        return $apiClient;
    }

    /**
     * @param ApiClient $entityInstance
     */
    #[Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $plain = $entityInstance->getPlainSecret();
        if (null === $plain || '' === $plain) {
            $plain = bin2hex(random_bytes(24));
            $entityInstance->setPlainSecret($plain);
        }
        $entityInstance->setSecret($this->passwordHasher->hashPassword($entityInstance, $plain));

        parent::persistEntity($entityManager, $entityInstance);

        $this->flashSecret($entityInstance, $plain);
    }

    /**
     * @param ApiClient $entityInstance
     */
    #[Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $plain = $entityInstance->getPlainSecret();
        if (null !== $plain && '' !== $plain) {
            $entityInstance->setSecret($this->passwordHasher->hashPassword($entityInstance, $plain));
            parent::updateEntity($entityManager, $entityInstance);
            $this->flashSecret($entityInstance, $plain);

            return;
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function flashSecret(ApiClient $apiClient, string $plain): void
    {
        $this->addFlash(
            'success',
            \sprintf(
                'API client secret (shown only once) — client_id: %s — secret: %s',
                $apiClient->getClientId(),
                $plain,
            )
        );
    }
}
