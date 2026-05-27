<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Backup;
use App\Entity\RestoreBundleAudit;
use App\Entity\RestoreDestination;
use App\Repository\BackupRepository;
use App\Restore\Destination\RestoreDestinationStrategyRegistry;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminAction;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext as EAContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * @extends AbstractCrudController<RestoreDestination>
 */
class RestoreDestinationCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly RestoreDestinationStrategyRegistry $strategyRegistry,
        private readonly BackupRepository $backupRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return RestoreDestination::class;
    }

    /**
     * @return FormBuilderInterface<RestoreDestination>
     */
    #[Override]
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, EAContext $context): FormBuilderInterface
    {
        $builder = parent::createEditFormBuilder($entityDto, $formOptions, $context);
        $em = $this->entityManager;
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($em): void {
            $entity = $event->getData();
            if (!$entity instanceof RestoreDestination) {
                return;
            }
            $original = $em->getUnitOfWork()->getOriginalEntityData($entity);
            $posted = $entity->getTargetDbPassword();
            if ((null === $posted || '' === $posted) && !empty($original['targetDbPassword'])) {
                $entity->setTargetDbPassword($original['targetDbPassword']);
            }
        }, priority: 100);

        return $builder;
    }

    #[Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Restore destinations')
            ->setPageTitle('new', 'New restore destination')
            ->setPageTitle('detail', fn (RestoreDestination $d) => (string) $d)
            ->setPageTitle('edit', fn (RestoreDestination $d) => \sprintf('Edit <b>%s</b>', $d));
    }

    #[Override]
    public function configureActions(Actions $actions): Actions
    {
        $testConnection = Action::new('testConnection', 'Test connection', 'fa fa-plug')
            ->linkToCrudAction('testConnection')
            ->setCssClass('btn btn-secondary');

        return $actions
            ->add(Crud::PAGE_INDEX, $testConnection)
            ->add(Crud::PAGE_DETAIL, $testConnection);
    }

    #[Override]
    public function configureFields(string $pageName): iterable
    {
        $typeChoices = [];
        foreach (RestoreDestination::getAvailableTypes() as $type) {
            $typeChoices[$type] = $type;
        }
        $connectionChoices = [];
        foreach (RestoreDestination::getAvailableConnectionModes() as $mode) {
            $connectionChoices[$mode] = $mode;
        }

        $dbTypes = 'mysql,postgresql';

        $rowAttr = static fn (array $attrs): array => ['row_attr' => array_merge(['class' => 'restore-dest-field'], $attrs)];

        return [
            TextField::new('name'),
            ChoiceField::new('type')
                ->setChoices($typeChoices)
                ->setFormTypeOptions(['attr' => ['data-restore-type-select' => '1']]),
            TextareaField::new('description')->hideOnIndex(),
            BooleanField::new('enabled'),
            BooleanField::new('allowOverwrite')->setHelp('Required for destructive restores. Enable only with an explicit description.'),
            ChoiceField::new('connectionMode')
                ->setChoices($connectionChoices)
                ->setHelp('ssh = script SSHes to Host and runs mysql/pg_restore on target. direct = script runs mysql/pg_restore locally against host:port over network. mysql/postgresql only.')
                ->setFormTypeOptions(['attr' => ['data-restore-mode-select' => '1']] + $rowAttr(['data-show-type' => $dbTypes])),
            BooleanField::new('bringOwnCredentials')
                ->setHelp("When true: bundle omits db_password.txt; script relies on the operator's ~/.my.cnf or ~/.pgpass. mysql/postgresql only.")
                ->setFormTypeOptions(['attr' => ['data-restore-byo-select' => '1']] + $rowAttr(['data-show-type' => $dbTypes])),
            AssociationField::new('host')
                ->setHelp('SSH Host to connect to (ssh mode only).')
                ->setFormTypeOptions($rowAttr(['data-show-type' => $dbTypes, 'data-show-mode' => 'ssh'])),
            TextField::new('directHost')
                ->setHelp('Target host (FQDN or IP) reachable directly from where the bundle runs.')
                ->setFormTypeOptions($rowAttr(['data-show-type' => $dbTypes, 'data-show-mode' => 'direct']))
                ->hideOnIndex(),
            IntegerField::new('directPort')
                ->setHelp('Target DB port (3306 for mysql, 5432 for postgresql by default).')
                ->setFormTypeOptions($rowAttr(['data-show-type' => $dbTypes, 'data-show-mode' => 'direct']))
                ->hideOnIndex(),
            AssociationField::new('storage')
                ->setHelp('Target Storage bucket (rclone only).')
                ->setFormTypeOptions($rowAttr(['data-show-type' => 'rclone'])),
            TextField::new('targetDatabase')
                ->setHelp('Required for mysql/postgresql.')
                ->setFormTypeOptions($rowAttr(['data-show-type' => $dbTypes]))
                ->hideOnIndex(),
            TextField::new('targetDbUser')
                ->setHelp('Target DB account user. Leave blank if bring_own_credentials=true.')
                ->setFormTypeOptions($rowAttr(['data-show-type' => $dbTypes, 'data-show-byo' => 'false']))
                ->hideOnIndex(),
            TextField::new('targetDbPassword')
                ->setFormType(\Symfony\Component\Form\Extension\Core\Type\PasswordType::class)
                ->setFormTypeOption('always_empty', false)
                ->setRequired(false)
                ->setHelp('Target DB account password. Leave blank if bring_own_credentials=true.')
                ->setFormTypeOptions($rowAttr(['data-show-type' => $dbTypes, 'data-show-byo' => 'false']) + ['always_empty' => false])
                ->onlyOnForms(),
            TextField::new('targetPath')
                ->setHelp('Required for rclone (path inside target Storage).')
                ->setFormTypeOptions($rowAttr(['data-show-type' => 'rclone']))
                ->hideOnIndex(),
        ];
    }

    #[AdminAction(routePath: '/{entityId}/test-connection', routeName: 'admin_restore_destination_test_connection')]
    public function testConnection(AdminContext $context): Response
    {
        $destination = $context->getEntity()->getInstance();
        if (!$destination instanceof RestoreDestination) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid entity'], 400);
        }

        $strategy = $this->strategyRegistry->forDestination($destination);
        $report = $strategy->validateConnectivity($destination);

        return new JsonResponse($report->toArray());
    }

    #[AdminAction(routePath: '/{entityId}/generate-bundle', routeName: 'admin_restore_destination_generate_bundle', methods: ['GET', 'POST'])]
    public function generateBundle(AdminContext $context, Request $request, #[CurrentUser] ?UserInterface $user): Response
    {
        $destination = $context->getEntity()->getInstance();
        if (!$destination instanceof RestoreDestination) {
            return new Response('Invalid entity', 400);
        }

        $backupId = $request->query->get('backup');
        if (!is_numeric($backupId)) {
            return new Response('Missing or invalid ?backup= query parameter', 400);
        }

        $backup = $this->backupRepository->find((int) $backupId);
        if (!$backup instanceof Backup) {
            return new Response(\sprintf('Backup #%s not found', $backupId), 404);
        }

        $strategy = $this->strategyRegistry->forDestination($destination);
        $bundle = $strategy->renderBundle($destination, $backup);
        $bytes = $bundle->toTarGz();
        $sha256 = hash('sha256', $bytes);

        $audit = new RestoreBundleAudit()
            ->setDestination($destination)
            ->setBackup($backup)
            ->setSha256($sha256)
            ->setFilename($bundle->tarGzFilename())
            ->setSource(RestoreBundleAudit::SOURCE_ADMIN);
        if (\is_object($user) && method_exists($user, 'getId') && \is_int($user->getId())) {
            $audit->setUser($user instanceof \App\Entity\User ? $user : null);
        }
        $this->entityManager->persist($audit);
        $this->entityManager->flush();

        $response = new Response($bytes);
        $response->headers->set('Content-Type', 'application/gzip');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s"', $bundle->tarGzFilename()));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Bundle-Sha256', $sha256);

        return $response;
    }
}
