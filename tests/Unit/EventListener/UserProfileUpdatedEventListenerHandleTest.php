<?php

/**
 * What UserProfileUpdatedEventListener::handle does with irrelevant, relevant and failing profile updates.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\Stackiq\EventListener\UserProfileUpdatedEventListener;
use OCA\Stackiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Stackiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for UserProfileUpdatedEventListener::handle().
 *
 * @covers \OCA\Stackiq\EventListener\UserProfileUpdatedEventListener
 * @uses   \OCA\Stackiq\EventListener\ProfileContactSync
 */
final class UserProfileUpdatedEventListenerHandleTest extends TestCase {

	/**
	 * Calls recorded on the logger, as [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A logger that records every call.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A profile update event for user jan.
	 *
	 * @param list<string> $changes The changed fields.
	 *
	 * @return UserProfileUpdatedEvent The event.
	 */
	private function event(array $changes): UserProfileUpdatedEvent {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('jan');

		return new UserProfileUpdatedEvent(user: $user, oldData: [], newData: [], changes: $changes);
	}//end event()

	/**
	 * A container serving the given services by id.
	 *
	 * @param array<string, mixed> $services The services.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): mixed {
				if (array_key_exists(key: $id, array: $services) === false) {
					throw new RuntimeException(message: 'no '.$id);
				}

				$service = $services[$id];
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		return $container;
	}//end container()

	/**
	 * The listener over the given container, with an object service that is never reached.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return UserProfileUpdatedEventListener The listener.
	 */
	private function listener(ContainerInterface $container): UserProfileUpdatedEventListener {
		return new UserProfileUpdatedEventListener(
			container: $container,
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class)
		);
	}//end listener()

	/**
	 * Another event type is ignored without touching the container.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->listener(container: $container)->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * Changes outside the mapped fields are logged at debug and nothing is synced.
	 *
	 * @return void
	 */
	public function testIrrelevantChangesAreNotSynced(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getVoorzieningenConfig');
		$container = $this->container(services: [LoggerInterface::class => $this->logger(), SettingsServiceInterface::class => $settings]);

		$this->listener(container: $container)->handle($this->event(changes: ['avatar', 'language']));

		$this->assertSame(
			expected: [
				[
					'debug',
					'[UserProfileUpdatedEventListener] No relevant field changes for contactpersoon sync',
					['userId' => 'jan', 'changes' => ['avatar', 'language']],
				],
			],
			actual: $this->logged
		);
	}//end testIrrelevantChangesAreNotSynced()

	/**
	 * A mapped change is announced with only the relevant fields, then the sync starts and stops on missing config.
	 *
	 * @return void
	 */
	public function testRelevantChangesStartTheSync(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->once())->method('getVoorzieningenConfig')->willReturn([]);
		$container = $this->container(services: [LoggerInterface::class => $this->logger(), SettingsServiceInterface::class => $settings]);

		$this->listener(container: $container)->handle($this->event(changes: ['avatar', 'lastName', 'email']));

		$this->assertSame(expected: 'info', actual: $this->logged[0][0]);
		$this->assertSame(expected: '[UserProfileUpdatedEventListener] Syncing user profile changes to contactpersoon', actual: $this->logged[0][1]);
		$this->assertSame(expected: ['userId' => 'jan', 'relevantChanges' => [1 => 'lastName', 2 => 'email']], actual: $this->logged[0][2]);
		$this->assertSame(
			expected: ['warning', '[UserProfileUpdatedEventListener] Voorzieningen config missing register or contactpersoon_schema', []],
			actual: $this->logged[1]
		);
		$this->assertCount(expectedCount: 2, haystack: $this->logged);
	}//end testRelevantChangesStartTheSync()

	/**
	 * A failure during the sync is logged as an error with the user and message, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingSyncIsLoggedAsAnError(): void {
		$container = $this->container(
			services: [LoggerInterface::class => $this->logger(), SettingsServiceInterface::class => new RuntimeException(message: 'settings unavailable')]
		);

		$this->listener(container: $container)->handle($this->event(changes: ['firstName']));

		$error = $this->logged[array_key_last(array: $this->logged)];
		$this->assertSame(expected: 'error', actual: $error[0]);
		$this->assertSame(expected: '[UserProfileUpdatedEventListener] Error syncing profile to contactpersoon', actual: $error[1]);
		$this->assertSame(expected: 'jan', actual: $error[2]['userId']);
		$this->assertSame(expected: 'settings unavailable', actual: $error[2]['exception']);
	}//end testAFailingSyncIsLoggedAsAnError()

	/**
	 * When even the logger cannot be fetched, the failure is swallowed.
	 *
	 * @return void
	 */
	public function testAFailureWithoutALoggerIsSwallowed(): void {
		$container = $this->container(services: [LoggerInterface::class => new RuntimeException(message: 'no logger')]);

		$this->listener(container: $container)->handle($this->event(changes: ['firstName']));

		$this->assertSame(expected: [], actual: $this->logged);
	}//end testAFailureWithoutALoggerIsSwallowed()
}//end class
