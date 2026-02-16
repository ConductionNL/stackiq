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
use OCA\SoftwareCatalog\EventListener\ModuleComplianceSubscriber;
use OCA\SoftwareCatalog\EventListener\UserProfileUpdatedEventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
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
use OCA\SoftwareCatalog\Service\GebruikSyncService;

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

        // Register module compliance subscriber for module updates
        $context->registerEventListener(ObjectCreatedEvent::class, ModuleComplianceSubscriber::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ModuleComplianceSubscriber::class);

        // Register listener to sync user profile updates to contactpersoon objects
        $context->registerEventListener(UserProfileUpdatedEvent::class, UserProfileUpdatedEventListener::class);



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

        // Register gebruik sync service
        $context->registerService(\OCA\SoftwareCatalog\Service\GebruikSyncService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\GebruikSyncService(
                $container->get('Psr\Log\LoggerInterface'),
                $container->get(SettingsService::class)
            );
        });

        // Event listener uses direct service access like OpenCatalogi - no service registration needed

        // Register module compliance service
        $context->registerService(\OCA\SoftwareCatalog\Service\ModuleComplianceService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ModuleComplianceService(
                $container,
                $container->get(SettingsService::class),
                $container->get('Psr\Log\LoggerInterface')
            );
        });

        // Register ArchiMate import service
        $context->registerService(\OCA\SoftwareCatalog\Service\ArchiMateImportService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ArchiMateImportService(
                $container->get(IAppConfig::class),
                $container->get('OCP\Files\IRootFolder'),
                $container->get('OCP\IUserSession'),
                $container->get('OCP\App\IAppManager'),
                $container,
                $container->get('Psr\Log\LoggerInterface'),
                $container->get(SettingsService::class),
                $container->get(\OCA\OpenRegister\Service\OrganisationService::class)
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
                $container->get(SettingsService::class),
                $container->get(\OCA\SoftwareCatalog\Service\ArchiMateImportService::class),
                $container->get(\OCA\SoftwareCatalog\Service\ArchiMateExportService::class)
            );
        });

        // Register View service for ArchiMate views with enrichment capabilities
        $context->registerService(\OCA\SoftwareCatalog\Service\ViewService::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ViewService(
                $container->get(IAppConfig::class),
                $container->get('OCP\App\IAppManager'),
                $container,
                $container->get('Psr\Log\LoggerInterface'),
                $container->get(SettingsService::class),
                $container->get('OCP\IUserSession')
            );
        });

        // Register progress tracking service
        $context->registerService(\OCA\SoftwareCatalog\Service\ProgressTracker::class, function ($container) {
            return new \OCA\SoftwareCatalog\Service\ProgressTracker(
                $container->get('OCP\ISession'),
                $container->get('Psr\Log\LoggerInterface')
            );
        });

        // Register background job for organization contact synchronization.
        $context->registerService(\OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob::class, function ($container) {
            return new \OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob(
                $container->get('OCP\AppFramework\Utility\ITimeFactory'),
                $container->get(\OCA\SoftwareCatalog\Service\OrganizationSyncService::class),
                $container->get('Psr\Log\LoggerInterface')
            );
        });

        // Register ContactpersonenController with explicit dependencies for /me endpoint
        $context->registerService(\OCA\SoftwareCatalog\Controller\ContactpersonenController::class, function ($container) {
            return new \OCA\SoftwareCatalog\Controller\ContactpersonenController(
                self::APP_ID,
                $container->get('OCP\IRequest'),
                $container->get(SettingsService::class),
                $container->get('OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler'),
                $container->get(\OCA\SoftwareCatalog\Service\ContactpersoonService::class),
                $container->get('OCP\IUserManager'),
                $container->get('OCP\IGroupManager'),
                $container->get('OCP\IUserSession'),
                $container,
                $container->get('OCP\Security\ISecureRandom'),
                $container->get('Psr\Log\LoggerInterface')
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
        // Initialization is now handled by the Repair step (InitializeSettings)
        // which runs only during app install/upgrade, not on every request.
        // See lib/Repair/InitializeSettings.php

        $container = $context->getServerContainer();
        $logger = $container->get(LoggerInterface::class);

        // Register background job for organization contact synchronization
        try {
            $jobList = $container->get('OCP\BackgroundJob\IJobList');
            if (!$jobList->has(\OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob::class, null)) {
                $jobList->add(\OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob::class);
                $logger->debug('SoftwareCatalog boot: Background job registered');
            }
        } catch (\Exception $e) {
            $logger->error('SoftwareCatalog boot: Failed to register background job', [
                'exception' => $e->getMessage()
            ]);
        }
    }


}
