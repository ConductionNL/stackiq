<?php

/**
 * Unit tests for ListenerRegistrar
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

require_once __DIR__ . '/decision-conclusion-event-doubles.php';

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\Stackiq\EventListener\AnonymousRegistrationListener;
use OCA\Stackiq\EventListener\AttestationCorrectionListener;
use OCA\Stackiq\EventListener\CatalogueMergeRelinker;
use OCA\Stackiq\EventListener\ContactPersonOrganisationListener;
use OCA\Stackiq\EventListener\ComplianceVerificationGuard;
use OCA\Stackiq\EventListener\ContractApprovalListener;
use OCA\Stackiq\EventListener\DecisionConcludedListener;
use OCA\Stackiq\EventListener\LifecycleCalendarListener;
use OCA\Stackiq\EventListener\ListenerRegistrar;
use OCA\Stackiq\EventListener\MaintenanceRecipientsListener;
use OCA\Stackiq\EventListener\ModuleComplianceSubscriber;
use OCA\Stackiq\EventListener\ModuleComponentListener;
use OCA\Stackiq\EventListener\ModuleRegistrationSubscriber;
use OCA\Stackiq\EventListener\ModuleVersionPublicationListener;
use OCA\Stackiq\EventListener\StackiqEventListener;
use OCA\Stackiq\EventListener\TestEventListener;
use OCA\Stackiq\EventListener\UsageClassificationSubscriber;
use OCA\Stackiq\EventListener\UserProfileUpdatedEventListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\TestCase;

/**
 * The event-to-listener wiring the AppInfo bootstrap delegates to.
 *
 * @covers \OCA\Stackiq\EventListener\ListenerRegistrar
 */
final class ListenerRegistrarTest extends TestCase {

	/**
	 * Register every listener against a recording context.
	 *
	 * @return array<int, array{0: string, 1: string}> The (event, listener) pairs, in order.
	 */
	private function registered(): array {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')
			->willReturnCallback(
				static function (string $event, string $listener, int $priority = 0) use (&$pairs): void {
					$pairs[] = [$event, $listener];
				}
			);

		(new ListenerRegistrar())->register(context: $context);

		return $pairs;
	}//end registered()

	/**
	 * Every OpenRegister and Nextcloud event is bound to exactly its listeners, in order.
	 *
	 * @return void
	 */
	public function testEveryEventIsBoundToItsListeners(): void {
		$pairs = array_values(
			array_filter(
				$this->registered(),
				static fn (array $pair): bool => $pair[1] !== DecisionConcludedListener::class
			)
		);

		self::assertSame(
			[
				[UserLoggedInEvent::class, TestEventListener::class],
				[ObjectCreatedEvent::class, ModuleComplianceSubscriber::class],
				[ObjectUpdatedEvent::class, ModuleComplianceSubscriber::class],
				[ObjectCreatedEvent::class, UsageClassificationSubscriber::class],
				[ObjectUpdatedEvent::class, UsageClassificationSubscriber::class],
				[ObjectCreatedEvent::class, ModuleRegistrationSubscriber::class],
				[ObjectCreatingEvent::class, AnonymousRegistrationListener::class],
				[ObjectCreatingEvent::class, ContactPersonOrganisationListener::class],
				[ObjectUpdatingEvent::class, ContactPersonOrganisationListener::class],
				[ObjectCreatingEvent::class, ModuleComponentListener::class],
				[ObjectUpdatingEvent::class, ModuleComponentListener::class],
				[ObjectCreatingEvent::class, ContractApprovalListener::class],
				[ObjectUpdatingEvent::class, ContractApprovalListener::class],
				[ObjectCreatingEvent::class, ComplianceVerificationGuard::class],
				[ObjectUpdatingEvent::class, ComplianceVerificationGuard::class],
				[ObjectUpdatedEvent::class, ModuleRegistrationSubscriber::class],
				[ObjectCreatedEvent::class, MaintenanceRecipientsListener::class],
				[ObjectsMergedEvent::class, CatalogueMergeRelinker::class],
				[ObjectCreatedEvent::class, ModuleVersionPublicationListener::class],
				[ObjectUpdatedEvent::class, ModuleVersionPublicationListener::class],
				[ObjectDeletedEvent::class, ModuleVersionPublicationListener::class],
				[ObjectCreatedEvent::class, LifecycleCalendarListener::class],
				[ObjectUpdatedEvent::class, LifecycleCalendarListener::class],
				[ObjectUpdatedEvent::class, AttestationCorrectionListener::class],
				[UserProfileUpdatedEvent::class, UserProfileUpdatedEventListener::class],
			],
			$pairs
		);
	}//end testEveryEventIsBoundToItsListeners()

	/**
	 * The disabled object-lifecycle listener stays off every event.
	 *
	 * @return void
	 */
	public function testTheDisabledLifecycleListenerIsNotRegistered(): void {
		$listeners = array_column($this->registered(), 1);

		self::assertNotContains(StackiqEventListener::class, $listeners);
	}//end testTheDisabledLifecycleListenerIsNotRegistered()

	/**
	 * The concluded-decision listener is bound to both spellings of the event while both resolve.
	 *
	 * @return void
	 */
	public function testTheDecisionListenerIsBoundToBothEventSpellings(): void {
		$decisionEvents = array_column(
			array_filter(
				$this->registered(),
				static fn (array $pair): bool => $pair[1] === DecisionConcludedListener::class
			),
			0
		);

		self::assertSame(
			[
				'\\OCA\\Decidiq\\Event\\DecisionConcludedEvent',
				'\\OCA\\Decidesk\\Event\\DecisionConcludedEvent',
			],
			array_values($decisionEvents)
		);
	}//end testTheDecisionListenerIsBoundToBothEventSpellings()
}//end class
