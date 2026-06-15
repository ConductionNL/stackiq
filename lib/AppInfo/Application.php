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
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\AppInfo;

use OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob;
use OCA\SoftwareCatalog\BackgroundJob\ContractStatusJob;
use OCA\SoftwareCatalog\Service\ContractStatusService;
use OCA\SoftwareCatalog\BackgroundJob\FederationSyncJob;
use OCA\SoftwareCatalog\Service\Federation\FederationConfig;
use OCA\SoftwareCatalog\Service\Federation\FederationService;
use OCA\SoftwareCatalog\Controller\ContactpersonenController;
use OCA\SoftwareCatalog\Dashboard\ConceptOrganisatiesWidget;
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
use OCA\SoftwareCatalog\Service\SoftwareCatalogContactSyncService;
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
use OCP\AppFramework\Services\IInitialState;
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
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
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
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        $this->registerHandlerServices($context);

        // Wire up event-listener bindings — extracted to a single
        // single-responsibility helper per
        // `openspec/changes/method-decomposition/tasks.md` task 9.1.
        $this->registerEventListeners($context);

        $this->registerDomainServices($context);
    }//end register()


    /**
     * Wire the four SoftwareCatalogue handler services as DI bindings.
     *
     * Single-responsibility helper extracted from `register()` per
     * `openspec/changes/method-decomposition/tasks.md` task 9.1.
     *
     * @param IRegistrationContext $context Registration context
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
     */
    private function registerHandlerServices(IRegistrationContext $context): void
    {
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
                    _logger: $c->get(LoggerInterface::class),
                    _userManager: $c->get(IUserManager::class),
                    _groupManager: $c->get(IGroupManager::class)
                    );
                }
                );

    }//end registerHandlerServices()


    /**
     * Wire all domain-level services (sync/email/settings/ArchiMate/etc.).
     *
     * Single-responsibility helper extracted from `register()` per
     * `openspec/changes/method-decomposition/tasks.md` task 9.1.
     *
     * @param IRegistrationContext $context Registration context
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
     */
    private function registerDomainServices(IRegistrationContext $context): void
    {
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
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    groupManager: $container->get(IGroupManager::class)
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
                    container: $container,
                    );
                }
                );

        // Register the Nextcloud-Contacts bridge (identity → NC addressbook,
        // relationship records keyed by contactsUid; ADR-019/ADR-022).
        $context->registerService(
                SoftwareCatalogContactSyncService::class,
                function ($container) {
                    return new SoftwareCatalogContactSyncService(
                    contactsManager: $container->get('OCP\Contacts\IManager'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register gebruik sync service.
        $context->registerService(
                GebruikSyncService::class,
                function ($container) {
                    return new GebruikSyncService(
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: $container->get(SettingsService::class),
                    container: $container
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
                    organisationService: $container->get(OpenRegisterOrganisationService::class),
                    dbConnection: $container->get(IDBConnection::class)
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
                    timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
                    orgSyncService: $container->get(OrganizationSyncService::class),
                    contactSync: $container->get(SoftwareCatalogContactSyncService::class),
                    settingsService: $container->get(SettingsService::class),
                    appManager: $container->get(IAppManager::class),
                    logger: $container->get(LoggerInterface::class)
                    );
                }
                );

        // Register the contract-status maintenance service + its daily job.
        $context->registerService(
                ContractStatusService::class,
                function ($container) {
                    return new ContractStatusService(
                    container: $container,
                    settingsService: $container->get(SettingsService::class),
                    logger: $container->get(LoggerInterface::class)
                    );
                }
                );
        $context->registerService(
                ContractStatusJob::class,
                function ($container) {
                    return new ContractStatusJob(
                    timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
                    statusService: $container->get(ContractStatusService::class),
                    appManager: $container->get(IAppManager::class),
                    logger: $container->get(LoggerInterface::class)
                    );
                }
                );

        // Register the federation config + service + scheduled job.
        $context->registerService(
                FederationConfig::class,
                function ($container) {
                    return new FederationConfig(appConfig: $container->get(IAppConfig::class));
                }
                );
        $context->registerService(
                FederationService::class,
                function ($container) {
                    return new FederationService(
                    container: $container,
                    appManager: $container->get(IAppManager::class),
                    config: $container->get(FederationConfig::class),
                    logger: $container->get(LoggerInterface::class)
                    );
                }
                );
        $context->registerService(
                FederationSyncJob::class,
                function ($container) {
                    return new FederationSyncJob(
                    timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
                    federation: $container->get(FederationService::class),
                    config: $container->get(FederationConfig::class),
                    logger: $container->get(LoggerInterface::class)
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
                    contactSvc: $container->get(ContactpersoonService::class),
                    userManager: $container->get('OCP\IUserManager'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    userSession: $container->get('OCP\IUserSession'),
                    container: $container,
                    secureRandom: $container->get('OCP\Security\ISecureRandom'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Dashboard widgets — see lib/Dashboard/*.php and src/*Widget.js.
        $context->registerDashboardWidget(ConceptOrganisatiesWidget::class);
    }//end registerDomainServices()

    /**
     * Wire all OpenRegister event listeners.
     *
     * Single-responsibility helper extracted from `register()` per
     * `openspec/changes/method-decomposition/tasks.md` task 9.1. Keeps
     * `register()` focused on service-binding wiring while this method
     * owns the listener catalogue.
     *
     * @param IRegistrationContext $context Registration context.
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
     */
    private function registerEventListeners(IRegistrationContext $context): void
    {
        // TEST event listener for easily triggerable Nextcloud events.
        $context->registerEventListener(UserLoggedInEvent::class, TestEventListener::class);

        // OpenRegister object lifecycle events — broadcast to the
        // SoftwareCatalog cross-cutting listener.
        $context->registerEventListener(ObjectCreatedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectLockedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectUnlockedEvent::class, SoftwareCatalogEventListener::class);
        $context->registerEventListener(ObjectRevertedEvent::class, SoftwareCatalogEventListener::class);

        // Module-compliance subscriber — runs on create/update only.
        $context->registerEventListener(ObjectCreatedEvent::class, ModuleComplianceSubscriber::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ModuleComplianceSubscriber::class);

        // Module registration — sets geregistreerdDoor on each save.
        $context->registerEventListener(ObjectCreatedEvent::class, ModuleRegistrationSubscriber::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ModuleRegistrationSubscriber::class);

        // Sync user profile updates into the contactpersoon mirror.
        $context->registerEventListener(UserProfileUpdatedEvent::class, UserProfileUpdatedEventListener::class);

    }//end registerEventListeners()

    /**
     * Boot the application
     *
     * @param IBootContext $context Boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
     */
    public function boot(IBootContext $context): void
    {
        // Background jobs are registered declaratively in appinfo/info.xml.
        // Initialization is handled by the Repair step (InitializeSettings).
        // Provision IAppConfig keys referenced as `@resolve:<key>` sentinels in
        // src/manifest.json so @conduction/nextcloud-vue's resolver chain can
        // pick them up synchronously via @nextcloud/initial-state (Step 1)
        // without falling through to the deferred backend fetch (Step 2).
        // See node_modules/@conduction/nextcloud-vue/src/utils/resolveManifestSentinels.js
        // — readInitialState() calls loadState(appId, key, undefined).
        $container    = $this->getContainer();
        $initialState = $container->get(IInitialState::class);
        $appConfig    = $container->get(IAppConfig::class);

        // Keys must match every distinct `@resolve:<key>` sentinel in
        // src/manifest.json. Discover with:
        // grep -oE '@resolve:[a-z_]+' src/manifest.json | sort -u.
        //
        // The canonical home for the voorzieningen register id is the
        // `voorzieningen_config` JSON blob (`{"register":"11", ...}`) written
        // by the settings UI / auto-configure flow. The flat scalar key
        // `voorzieningen_register` is a legacy fallback that is usually unset.
        // We must provision the resolved numeric id from whichever source has
        // it, otherwise the manifest sentinel resolves to null and every index
        // page fires `GET .../objects/@resolve:voorzieningen_register/<schema>`
        // (404). See node_modules/@conduction/nextcloud-vue/src/utils/resolveManifestSentinels.js.
        $registerId  = $this->resolveVoorzieningenRegisterId(appConfig: $appConfig);
        $provisioned = null;
        if ($registerId !== '') {
            $provisioned = $registerId;
        }

        $initialState->provideInitialState('voorzieningen_register', $provisioned);
    }//end boot()

    /**
     * Resolve the numeric voorzieningen register id from the canonical config.
     *
     * Resolution order:
     *  1. `voorzieningen_config` JSON blob's `register` field — the canonical
     *     home written by the settings UI / auto-configure flow.
     *  2. `voorzieningen` JSON blob's `configured.register` field — the legacy
     *     auto-configure result envelope.
     *  3. The flat `voorzieningen_register` scalar key — legacy fallback.
     *
     * @param IAppConfig $appConfig The app config service.
     *
     * @return string The numeric register id, or '' when none is configured.
     */
    private function resolveVoorzieningenRegisterId(IAppConfig $appConfig): string
    {
        $configJson = $appConfig->getValueString(self::APP_ID, 'voorzieningen_config', '');
        if ($configJson !== '') {
            $decoded = json_decode($configJson, true);
            if (is_array($decoded) === true
                && isset($decoded['register']) === true
                && $decoded['register'] !== ''
            ) {
                return (string) $decoded['register'];
            }
        }

        $envelopeJson = $appConfig->getValueString(self::APP_ID, 'voorzieningen', '');
        if ($envelopeJson !== '') {
            $decoded = json_decode($envelopeJson, true);
            if (is_array($decoded) === true
                && isset($decoded['configured']['register']) === true
                && $decoded['configured']['register'] !== ''
            ) {
                return (string) $decoded['configured']['register'];
            }
        }

        return $appConfig->getValueString(self::APP_ID, 'voorzieningen_register', '');
    }//end resolveVoorzieningenRegisterId()
}//end class
