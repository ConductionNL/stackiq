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

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\SoftwareCatalog\EventListener\SoftwareCatalogEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use OCA\SoftwareCatalog\Service\PhpEmailService;

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
        // Log registration start
        error_log("SoftwareCatalog Application: Starting registration process");
        
        include_once __DIR__ . '/../../vendor/autoload.php';
        
        // Register the handlers as services
        $context->registerService('OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler', function (ContainerInterface $c) {
            error_log("SoftwareCatalog Application: Creating OrganizationHandler service");
            return new \OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler(
                $c->get(IGroupManager::class),
                $c->get(IUserManager::class),
                $c,
                $c->get(IAppManager::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $context->registerService('OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler', function (ContainerInterface $c) {
            error_log("SoftwareCatalog Application: Creating ContactPersonHandler service");
            return new \OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler(
                $c->get(IUserManager::class),
                $c->get(\OCP\Security\ISecureRandom::class),
                $c->get(IGroupManager::class),
                $c->get(IConfig::class),
                $c,
                $c->get(IAppManager::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(PhpEmailService::class)
            );
        });

        $context->registerService('OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler', function (ContainerInterface $c) {
            error_log("SoftwareCatalog Application: Creating GroupHandler service");
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
            error_log("SoftwareCatalog Application: Creating HierarchyHandler service");
            return new \OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler(
                $c->get('OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler'),
                $c->get('OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler'),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // Register event listeners for OpenRegister events
        error_log("SoftwareCatalog Application: Registering event listeners");
        
        $context->registerEventListener(ObjectCreatedEvent::class, SoftwareCatalogEventListener::class);
        error_log("SoftwareCatalog Application: Registered ObjectCreatedEvent listener");
        
        $context->registerEventListener(ObjectUpdatedEvent::class, SoftwareCatalogEventListener::class);
        error_log("SoftwareCatalog Application: Registered ObjectUpdatedEvent listener");
        
        $context->registerEventListener(ObjectDeletedEvent::class, SoftwareCatalogEventListener::class);
        error_log("SoftwareCatalog Application: Registered ObjectDeletedEvent listener");
        
        $context->registerEventListener(ObjectLockedEvent::class, SoftwareCatalogEventListener::class);
        error_log("SoftwareCatalog Application: Registered ObjectLockedEvent listener");
        
        $context->registerEventListener(ObjectUnlockedEvent::class, SoftwareCatalogEventListener::class);
        error_log("SoftwareCatalog Application: Registered ObjectUnlockedEvent listener");
        
        $context->registerEventListener(ObjectRevertedEvent::class, SoftwareCatalogEventListener::class);
        error_log("SoftwareCatalog Application: Registered ObjectRevertedEvent listener");
        
        // Organization event listeners removed - now using cron job for organization synchronization
        // Contact person event listeners are still active for real-time processing
        
                            // Register new focused services
                    $context->registerService(\OCA\SoftwareCatalog\Service\OrganisatieService::class, function ($container) {
                        return new \OCA\SoftwareCatalog\Service\OrganisatieService(
                            $container->get(\OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler::class),
                            $container->get('Psr\Log\LoggerInterface'),
                            $container,
                            $container->get('OCP\App\IAppManager'),
                            $container->get('OCP\IConfig')
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
                            $container->get('OCP\IConfig')
                        );
                    });

                    // Register organization sync service
                    $context->registerService(\OCA\SoftwareCatalog\Service\OrganizationSyncService::class, function ($container) {
                        return new \OCA\SoftwareCatalog\Service\OrganizationSyncService(
                            $container->get(\OCA\SoftwareCatalog\Service\OrganisatieService::class),
                            $container->get(\OCA\SoftwareCatalog\Service\ContactpersoonService::class),
                            $container->get('OCP\IConfig'),
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
        error_log("SoftwareCatalog Application: Registered OrganizationContactSyncJob");
        
        error_log("SoftwareCatalog Application: Registration process completed");
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
        error_log("SoftwareCatalog Application: Boot process started");
        
        // Register background job for organization contact synchronization
        $jobList = $context->getServerContainer()->get('OCP\BackgroundJob\IJobList');
        if (!$jobList->has(\OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob::class, null)) {
            $jobList->add(\OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob::class);
            error_log("SoftwareCatalog Application: Added OrganizationContactSyncJob to job list");
        } else {
            error_log("SoftwareCatalog Application: OrganizationContactSyncJob already registered");
        }
        
        // Application boot completed
        error_log("SoftwareCatalog Application: Boot process completed");
    }
}
