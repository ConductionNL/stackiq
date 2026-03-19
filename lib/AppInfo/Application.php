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
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\AppInfo;

use OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob;
use OCA\SoftwareCatalog\Controller\ContactpersonenController;
use OCA\SoftwareCatalog\EventListener\SoftwareCatalogEventListener;
use OCA\SoftwareCatalog\EventListener\TestEventListener;
use OCA\SoftwareCatalog\EventListener\ModuleComplianceSubscriber;
use OCA\SoftwareCatalog\EventListener\ModuleRegistrationSubscriber;
use OCA\SoftwareCatalog\EventListener\UserProfileUpdatedEventListener;
use OCA\SoftwareCatalog\Service\ArchiMateExportService;
use OCA\SoftwareCatalog\Service\ArchiMateImportService;
use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\GebruikSyncService;
use OCA\SoftwareCatalog\Service\ModuleComplianceService;
use OCA\SoftwareCatalog\Service\ModuleRegistrationService;
use OCA\SoftwareCatalog\Service\ModuleVersionService;
use OCA\SoftwareCatalog\Service\OrganisatieService;
use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use OCA\SoftwareCatalog\Service\ProgressTracker;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use OCA\SoftwareCatalog\Service\ViewService;
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
use OCA\OpenRegister\Service\OrganisationService as OpenRegisterOrganisationService;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Main Application class for SoftwareCatalog
 *
 * @category Application
 * @package  OCA\SoftwareCatalog\AppInfo
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services
     *
     * @param IRegistrationContext $context Registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register the handlers as services.
        $context->registerService(
                'OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler',
                function (ContainerInterface $c) {
                    return new OrganizationHandler(
                    _groupManager: $c->get(IGroupManager::class),
                    _userManager: $c->get(IUserManager::class),
                    _container: $c,
                    _appManager: $c->get(IAppManager::class),
                    _logger: $c->get(LoggerInterface::class)
                    );
                }
                );

        $context->registerService(
                'OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler',
                function (ContainerInterface $c) {
                    return new ContactPersonHandler(
                    _userManager: $c->get(IUserManager::class),
                    _secureRandom: $c->get(ISecureRandom::class),
                    _groupManager: $c->get(IGroupManager::class),
                    _config: $c->get(IAppConfig::class),
                    _container: $c,
                    _appManager: $c->get(IAppManager::class),
                    _logger: $c->get(LoggerInterface::class),
                    _emailService: $c->get(SymfonyEmailService::class),
                    config: $c->get(IConfig::class)
                    );
                }
                );

        $context->registerService(
                'OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler',
                function (ContainerInterface $c) {
                    return new GroupHandler(
                    _groupManager: $c->get(IGroupManager::class),
                    _userManager: $c->get(IUserManager::class),
                    _appConfig: $c->get(IAppConfig::class),
                    _container: $c,
                    _appManager: $c->get(IAppManager::class),
                    _logger: $c->get(LoggerInterface::class)
                    );
                }
                );

        $context->registerService(
                'OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler',
                function (ContainerInterface $c) {
                    return new HierarchyHandler(
                    _organizationHandler: $c->get('OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler'),
                    _contactPersonHandler: $c->get('OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler'),
                    _logger: $c->get(LoggerInterface::class)
                    );
                }
                );

        // Register TEST event listener for easily triggerable Nextcloud events.
        $context->registerEventListener(UserLoggedInEvent::class, TestEventListener::class);

        // Register event listeners for OpenRegister events.
        $context->registerEventListener(ObjectCreatedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectLockedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectUnlockedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectRevertedEvent::class, SoftwareCatalogEventListener::class);

        // Register module compliance subscriber for module updates.
        $context->registerEventListener(ObjectCreatedEvent::class, ModuleComplianceSubscriber::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ModuleComplianceSubscriber::class);

        // Register module registration subscriber for auto-setting geregistreerdDoor.
        $context->registerEventListener(ObjectCreatedEvent::class, ModuleRegistrationSubscriber::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ModuleRegistrationSubscriber::class);

        // Register listener to sync user profile updates to contactpersoon objects.
        $context->registerEventListener(UserProfileUpdatedEvent::class, UserProfileUpdatedEventListener::class);

        // Organization event listeners removed - now using cron job for organization synchronization.
        // Contact person event listeners are still active for real-time processing.
        // Register new focused services.
        $context->registerService(
                OrganisatieService::class,
                function ($container) {
                    return new OrganisatieService(
                    organizationHandler: $container->get(
                        OrganizationHandler::class
                    ),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    container: $container,
                    appManager: $container->get('OCP\App\IAppManager'),
                    config: $container->get(IAppConfig::class),
                    userManager: $container->get(IUserManager::class),
                    emailService: $container->get(SymfonyEmailService::class),
                    );
                }
                );

        $context->registerService(
                ContactpersoonService::class,
                function ($container) {
                    return new ContactpersoonService(
                    contactPersonHandler: $container->get(
                        ContactPersonHandler::class
                    ),
                    groupHandler: $container->get(
                        GroupHandler::class
                    ),
                    hierarchyHandler: $container->get(
                        HierarchyHandler::class
                    ),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    container: $container,
                    appManager: $container->get('OCP\App\IAppManager'),
                    config: $container->get(IAppConfig::class),
                    settingsService: $container->get(SettingsService::class)
                    );
                }
                );

        // Register email service.
        $context->registerService(
                SymfonyEmailService::class,
                function ($container) {
                    return new SymfonyEmailService(
                    config: $container->get(IAppConfig::class),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: $container->get(SettingsService::class)
                    );
                }
                );

        // Register settings service.
        $context->registerService(
                SettingsService::class,
                function ($container) {
                    return new SettingsService(
                    config: $container->get(IAppConfig::class),
                    request: $container->get('OCP\IRequest'),
                    container: $container,
                    appManager: $container->get('OCP\App\IAppManager'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register organization sync service.
        $context->registerService(
                OrganizationSyncService::class,
                function ($container) {
                    return new OrganizationSyncService(
                    organisatieService: $container->get(OrganisatieService::class),
                    contactpersoonService: $container->get(ContactpersoonService::class),
                    emailService: $container->get(SymfonyEmailService::class),
                    config: $container->get(IAppConfig::class),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: $container->get(SettingsService::class),
                    db: $container->get(IDBConnection::class),
                    contactpersonHandler: $container->get(ContactPersonHandler::class),
                    );
                }
                );

        // Register gebruik sync service.
        $context->registerService(
                GebruikSyncService::class,
                function ($container) {
                    return new GebruikSyncService(
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: $container->get(SettingsService::class)
                    );
                }
                );

        // Event listener uses direct service access like OpenCatalogi - no service registration needed.
        // Register module compliance service.
        $context->registerService(
                ModuleComplianceService::class,
                function ($container) {
                    return new ModuleComplianceService(
                    container: $container,
                    settingsService: $container->get(SettingsService::class),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register module registration service (auto-sets geregistreerdDoor).
        $context->registerService(
                ModuleRegistrationService::class,
                function ($container) {
                    return new ModuleRegistrationService(
                    container: $container,
                    settingsService: $container->get(SettingsService::class),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register module version service (creates default 1.0.0 version for new modules).
        $context->registerService(
                ModuleVersionService::class,
                function ($container) {
                    return new ModuleVersionService(
                    container: $container,
                    settingsService: $container->get(SettingsService::class),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register ArchiMate import service.
        $context->registerService(
                ArchiMateImportService::class,
                function ($container) {
                    return new ArchiMateImportService(
                    config: $container->get(IAppConfig::class),
                    rootFolder: $container->get('OCP\Files\IRootFolder'),
                    userSession: $container->get('OCP\IUserSession'),
                    appManager: $container->get('OCP\App\IAppManager'),
                    container: $container,
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: $container->get(SettingsService::class),
                    organisationService: $container->get(OpenRegisterOrganisationService::class)
                    );
                }
                );

        // Register ArchiMate export service.
        $context->registerService(
                ArchiMateExportService::class,
                function ($container) {
                    return new ArchiMateExportService(
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register ArchiMate import/export service.
        $context->registerService(
                ArchiMateService::class,
                function ($container) {
                    return new ArchiMateService(
                    config: $container->get(IAppConfig::class),
                    rootFolder: $container->get('OCP\Files\IRootFolder'),
                    userSession: $container->get('OCP\IUserSession'),
                    appManager: $container->get('OCP\App\IAppManager'),
                    container: $container,
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: $container->get(SettingsService::class),
                    importService: $container->get(ArchiMateImportService::class),
                    exportService: $container->get(ArchiMateExportService::class)
                    );
                }
                );

        // Register View service for ArchiMate views with enrichment capabilities.
        $context->registerService(
                ViewService::class,
                function ($container) {
                    return new ViewService(
                    config: $container->get(IAppConfig::class),
                    appManager: $container->get('OCP\App\IAppManager'),
                    container: $container,
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: $container->get(SettingsService::class),
                    userSession: $container->get('OCP\IUserSession'),
                    cacheFactory: $container->get(ICacheFactory::class)
                    );
                }
                );

        // Register progress tracking service.
        $context->registerService(
                ProgressTracker::class,
                function ($container) {
                    return new ProgressTracker(
                    session: $container->get('OCP\ISession'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register background job for organization contact synchronization.
        $context->registerService(
                OrganizationContactSyncJob::class,
                function ($container) {
                    return new OrganizationContactSyncJob(
                    time: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
                    syncService: $container->get(OrganizationSyncService::class),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register ContactpersonenController with explicit dependencies for /me endpoint.
        $context->registerService(
                ContactpersonenController::class,
                function ($container) {
                    return new ContactpersonenController(
                    appName: self::APP_ID,
                    request: $container->get('OCP\IRequest'),
                    settingsService: $container->get(SettingsService::class),
                    contactPersonHandler: $container->get(
                        'OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler'
                    ),
                    contactpersoonService: $container->get(ContactpersoonService::class),
                    userManager: $container->get('OCP\IUserManager'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    userSession: $container->get('OCP\IUserSession'),
                    container: $container,
                    secureRandom: $container->get('OCP\Security\ISecureRandom'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );
    }//end register()

    /**
     * Boot the application
     *
     * @param IBootContext $context Boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
        // Background jobs are registered declaratively in appinfo/info.xml.
        // Initialization is handled by the Repair step (InitializeSettings).
        // No per-request work needed here.
    }//end boot()
}//end class
