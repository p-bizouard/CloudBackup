<?php

namespace App\Controller\Api;

use App\ApiModel\InventoryEntry;
use App\Entity\ApiClient;
use App\Repository\BackupConfigurationRepository;
use App\Service\Inventory\InventoryBuilder;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security as ApiDocSecurity;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Yaml\Yaml;

#[OA\Tag(name: 'Inventory')]
final class InventoryApiController extends AbstractController
{
    public function __construct(
        private readonly BackupConfigurationRepository $backupConfigurationRepository,
        private readonly InventoryBuilder $inventoryBuilder,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('/api/inventory.json', name: 'api_inventory_json', methods: ['GET'])]
    #[IsGranted(ApiClient::ROLE)]
    #[ApiDocSecurity(name: 'basicAuth')]
    #[OA\Response(
        response: 200,
        description: 'Inventory of enabled backup configurations with latest backup metadata.',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: InventoryEntry::class)),
        ),
    )]
    #[OA\Response(response: 401, description: 'Missing or invalid API client credentials.')]
    public function inventoryJson(): JsonResponse
    {
        return new JsonResponse($this->normalize(), Response::HTTP_OK, [], true);
    }

    #[Route('/api/inventory.yaml', name: 'api_inventory_yaml', methods: ['GET'])]
    #[IsGranted(ApiClient::ROLE)]
    #[ApiDocSecurity(name: 'basicAuth')]
    #[OA\Response(
        response: 200,
        description: 'Inventory of enabled backup configurations with latest backup metadata (YAML).',
        content: new OA\MediaType(
            mediaType: 'application/yaml',
            schema: new OA\Schema(type: 'string'),
        ),
    )]
    #[OA\Response(response: 401, description: 'Missing or invalid API client credentials.')]
    public function inventoryYaml(): Response
    {
        $yaml = Yaml::dump($this->normalize(asJson: false), 6, 2);

        return new Response($yaml, Response::HTTP_OK, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
        ]);
    }

    /**
     * @return string|array<int, array<string, mixed>>
     */
    private function normalize(bool $asJson = true): string|array
    {
        $entries = $this->inventoryBuilder->build(
            $this->backupConfigurationRepository->findEnabledWithLatestBackupOnly(),
        );

        /** @var array<int, array<string, mixed>> $normalized */
        $normalized = $this->normalizer->normalize($entries);

        if ($asJson) {
            return json_encode($normalized, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }

        return $normalized;
    }
}
