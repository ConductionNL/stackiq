<?php

/**
 * Stackiq Application
 *
 * This file contains the main application class for the Stackiq app.
 *
 * @category  Application
 * @package   OCA\Stackiq\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\Stackiq\BackgroundJob\JobRegistrar;
use OCA\Stackiq\Controller\ControllerRegistrar;
use OCA\Stackiq\Dashboard\ConceptOrganisatiesWidget;
use OCA\Stackiq\EventListener\AnonymousRegistrationListener;
use OCA\Stackiq\EventListener\ContactPersonOrganisationListener;
use OCA\Stackiq\EventListener\DecisionConcludedListener;
use OCA\Stackiq\EventListener\MaintenanceRecipientsListener;
use OCA\Stackiq\EventListener\ModuleComplianceSubscriber;
use OCA\Stackiq\EventListener\ModuleRegistrationSubscriber;
use OCA\Stackiq\EventListener\ModuleVersionPublicationListener;
use OCA\Stackiq\EventListener\TestEventListener;
use OCA\Stackiq\EventListener\UserProfileUpdatedEventListener;
use OCA\Stackiq\Middleware\ApiContractMiddleware;
use OCA\Stackiq\Service\ContractApprovalService;
use OCA\Stackiq\Service\ServiceRegistrar;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Main Application class for Stackiq
 *
 * @category Application
 * @package  OCA\Stackiq\AppInfo
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class Application extends App implements IBootstrap {
	/**
	 * The application ID, kept here for code that reads it from the bootstrap class
	 */
	public const APP_ID = AppIdentity::APP_ID;

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

		// The service bindings and the handler factories (ADR-084 aliases included)
		// live with the Service component.
		(new ServiceRegistrar())->register(context: $context);

		// Wire up event-listener bindings — extracted to a single
		// single-responsibility helper per
		// `openspec/changes/method-decomposition/tasks.md` task 9.1.
		$this->registerEventListeners(context: $context);

		$this->registerDomainServices(context: $context);

		// API-Version header and problem details on the JSON API (spec api-contract).
		$context->registerMiddleware(ApiContractMiddleware::class);
	}//end register()

	/**
	 * Wire all domain-level services, the hand-built jobs and controllers, and the widget.
	 *
	 * Each component registers its own factories: the services in
	 * Service\ServiceRegistrar, the jobs in BackgroundJob\JobRegistrar and the
	 * controllers in Controller\ControllerRegistrar.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
	 */
	private function registerDomainServices(IRegistrationContext $context): void {
		(new ServiceRegistrar())->registerDomainServices(context: $context);
		(new JobRegistrar())->register(context: $context);
		(new ControllerRegistrar())->register(context: $context);

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
		// StackiqEventListener.
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
		// twelve seeded objects — produced 657 Stackiq event handlings.
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

		// An organisation registered without an account stays pending until reviewed.
		$context->registerEventListener(ObjectCreatingEvent::class, AnonymousRegistrationListener::class);

		// A contact person only from someone who may add members to its organisation.
		$context->registerEventListener(ObjectCreatingEvent::class, ContactPersonOrganisationListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, ContactPersonOrganisationListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ModuleRegistrationSubscriber::class);

		// Queue the owner resolution when a supplier announces maintenance (lifecycle-maintenance-and-supplier-roadmap).
		$context->registerEventListener(ObjectCreatedEvent::class, MaintenanceRecipientsListener::class);

		// A module version is public only while its application is (publication-field-rules).
		$context->registerEventListener(ObjectCreatedEvent::class, ModuleVersionPublicationListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ModuleVersionPublicationListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, ModuleVersionPublicationListener::class);

		// Sync user profile updates into the contactpersoon mirror.
		$context->registerEventListener(UserProfileUpdatedEvent::class, UserProfileUpdatedEventListener::class);

		// Project a concluded contract-approval Decision from the decision app
		// onto the catalog contract. The listener filters by sourceApp and
		// IDOR-checks the decision id before projecting (the In onderhandeling
		// -> Actief transition is reached only here). Replaces the former HTTP
		// outcome-callback + daily reconcile poll.
		//
		// BOTH SPELLINGS, by FQN STRING rather than `::class`. That app renamed
		// its PSR-4 root from OCA\Decidesk to OCA\Decidiq with no compatibility
		// alias, and `::class` on an imported name resolves at COMPILE TIME — so
		// this registered a class nothing dispatches any more, the listener
		// never fired, and every approved contract stayed in `In onderhandeling`.
		// An event with no listener and a listener on no event look identical
		// from here: nothing throws, nothing is logged.
		//
		// Registering a name that does not resolve is harmless, because dispatch
		// matches on the concrete event class, but the guard is kept so this
		// stays symmetric with ContractApprovalService::isDelegationConfigured()
		// on the outbound side.
		foreach (ContractApprovalService::DECISION_CONCLUDED_EVENTS as $concludedEvent) {
			if (class_exists($concludedEvent) === false) {
				continue;
			}

			$context->registerEventListener($concludedEvent, DecisionConcludedListener::class);
		}

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

		// The SECOND register this app owns. lib/Settings/softwarecatalogus_register.json
		// declares two: `voorzieningen` (15 schemas) and `vng-gemma` / AMEF
		// (element, model, property-definition, relation, view). A schema is only
		// fetchable through /api/objects/{register}/{schema} from a register that
		// ATTACHES it — OpenRegister's ObjectService::setSchema() resolves a slug
		// register-scoped and, since its 2026-08-16 change, THROWS on a scoped miss
		// instead of falling back to a global lookup. So the manifest pages whose
		// schema lives in the AMEF register (Standaarden / StandaardDetail, both
		// `schema: element`) need their OWN sentinel; pointing them at
		// `voorzieningen_register` yields `404 {"message":"Schema not found:
		// 'element'"}` on every fetch.
		//
		// Its canonical home is the `amef_config` JSON blob written by
		// SettingsService::configureAmef(), exactly as `voorzieningen_config` is for
		// the register above. configureAmef() detects the register by the PRESENCE of
		// the AMEF core schemas rather than by slug, which is the property this
		// sentinel needs — whatever it selects is by construction a register that
		// carries `element`.
		$amefRegisterId = $this->resolveAmefRegisterId(appConfig: $appConfig);
		$amefProvisioned = null;
		if ($amefRegisterId !== '') {
			$amefProvisioned = $amefRegisterId;
		}

		$initialState->provideInitialState('amef_register', $amefProvisioned);
	}//end boot()

	/**
	 * Resolve the numeric AMEF (vng-gemma) register id from the canonical config.
	 *
	 * Mirrors resolveVoorzieningenRegisterId() below, over the AMEF key family.
	 * Resolution order:
	 *  1. `amef_config` JSON blob's `register` field — the canonical home written
	 *     by SettingsService::setAmefConfig() from configureAmef().
	 *  2. The same blob's `register_id` field — the legacy shape
	 *     SettingsService::getAmefConfig() assembles when the blob is absent.
	 *  3. The flat `amef_register_id` scalar key — legacy fallback.
	 *
	 * @param IAppConfig $appConfig The app config service.
	 *
	 * @return string The numeric register id, or '' when none is configured.
	 */
	private function resolveAmefRegisterId(IAppConfig $appConfig): string {
		$configJson = $appConfig->getValueString(self::APP_ID, 'amef_config', '');
		if ($configJson !== '') {
			$decoded = json_decode($configJson, true);
			if (is_array($decoded) === true) {
				foreach (['register', 'register_id'] as $key) {
					if (isset($decoded[$key]) === true && $decoded[$key] !== '') {
						return (string)$decoded[$key];
					}
				}
			}
		}

		return $appConfig->getValueString(self::APP_ID, 'amef_register_id', '');
	}//end resolveAmefRegisterId()

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
