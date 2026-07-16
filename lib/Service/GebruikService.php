<?php
/**
 * Gebruik Service.
 *
 * Service for retrieving and managing Gebruik (usage) objects.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Service;

use Exception;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling gebruik-related operations
 */
class GebruikService
{
    /**
     * Constructor for GebruikService.
     *
     * @param SettingsService    $settingsService The settings service instance
     * @param IAppManager        $appManager      The application manager
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger instance
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Fetch relevant configuration for this service.
     *
     * @return array The resulting configuration parameters.
     *
     * @throws Exception When configuration cannot be retrieved.
     *
     * @spec openspec/specs/gebruik-services/spec.md
     */
    private function getGebruiksConfiguration(): array
    {
        // Try to get voorzieningen configuration from SettingsService.
        try {
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();

            $this->logger->debug(
                    'Retrieved voorzieningen configuration',
                    [
                        'config' => $voorzieningenConfig,
                    ]
                    );

            $registerId       = $voorzieningenConfig['register'] ?? null;
            $gebruikSchema    = $voorzieningenConfig['gebruik_schema'] ?? null;
            $applicatieSchema = $voorzieningenConfig['module_schema'] ?? null;

            // If configuration is available, use it.
            if (empty($registerId) === false && empty($gebruikSchema) === false) {
                return [
                    'registerId'       => $registerId ?? 'null',
                    'gebruikSchema'    => $gebruikSchema ?? 'null',
                    'applicatieSchema' => $applicatieSchema ?? 'null',
                ];
            }
        } catch (Exception $e) {
            $this->logger->warning(
                    'Failed to get voorzieningen configuration from SettingsService',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
        }//end try

        // No hardcoded fallback - configuration must be properly set.
        $this->logger->error(
                'Failed to get voorzieningen configuration - no fallback provided',
                [
                    'registerId'          => $registerId ?? 'null',
                    'gebruikSchema'       => $gebruikSchema ?? 'null',
                    'voorzieningenConfig' => $voorzieningenConfig ?? 'null',
                    'applicatieSchema'    => $applicatieSchema ?? 'null',
                ]
                );

        throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
    }//end getGebruiksConfiguration()

    /**
     * Get ObjectService from OpenRegister app.
     *
     * @return ObjectService The OpenRegister object service.
     *
     * @throws Exception When OpenRegister service is not available.
     */
    private function getObjectService(): ObjectService
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            throw new Exception('OpenRegister app is not installed');
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Exception $e) {
            throw new Exception('Failed to get OpenRegister service: '.$e->getMessage());
        }
    }//end getObjectService()

    /**
     * Fetch gebruiken for given options.
     *
     * @param array $options The options to use while searching.
     *
     * @return array The result set of gebruiken.
     *
     * @throws \OCP\DB\Exception When database query fails.
     *
     * @spec openspec/specs/gebruik-services/spec.md
     */
    public function getGebruiken(array $options): array
    {
        $objectService  = $this->getObjectService();
        $gebruiksConfig = $this->getGebruiksConfiguration();

        $options['@self'] = [
            'register' => $gebruiksConfig['registerId'],
            'schema'   => $gebruiksConfig['gebruikSchema'],
        ];

        // Normalize _extend parameter to array format.
        // Supports both 'extend' and '_extend' parameter names.
        $extend = $options['extend'] ?? $options['_extend'] ?? [];
        if (is_string($extend) === true) {
            $extend = array_map('trim', explode(',', $extend));
        } else if (is_array($extend) === false) {
            $extend = [$extend];
        }

        $options['_extend'] = $extend;
        unset($options['extend']);

        $searchResult = $objectService->searchObjectsPaginated(query: $options, _rbac: false, _multitenancy: false);

        $searchResult['results'] = array_map(
                function ($object) {
                    if (is_array($object) === false) {
                        $object = $object->getObject();
                    }

                    unset($object['interneAantekening']);

                    return $object;
                },
                $searchResult['results']
                );

        return $searchResult;
    }//end getGebruiken()

    /**
     * Get application ids for given options.
     *
     * @param array $options The options to use while searching.
     *
     * @return array The resulting ids.
     *
     * @throws \OCP\DB\Exception When database query fails.
     *
     * @spec openspec/specs/gebruik-services/spec.md
     */
    public function getApplicationIds(array $options): array
    {
        $objectService  = $this->getObjectService();
        $gebruiksConfig = $this->getGebruiksConfiguration();

        $options['@self'] = [
            'register' => $gebruiksConfig['registerId'],
            'schema'   => $gebruiksConfig['applicatieSchema'],
        ];

        $searchResult = $objectService->searchObjectsPaginated(query: $options, _rbac: false, _multitenancy: false);

        $searchResult = array_map(
                function ($object) {
                    // Handle both ObjectEntity and array results.
                    if (is_array($object) === false) {
                        // Use jsonSerialize to get full object with @self metadata.
                        if (method_exists($object, 'jsonSerialize') === true) {
                            $object = $object->jsonSerialize();
                        } else if (method_exists($object, 'getObject') === true) {
                            $object = $object->getObject();
                        }
                    }

                    return $object['@self']['id'] ?? $object['id'] ?? null;
                },
                $searchResult['results']
                );

        return $searchResult;
    }//end getApplicationIds()
}//end class
