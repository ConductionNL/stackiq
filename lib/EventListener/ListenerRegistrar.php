<?php

/**
 * The EventListener component's container bindings
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
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

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\Stackiq\Kernel\Contracts\ContractApprovalServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Binds every event this app listens to to its listener. The AppInfo
 * bootstrap calls this instead of naming each listener.
 *
 * @category EventListener
 * @package  OCA\Stackiq\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class ListenerRegistrar {

	/**
	 * Wire all event listeners.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
	 */
	public function register(IRegistrationContext $context): void {
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

		// One calendar event per contract end date and module end of support (adopt-integration-leaves).
		$context->registerEventListener(ObjectCreatedEvent::class, LifecycleCalendarListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, LifecycleCalendarListener::class);

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
		foreach (ContractApprovalServiceInterface::DECISION_CONCLUDED_EVENTS as $concludedEvent) {
			if (class_exists($concludedEvent) === false) {
				continue;
			}

			$context->registerEventListener($concludedEvent, DecisionConcludedListener::class);
		}

	}//end register()
}//end class
