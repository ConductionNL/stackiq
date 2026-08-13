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
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\AppInfo;

use OCA\Decidesk\Event\DecisionConcludedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\OpenRegister\Service\OrganisationService as OpenRegisterOrganisationService;
use OCA\SoftwareCatalog\BackgroundJob\ContractStatusJob;
use OCA\SoftwareCatalog\BackgroundJob\EolSyncJob;
use OCA\SoftwareCatalog\BackgroundJob\FederationSyncJob;
use OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob;
use OCA\SoftwareCatalog\Controller\ContactpersonenController;
use OCA\SoftwareCatalog\Dashboard\ConceptOrganisatiesWidget;
use OCA\SoftwareCatalog\EventListener\DecisionConcludedListener;
use OCA\SoftwareCatalog\EventListener\ModuleComplianceSubscriber;
use OCA\SoftwareCatalog\EventListener\ModuleRegistrationSubscriber;
use OCA\SoftwareCatalog\EventListener\TestEventListener;
use OCA\SoftwareCatalog\EventListener\UserProfileUpdatedEventListener;
use OCA\SoftwareCatalog\Service\ArchiMateExportService;
use OCA\SoftwareCatalog\Service\ArchiMateImportService;
use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\ContractApprovalService;
use OCA\SoftwareCatalog\Service\ContractStatusService;
use OCA\SoftwareCatalog\Service\EolMatcherService;
use OCA\SoftwareCatalog\Service\EolSyncService;
use OCA\SoftwareCatalog\Service\FacetService;
use OCA\SoftwareCatalog\Service\Federation\FederationConfig;
use OCA\SoftwareCatalog\Service\Federation\FederationMerger;
use OCA\SoftwareCatalog\Service\Federation\FederationService;
use OCA\SoftwareCatalog\Service\GebruikSyncService;
use OCA\SoftwareCatalog\Service\IntakeService;
use OCA\SoftwareCatalog\Service\MergeOrganisatieService;
use OCA\SoftwareCatalog\Service\ModerationService;
use OCA\SoftwareCatalog\Service\ModuleComplianceService;
use OCA\SoftwareCatalog\Service\ModuleRegistrationService;
use OCA\SoftwareCatalog\Service\ModuleVersionService;
use OCA\SoftwareCatalog\Service\OrganisatieService;
use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use OCA\SoftwareCatalog\Service\ProgressTracker;
use OCA\SoftwareCatalog\Service\PublicationService;
use OCA\SoftwareCatalog\Service\ReviewAggregateService;
use OCA\SoftwareCatalog\Service\ReviewService;
use OCA\SoftwareCatalog\Service\SbomImportService;
use OCA\SoftwareCatalog\Service\SbomParserService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogContactSyncService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use OCA\SoftwareCatalog\Service\ViewQueryBuilder;
use OCA\SoftwareCatalog\Service\ViewService;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
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
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class Application extends App implements IBootstrap {
	/**
	 * The application ID
	 */
	public const APP_ID = 'softwarecatalog';

	/**
	 * Application constructor
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';

		$this->registerHandlerServices(context: $context);

		// Wire up event-listener bindings — extracted to a single
		// single-responsibility helper per
		// `openspec/changes/method-decomposition/tasks.md` task 9.1.
		$this->registerEventListeners(context: $context);

		$this->registerDomainServices(context: $context);
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
	private function registerHandlerServices(IRegistrationContext $context): void {
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
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) 498 lines of flat, branch-free DI
	 * wiring — one `registerService()` closure per domain service. The length is genuine
	 * and this method SHOULD be split into per-domain registrars the way
	 * `registerHandlerServices()` already was; that split is a deliberate follow-up and is
	 * deferred here only because it is a behaviour change, not a quality-gate change.
	 */
	private function registerDomainServices(IRegistrationContext $context): void {
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

		// Register the organisation-merge service (VNG Softwarecatalogus #141 —
		// gemeentelijke herindeling / leveranciersovername).
		$context->registerService(
			MergeOrganisatieService::class,
			function ($container) {
				return new MergeOrganisatieService(
					container: $container,
					appManager: $container->get('OCP\App\IAppManager'),
					groupManager: $container->get(IGroupManager::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					eventDispatcher: $container->get(IEventDispatcher::class),
					settingsService: $container->get(SettingsService::class),
					organisationService: $container->get(OrganisatieService::class),
					progressTracker: $container->get(ProgressTracker::class),
					organizationHandler: $container->get(OrganizationHandler::class),
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
					groupManager: $container->get(IGroupManager::class),
					l10n: $container->get('OCP\IL10N')
				);
			}
		);

		// Register organization sync service.
		$context->registerService(
			OrganizationSyncService::class,
			function ($container) {
				return new OrganizationSyncService(
					organisationService: $container->get(OrganisatieService::class),
					contactPersonService: $container->get(ContactpersoonService::class),
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

		// Register module registration service (auto-sets registeredBy).
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

		// Register open-data publication service (sets/clears publicatiedatum — the
		// live OR RBAC publish gate; no @self.published, no app-local flag).
		$context->registerService(
			PublicationService::class,
			function ($container) {
				return new PublicationService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the anonymous-registration intake service (queues submissions
		// as registratiestatus=pending, no publicatiedatum — invisible until approved).
		$context->registerService(
			IntakeService::class,
			function ($container) {
				return new IntakeService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the registration/review moderation/approval-queue service
		// (generalised to also moderate beoordeeling — softwarecatalog#375).
		$context->registerService(
			ModerationService::class,
			function ($container) {
				return new ModerationService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the authenticated review-submission service (catalog-ratings,
		// softwarecatalog#375). Author identity comes from IUserSession, never
		// from client input.
		$context->registerService(
			ReviewService::class,
			function ($container) {
				return new ReviewService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					userSession: $container->get(\OCP\IUserSession::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the public approved-only review aggregate/read service
		// (catalog-ratings, softwarecatalog#375) — split from ReviewService
		// to keep each class under the complexity budget.
		$context->registerService(
			ReviewAggregateService::class,
			function ($container) {
				return new ReviewAggregateService(
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

		// Register the pure SBOM parser (no OR/HTTP dependency — ADR-008).
		$context->registerService(
			SbomParserService::class,
			function () {
				return new SbomParserService();
			}
		);

		// Register the SBOM import orchestrator (parse → replace previous
		// component set → bulk-save new set → record provenance).
		$context->registerService(
			SbomImportService::class,
			function ($container) {
				return new SbomImportService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					parser: $container->get(SbomParserService::class),
					progressTracker: $container->get(ProgressTracker::class),
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

		// Register Facet service for GEMMA-dimension facet aggregation
		// (gemma-faceted-search) — mirrors ViewService's cache-factory wiring.
		$context->registerService(
			FacetService::class,
			function ($container) {
				return new FacetService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					archiMateService: $container->get(ArchiMateService::class),
					queryBuilder: $container->get(ViewQueryBuilder::class),
					userSession: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface'),
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

		// Register the contract-approval delegation service. Delegation runs
		// in-process via decidesk's DecisionRequestedEvent / DecisionConcludedEvent
		// contract (IEventDispatcher) — no HTTP client, no polling reconcile job.
		$context->registerService(
			ContractApprovalService::class,
			function ($container) {
				return new ContractApprovalService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
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
			FederationMerger::class,
			function () {
				return new FederationMerger();
			}
		);
		$context->registerService(
			FederationService::class,
			function ($container) {
				return new FederationService(
					container: $container,
					appManager: $container->get(IAppManager::class),
					config: $container->get(FederationConfig::class),
					merger: $container->get(FederationMerger::class),
					settingsService: $container->get(SettingsService::class),
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

		// Register the EOL matcher + sync orchestration + scheduled job
		// (eol-feed-integration). EolMatcherService has zero OCP dependencies
		// by design (design.md "Nextcloud Integration" — pure matching logic).
		$context->registerService(
			EolMatcherService::class,
			function () {
				return new EolMatcherService();
			}
		);
		$context->registerService(
			EolSyncService::class,
			function ($container) {
				return new EolSyncService(
					settingsService: $container->get(SettingsService::class),
					matcher: $container->get(EolMatcherService::class),
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);
		$context->registerService(
			EolSyncJob::class,
			function ($container) {
				return new EolSyncJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					eolSyncService: $container->get(EolSyncService::class),
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
	private function registerEventListeners(IRegistrationContext $context): void {
		// TEST event listener for easily triggerable Nextcloud events.
		$context->registerEventListener(UserLoggedInEvent::class, TestEventListener::class);

		// OpenRegister object lifecycle events are NO LONGER broadcast to
		// SoftwareCatalogEventListener.
		//
		// That listener's own docblock has said "DISABLED: All processing is now
		// handled by cron-based OrganizationSyncService to avoid race conditions"
		// for some time — and the work really did move: OrganizationContactSyncJob
		// runs performOrganizationsSync / performContactSync / performUserSync on
		// a schedule. Only these registrations were never removed, so the listener
		// kept running its full body on every object event in the instance and
		// then discarded the result.
		//
		// The cost was not theoretical. Importing OpenCatalogi's configuration —
		// twelve seeded objects — produced 657 SoftwareCatalog event handlings.
		// Each one resolved three services from the container and wrote six log
		// lines BEFORE reaching the schema check that decides the event is not
		// ours. `occ maintenance:repair` reached 119 of 120 steps and then sat in
		// that last step for 25+ minutes at 100% CPU.
		//
		// Deliberately not "fixed" by making the listener filter earlier: the
		// documented design is that it does not run at all.
		// Module-compliance subscriber — runs on create/update only.
		$context->registerEventListener(ObjectCreatedEvent::class, ModuleComplianceSubscriber::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ModuleComplianceSubscriber::class);

		// Module registration — sets registeredBy on each save.
		$context->registerEventListener(ObjectCreatedEvent::class, ModuleRegistrationSubscriber::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ModuleRegistrationSubscriber::class);

		// Sync user profile updates into the contactpersoon mirror.
		$context->registerEventListener(UserProfileUpdatedEvent::class, UserProfileUpdatedEventListener::class);

		// Project a concluded decidesk contract-approval Decision onto the
		// catalog contract. Only fires when decidesk is installed (it owns the
		// DecisionConcludedEvent class); the listener filters by sourceApp and
		// IDOR-checks the decision id before projecting (the In onderhandeling
		// -> Actief transition is reached only here). Replaces the former HTTP
		// outcome-callback + daily reconcile poll.
		$context->registerEventListener(DecisionConcludedEvent::class, DecisionConcludedListener::class);

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
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function boot(IBootContext $context): void {
		// Background jobs are registered declaratively in appinfo/info.xml.
		// Initialization is handled by the Repair step (InitializeSettings).
		// Provision IAppConfig keys referenced as `@resolve:<key>` sentinels in
		// src/manifest.json so @conduction/nextcloud-vue's resolver chain can
		// pick them up synchronously via @nextcloud/initial-state (Step 1)
		// without falling through to the deferred backend fetch (Step 2).
		// See node_modules/@conduction/nextcloud-vue/src/utils/resolveManifestSentinels.js
		// — readInitialState() calls loadState(appId, key, undefined).
		$container = $this->getContainer();
		$initialState = $container->get(IInitialState::class);
		$appConfig = $container->get(IAppConfig::class);

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
		$registerId = $this->resolveVoorzieningenRegisterId(appConfig: $appConfig);
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
	private function resolveVoorzieningenRegisterId(IAppConfig $appConfig): string {
		$configJson = $appConfig->getValueString(self::APP_ID, 'voorzieningen_config', '');
		if ($configJson !== '') {
			$decoded = json_decode($configJson, true);
			if (is_array($decoded) === true
				&& isset($decoded['register']) === true
				&& $decoded['register'] !== ''
			) {
				return (string)$decoded['register'];
			}
		}

		$envelopeJson = $appConfig->getValueString(self::APP_ID, 'voorzieningen', '');
		if ($envelopeJson !== '') {
			$decoded = json_decode($envelopeJson, true);
			if (is_array($decoded) === true
				&& isset($decoded['configured']['register']) === true
				&& $decoded['configured']['register'] !== ''
			) {
				return (string)$decoded['configured']['register'];
			}
		}

		return $appConfig->getValueString(self::APP_ID, 'voorzieningen_register', '');
	}//end resolveVoorzieningenRegisterId()
}//end class
