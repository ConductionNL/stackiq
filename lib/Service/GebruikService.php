<?php

namespace OCA\SoftwareCatalog\Service;

use Exception;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class GebruikService
{
    /**
     * @param SettingsService $settingsService
     * @param IAppManager $appManager
     * @param ContainerInterface $container
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Fetch relevant configuration for this service.
     *
     * @return array The resulting configuration parameters.
     * @throws Exception
     */
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
            $applicatieSchema = $voorzieningenConfig['module_schema'] ?? null;

            // If configuration is available, use it
            if ($registerId && $gebruikSchema) {
                return [
                    'registerId' => $registerId ?? 'null',
                    'gebruikSchema' => $gebruikSchema ?? 'null',
                    'applicatieSchema' => $applicatieSchema ?? 'null',
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

    /**
     * Fetch gebruiken for given options.
     *
     * @param array $options The options to use while searching.
     * @return array The result set of gebruiken.
     * @throws \OCP\DB\Exception
     */
    public function getGebruiken(array $options): array
    {
        $objectService = $this->getObjectService();
        $gebruiksConfig = $this->getGebruiksConfiguration();

        $options['@self'] = [
            'register' => $gebruiksConfig['registerId'],
            'schema' => $gebruiksConfig['gebruikSchema'],
        ];

        $searchResult = $objectService->searchObjectsPaginated(query: $options, _rbac: false, _multitenancy: false);

        $searchResult['results'] = array_map(function($object) {
            if (is_array($object) === false) {
                $object = $object->getObject();
            }

            unset($object['interneAantekening']);

            return $object;
        }, $searchResult['results']);

        return $searchResult;
    }

    /**
     * Get application ids for given options
     *
     * @param array $options The options to use while searching.
     * @return array The resulting ids
     * @throws \OCP\DB\Exception
     */
    public function getApplicationIds(array $options): array
    {
        $objectService = $this->getObjectService();
        $gebruiksConfig = $this->getGebruiksConfiguration();

        $options['@self'] = [
            'register' => $gebruiksConfig['registerId'],
            'schema' => $gebruiksConfig['applicatieSchema'],
        ];

        $searchResult = $objectService->searchObjectsPaginated(query: $options, _rbac: false, _multitenancy: false);

        $searchResult = array_map(function($object) {
            if (is_array($object) === false) {
                $object = $object->getObject();
            }
            return $object['@self']['id'];
        }, $searchResult['results']);

        return $searchResult;
    }
}
