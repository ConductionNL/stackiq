<?php

/**
 * Hand-built factories of the services
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Registration
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Registration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\OrganisationService as OpenRegisterOrganisationService;
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\ContractApprovalService;
use OCA\Stackiq\Service\ContractStatusService;
use OCA\Stackiq\Service\EolMatcherService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\FacetService;
use OCA\Stackiq\Service\Federation\FederationConfig;
use OCA\Stackiq\Service\Federation\FederationMerger;
use OCA\Stackiq\Service\Federation\FederationService;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\IntakeService;
use OCA\Stackiq\Service\MergeOrganisatieService;
use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\ModuleRegistrationService;
use OCA\Stackiq\Service\ModuleVersionService;
use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\PublicationService;
use OCA\Stackiq\Service\ReviewAggregateService;
use OCA\Stackiq\Service\ReviewService;
use OCA\Stackiq\Service\SbomImportService;
use OCA\Stackiq\Service\SbomParserService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\EolSyncSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCA\Stackiq\Service\ViewQueryBuilder;
use OCA\Stackiq\Service\ViewService;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The services whose constructor arguments are wired by hand, one method per
 * domain, in a fixed order.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Registration
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class ServiceFactories {

	/**
	 * Register the four Stackiq handler services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function registerHandlerServices(IRegistrationContext $context): void {
		$context->registerService(
			'OCA\Stackiq\Service\Stackiq\OrganizationHandler',
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
			'OCA\Stackiq\Service\Stackiq\ContactPersonHandler',
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
			'OCA\Stackiq\Service\Stackiq\GroupHandler',
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
			'OCA\Stackiq\Service\Stackiq\HierarchyHandler',
			function (ContainerInterface $c) {
				return new HierarchyHandler(
					_organizationHandler: $c->get('OCA\Stackiq\Service\Stackiq\OrganizationHandler'),
					_contactPersonHandler: $c->get('OCA\Stackiq\Service\Stackiq\ContactPersonHandler'),
					_logger: $c->get(LoggerInterface::class),
					_userManager: $c->get(IUserManager::class),
					_groupManager: $c->get(IGroupManager::class)
				);
			}
		);
	}//end registerHandlerServices()

	/**
	 * Register the domain services, one domain after the other.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function registerDomainServices(IRegistrationContext $context): void {
		$this->registerOrganisationServices(context: $context);
		$this->registerSettingsServices(context: $context);
		$this->registerSyncServices(context: $context);
		$this->registerModuleServices(context: $context);
		$this->registerReviewAndSbomServices(context: $context);
		$this->registerArchiMateServices(context: $context);
		$this->registerViewServices(context: $context);
		$this->registerContractServices(context: $context);
		$this->registerFederationServices(context: $context);
		$this->registerEolServices(context: $context);
	}//end registerDomainServices()

	/**
	 * Register the organisation, merge and contact person services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerOrganisationServices(IRegistrationContext $context): void {
		// Organization event listeners removed - now using cron job for organization synchronization.
		// Contact person event listeners are still active for real-time processing.
		// Register new focused services.
		$context->registerService(
			OrganisatieService::class,
			function ($container) {
				return new OrganisatieService(
					logger: $container->get('Psr\Log\LoggerInterface'),
					container: $container,
					appManager: $container->get('OCP\App\IAppManager'),
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
	}//end registerOrganisationServices()

	/**
	 * Register the email and settings services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerSettingsServices(IRegistrationContext $context): void {
		// Register email service.
		$context->registerService(
			SymfonyEmailService::class,
			function ($container) {
				return new SymfonyEmailService(
					config: $container->get(IAppConfig::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					emailSettings: $container->get(EmailSettings::class)
				);
			}
		);

		// Register settings service.
		$context->registerService(
			SettingsService::class,
			function ($container) {
				return new SettingsService(
					catalogue: $container->get(CatalogueLocator::class),
					config: $container->get(IAppConfig::class),
					request: $container->get('OCP\IRequest'),
					roleGroupSettings: $container->get(RoleGroupSettings::class),
					container: $container,
					appManager: $container->get('OCP\App\IAppManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					groupManager: $container->get(IGroupManager::class),
					l10n: $container->get('OCP\IL10N'),
					emailSettings: $container->get(EmailSettings::class)
				);
			}
		);
	}//end registerSettingsServices()

	/**
	 * Register the organisation, contacts and gebruik sync services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerSyncServices(IRegistrationContext $context): void {
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
					// ADR-084: the published contract, resolved through the alias
					// registered above, not the concrete OpenRegister class.
					objectService: $container->get(ObjectServiceInterface::class),
					organisationMapper: $container->get('OCA\OpenRegister\Db\OrganisationMapper'),
				);
			}
		);

		// Register the Nextcloud-Contacts bridge (identity → NC addressbook,
		// relationship records keyed by contactsUid; ADR-019/ADR-022).
		$context->registerService(
			StackiqContactSyncService::class,
			function ($container) {
				return new StackiqContactSyncService(
					contactsManager: $container->get('OCP\Contacts\IManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					container: $container,
					userSession: $container->get('OCP\IUserSession')
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
					objectService: $container->get(ObjectServiceInterface::class),
				);
			}
		);
	}//end registerSyncServices()

	/**
	 * Register the module compliance, registration, publication, intake and moderation services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerModuleServices(IRegistrationContext $context): void {
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
		// (generalised to also moderate beoordeeling — stackiq#375).
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
	}//end registerModuleServices()

	/**
	 * Register the review, module version and SBOM services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerReviewAndSbomServices(IRegistrationContext $context): void {
		// Register the authenticated review-submission service (catalog-ratings,
		// stackiq#375). Author identity comes from IUserSession, never
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
		// (catalog-ratings, stackiq#375) — split from ReviewService
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
	}//end registerReviewAndSbomServices()

	/**
	 * Register the ArchiMate import and export services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerArchiMateServices(IRegistrationContext $context): void {
		// Register ArchiMate import service.
		$context->registerService(
			ArchiMateImportService::class,
			function ($container) {
				return new ArchiMateImportService(
					config: $container->get(IAppConfig::class),
					userSession: $container->get('OCP\IUserSession'),
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					organisationService: $container->get(OpenRegisterOrganisationService::class),
					dbConnection: $container->get(IDBConnection::class),
					progressTracker: $container->get(ProgressTracker::class)
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
					userSession: $container->get('OCP\IUserSession'),
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					importService: $container->get(ArchiMateImportService::class),
					exportService: $container->get(ArchiMateExportService::class),
					progressTracker: $container->get(ProgressTracker::class)
				);
			}
		);
	}//end registerArchiMateServices()

	/**
	 * Register the view, facet and progress tracking services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerViewServices(IRegistrationContext $context): void {
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
					catalogue: $container->get(CatalogueLocator::class),
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
					cacheFactory: $container->get(ICacheFactory::class),
					userSession: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					config: $container->get(IConfig::class),
					appConfig: $container->get(IAppConfig::class)
				);
			}
		);
	}//end registerViewServices()

	/**
	 * Register the contract status and approval services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerContractServices(IRegistrationContext $context): void {
		// Register the contract-status maintenance service.
		$context->registerService(
			ContractStatusService::class,
			function ($container) {
				return new ContractStatusService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get(LoggerInterface::class),
					appConfig: $container->get(\OCP\IAppConfig::class)
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
	}//end registerContractServices()

	/**
	 * Register the federation configuration, merger and service.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerFederationServices(IRegistrationContext $context): void {
		// Register the federation config, merger and service.
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
					logger: $container->get(LoggerInterface::class),
					// The integriq connection report (adopt-connection-registry). Passed by
					// name: this factory is hand-built, so the constructor default of null
					// would otherwise switch every federation report off without a sound.
					connectionReports: $container->get(ConnectionReportService::class)
				);
			}
		);
	}//end registerFederationServices()

	/**
	 * Register the EOL matcher and sync services.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function registerEolServices(IRegistrationContext $context): void {
		// Register the EOL matcher + sync orchestration
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
					eolSyncSettings: $container->get(EolSyncSettings::class),
					matcher: $container->get(EolMatcherService::class),
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					logger: $container->get(LoggerInterface::class),
					// Same reason as the FederationService factory above.
					connectionReports: $container->get(ConnectionReportService::class)
				);
			}
		);
	}//end registerEolServices()
}//end class
