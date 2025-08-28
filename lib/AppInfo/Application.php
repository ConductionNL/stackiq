<?php
/**
 * SoftwareCatalog Application
 *
 * This file contains the main application class for the SoftwareCatalog app.
 *
 * @category  Application
 * @package   OCA\SoftwareCatalog\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\AppInfo;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\SoftwareCatalog\EventListener\SoftwareCatalogEventListener;
use OCA\SoftwareCatalog\EventListener\TestEventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCP\User\Events\UserLoggedInEvent;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use OCA\SoftwareCatalog\Service\SettingsService;

/**
 * Main Application class for SoftwareCatalog
 *
 * @category Application
 * @package  OCA\SoftwareCatalog\AppInfo
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class Application extends App implements IBootstrap
{
    /**
     * The application ID
     */
    public const APP_ID = 'softwarecatalog';

    /**
     * Application constructor
     */
    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    /**
     * Register event listeners and services
     *
     * @param IRegistrationContext $context Registration context
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__ . '/../../vendor/autoload.php';

        // Register the handlers as services
        $context->registerService('OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler', function (ContainerInterface $c) {
            return new \OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler(
                $c->get(IGroupManager::class),
                $c->get(IUserManager::class),
                $c,
                $c->get(IAppManager::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $context->registerService('OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler', function (ContainerInterface $c) {
            return new \OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler(
                $c->get(IUserManager::class),
                $c->get(\OCP\Security\ISecureRandom::class),
                $c->get(IGroupManager::class),
                $c->get(IAppConfig::class),
                $c,
                $c->get(IAppManager::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(SymfonyEmailService::class),
                $c->get(IConfig::class)
            );
        });

        $context->registerService('OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler', function (ContainerInterface $c) {
            return new \OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler(
                $c->get(IGroupManager::class),
                $c->get(IUserManager::class),
                $c->get(IAppConfig::class),
                $c,
                $c->get(IAppManager::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $context->registerService('OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler', function (ContainerInterface $c) {
            return new \OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler(
                $c->get('OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler'),
                $c->get('OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler'),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });



        // Register TEST event listener for easily triggerable Nextcloud events
        $context->registerEventListener(UserLoggedInEvent::class, TestEventListener::class);
        
        // Register event listeners for OpenRegister events
        $context->registerEventListener(ObjectCreatedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectLockedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectUnlockedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectRevertedEvent::class, SoftwareCatalogEventListener::class);



        // Organization event listeners removed - now using cron job for organization synchronization
        // Contact person event listeners are still active for real-time processing

        // Register new focused services
        $context->registerService(\OCA\SoftwareCatalog\Service\OrganisatieService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\OrganisatieService(
                $container->get(\OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler::class),
                $container->get('Psr\Log\LoggerInterface'),
                $container,
                $container->get('OCP\App\IAppManager'),
                $container->get(IAppConfig::class),
                $container->get(IUserManager::class),
                $container->get(SymfonyEmailService::class),
            );
        });

        $context->registerService(\OCA\SoftwareCatalog\Service\ContactpersoonService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ContactpersoonService(
                $container->get(\OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler::class),
                $container->get(\OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler::class),
                $container->get(\OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler::class),
                $container->get('Psr\Log\LoggerInterface'),
                $container,
                $container->get('OCP\App\IAppManager'),
                $container->get(IAppConfig::class),
                $container->get(SettingsService::class)
            );
        });

        // Register email service
        $context->registerService(SymfonyEmailService::class, function ($container) {
            return new SymfonyEmailService(
                $container->get(IAppConfig::class),
                $container->get('Psr\Log\LoggerInterface'),
                $container->get(SettingsService::class)
            );
        });

        // Register settings service
        $context->registerService(SettingsService::class, function ($container) {
            return new SettingsService(
                $container->get(IAppConfig::class),
                $container->get('OCP\IRequest'),
                $container,
                $container->get('OCP\App\IAppManager'),
                $container->get('Psr\Log\LoggerInterface')
            );
        });

        // Register organization sync service
        $context->registerService(\OCA\SoftwareCatalog\Service\OrganizationSyncService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\OrganizationSyncService(
                $container->get(\OCA\SoftwareCatalog\Service\OrganisatieService::class),
                $container->get(\OCA\SoftwareCatalog\Service\ContactpersoonService::class),
                $container->get(SymfonyEmailService::class),
                $container->get(IAppConfig::class),
                $container->get('Psr\Log\LoggerInterface'),
                $container->get(SettingsService::class),
                $container->get(IDBConnection::class),
                $container->get(ContactPersonHandler::class),
            );
        });

        // Event listener uses direct service access like OpenCatalogi - no service registration needed

        // Register ArchiMate import service
        $context->registerService(\OCA\SoftwareCatalog\Service\ArchiMateImportService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ArchiMateImportService(
                $container->get(IAppConfig::class),
                $container->get('OCP\Files\IRootFolder'),
                $container->get('OCP\IUserSession'),
                $container->get('OCP\App\IAppManager'),
                $container,
                $container->get('Psr\Log\LoggerInterface')
            );
        });

        // Register ArchiMate export service
        $context->registerService(\OCA\SoftwareCatalog\Service\ArchiMateExportService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ArchiMateExportService(
                $container->get('Psr\Log\LoggerInterface')
            );
        });

        // Register ArchiMate import/export service
        $context->registerService(\OCA\SoftwareCatalog\Service\ArchiMateService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ArchiMateService(
                $container->get(IAppConfig::class),
                $container->get('OCP\Files\IRootFolder'),
                $container->get('OCP\IUserSession'),
                $container->get('OCP\App\IAppManager'),
                $container,
                $container->get('Psr\Log\LoggerInterface'),
                $container->get(\OCA\SoftwareCatalog\Service\ArchiMateImportService::class),
                $container->get(\OCA\SoftwareCatalog\Service\ArchiMateExportService::class)
            );
        });

        // Register progress tracking service
        $context->registerService(\OCA\SoftwareCatalog\Service\ProgressTracker::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ProgressTracker(
                $container->get('OCP\ISession'),
                $container->get('Psr\Log\LoggerInterface')
            );
        });

        // Register background job for organization contact synchronization
        $context->registerService(\OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob::class, function ($container) {
            return new \OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob(
                $container->get('OCP\AppFramework\Utility\ITimeFactory'),
                $container->get(\OCA\SoftwareCatalog\Service\OrganizationSyncService::class)
            );
        });
    }

    /**
     * Boot the application
     *
     * @param IBootContext $context Boot context
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        $container = $context->getServerContainer();
        $logger = $container->get(LoggerInterface::class);

        try {
            $config = $container->get(IAppConfig::class);
            $appManager = $container->get(IAppManager::class);
            $currentAppVersion = $appManager->getAppVersion(self::APP_ID);
            $lastInitializedVersion = $config->getValueString(self::APP_ID, 'last_initialized_version', '');

            $logger->info('SoftwareCatalog boot: Version check', [
                'currentVersion' => $currentAppVersion,
                'lastInitializedVersion' => $lastInitializedVersion,
                'versionChanged' => $lastInitializedVersion !== $currentAppVersion
            ]);

            // Check if we actually have a valid configuration, not just version matching
            $needsInitialization = false;
            $initReason = '';

            if ($lastInitializedVersion !== $currentAppVersion || empty($lastInitializedVersion)) {
                $needsInitialization = true;
                $initReason = empty($lastInitializedVersion) ? 'never_initialized' : 'version_changed';
            } else {
                // Even if version matches, check if we have valid configuration
                $hasValidConfig = $config->getValueString(self::APP_ID, 'voorzieningen_organisatie_schema', '') !== '' ||
                                  $config->getValueString(self::APP_ID, 'organization_schema', '') !== '';

                if (!$hasValidConfig) {
                    $needsInitialization = true;
                    $initReason = 'missing_configuration';
                    $logger->warning('SoftwareCatalog boot: No valid configuration found despite version match', [
                        'currentVersion' => $currentAppVersion,
                        'lastInitializedVersion' => $lastInitializedVersion
                    ]);
                }
            }

            if ($needsInitialization) {
                $logger->info('SoftwareCatalog boot: Starting initialization', [
                    'reason' => $initReason,
                    'currentVersion' => $currentAppVersion,
                    'lastInitializedVersion' => $lastInitializedVersion
                ]);

                try {
                    $settingsService = $container->get(SettingsService::class);
                    $initResult = $settingsService->initialize();

                    $logger->info('SoftwareCatalog boot: Initialization completed', [
                        'result' => $initResult,
                        'hasErrors' => !empty($initResult['errors'])
                    ]);

                    // Only update version if initialization was actually successful
                    if (empty($initResult['errors']) &&
                        ($initResult['autoConfigured'] || $initResult['fullyConfigured'])) {
                        $config->setValueString(self::APP_ID, 'last_initialized_version', $currentAppVersion);
                        $logger->info('SoftwareCatalog boot: Version updated to ' . $currentAppVersion . ' (successful init)');
                    } else {
                        $logger->warning('SoftwareCatalog boot: Initialization incomplete, not updating version', [
                            'errors' => $initResult['errors'] ?? [],
                            'autoConfigured' => $initResult['autoConfigured'] ?? false,
                            'fullyConfigured' => $initResult['fullyConfigured'] ?? false
                        ]);
                    }

                } catch (\RuntimeException $e) {
                    // Don't update version if OpenRegister is not available
                    $logger->warning('SoftwareCatalog boot: OpenRegister not available during initialization', [
                        'exception' => $e->getMessage(),
                        'initReason' => $initReason
                    ]);
                } catch (\Exception $e) {
                    $logger->error('SoftwareCatalog boot: Initialization failed', [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'initReason' => $initReason
                    ]);
                }
            } else {
                $logger->debug('SoftwareCatalog boot: Skipping initialization (version unchanged and config valid)');
            }

        } catch (\Exception $e) {
            // Log error but don't fail the boot process
            $logger->error('SoftwareCatalog boot error during version check: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
        }

        // Register background job for organization contact synchronization
        try {
            $jobList = $container->get('OCP\BackgroundJob\IJobList');
            if (!$jobList->has(\OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob::class, null)) {
                $jobList->add(\OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob::class);
                $logger->info('SoftwareCatalog boot: Background job registered');
            }
        } catch (\Exception $e) {
            $logger->error('SoftwareCatalog boot: Failed to register background job', [
                'exception' => $e->getMessage()
            ]);
        }

        // Check if initial sync has been done
        try {
            $config = $container->get(IAppConfig::class);
            $initialSyncDone = $config->getValueString(self::APP_ID, 'initial_sync_done', 'false');
            if ($initialSyncDone === 'false') {
                // Mark as done to prevent repeated attempts
                $config->setValueString(self::APP_ID, 'initial_sync_done', 'true');
                $logger->info('SoftwareCatalog boot: Initial sync flag set');
            }
        } catch (\Exception $e) {
            // Log but don't fail
            $logger->error('SoftwareCatalog boot error during sync check: ' . $e->getMessage(), [
                'exception' => $e->getMessage()
            ]);
        }


    }


}
