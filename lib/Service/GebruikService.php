<?php

namespace OCA\SoftwareCatalog\Service;

use Exception;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class GebruikService
{
    private function __construct(
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {}

    private function getGebruiksConfiguration(): array
    {
        // Try to get voorzieningen configuration from SettingsService
        try {
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();

            $this->logger->debug('Retrieved voorzieningen configuration', [
                'config' => $voorzieningenConfig
            ]);

            $registerId = $voorzieningenConfig['register'] ?? null;
            $gebruikSchema = $voorzieningenConfig['gebruik_schema'] ?? null;
            $applicatieSchema = $voorzieningenConfig['applicatie_schema'] ?? null;

            // If configuration is available, use it
            if ($registerId && $gebruikSchema) {
                return [
                    'register_id' => $registerId,
                    'schemas' => [$gebruikSchema]
                ];
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to get voorzieningen configuration from SettingsService', [
                'error' => $e->getMessage()
            ]);
        }

        // No hardcoded fallback - configuration must be properly set
        $this->logger->error('Failed to get voorzieningen configuration - no fallback provided', [
            'registerId' => $registerId ?? 'null',
            'gebruikSchema' => $gebruikSchema ?? 'null',
            'voorzieningenConfig' => $voorzieningenConfig ?? 'null',
            'applicatieSchema' => $applicatieSchema ?? 'null',
        ]);

        throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
    }

    /**
     * Get ObjectService from OpenRegister app
     *
     * @return ObjectService The OpenRegister object service
     * @throws Exception When OpenRegister service is not available
     */
    private function getObjectService(): ObjectService
    {
        if (!in_array('openregister', $this->appManager->getInstalledApps())) {
            throw new Exception('OpenRegister app is not installed');
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Exception $e) {
            throw new Exception('Failed to get OpenRegister service: ' . $e->getMessage());
        }
    }
    public function getGebruiken(array $options): array
    {
        $objectService = $this->getObjectService();
        $gebruiksConfig = $this->getGebruiksConfiguration();

        $options['@self'] = [
            'register' => $gebruiksConfig['register_id'],
            'schema' => $gebruiksConfig['gebruikSchema'],
        ];

        $searchResult = $objectService->searchObjectsPaginated(query: $options, rbac: false, multi: false);

        $searchResult['results'] = array_map(function($object) {
            if (is_array($object) === false) {
                $object = $object->getObject();
            }

            unset($object['interneAantekening']);

            return $object;
        }, $searchResult['results']);

        return $searchResult;
    }

    public function getApplicationIds(array $options): array
    {
        $objectService = $this->getObjectService();
        $gebruiksConfig = $this->getGebruiksConfiguration();

        $options['@self'] = [
            'register' => $gebruiksConfig['register_id'],
            'schema' => $gebruiksConfig['applicatieSchema'],
        ];

        $searchResult = $objectService->searchObjectsPaginated(query: $options, rbac: false, multi: false);

        $searchResult = array_map(function($object) {
            if (is_array($object) === false) {
                $object = $object->getObject();
            }
            return $object['id'];
        }, $searchResult['results']);

        return $searchResult;
    }
}
