<?php

/**
 * Golden tests for ContactpersoonService::syncNameFieldsToUser().
 *
 * Pins when a contact person's name, role and e-mail fields are written to
 * the account named by its username (only on a change, only to an existing
 * account) and the log calls on the way, so splitting the method keeps every
 * outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Golden tests for the name sync from a contact person to its account.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ContactpersoonNameSyncGoldenTest extends TestCase {

	/**
	 * Every logger, container, user lookup and store call, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * The known account.
	 *
	 * @var IUser
	 */
	private IUser $user;

	/**
	 * Build a contact person double.
	 *
	 * @param array $data The contact person data.
	 *
	 * @return object The contact person.
	 */
	private function contactPerson(array $data): object {
		return new class($data) {
			/**
			 * Build the double.
			 *
			 * @param array $data The data.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * The id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return 17;
			}

			/**
			 * The data.
			 *
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}
		};
	}//end contactPerson()

	/**
	 * Run syncNameFieldsToUser() and return the recorded calls.
	 *
	 * @param object $new The updated contact person.
	 * @param object|null $old The previous contact person.
	 *
	 * @return array<int, array>
	 */
	private function sync(object $new, ?object $old): array {
		$this->user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				$this->calls[] = ['userManager.get', $uid];
				return $uid === 'known' ? $this->user : null;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($userManager) {
				$this->calls[] = ['container.get', $id];
				return $userManager;
			}
		);

		$handler = $this->createMock(ContactPersonHandler::class);
		$handler->method('storeContactNameFields')->willReturnCallback(
			function (IUser $user, array $contactData): void {
				$this->assertSame($this->user, $user);
				$this->calls[] = ['store', $contactData];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$service = new ContactpersoonService(
			contactPersonHandler: $handler,
			groupHandler: $this->createMock(GroupHandler::class),
			hierarchyHandler: $this->createMock(HierarchyHandler::class),
			logger: $logger,
			container: $container,
			appManager: $this->createMock(IAppManager::class),
			config: $this->createMock(IAppConfig::class),
			settingsService: $this->createMock(SettingsService::class)
		);

		$target = (new ReflectionClass(objectOrClass: ContactpersoonService::class))->getMethod(name: 'syncNameFieldsToUser');
		$target->setAccessible(accessible: true);
		$target->invoke($service, $new, $old);
		return $this->calls;
	}//end sync()

	/**
	 * Unchanged name fields write nothing, whatever else changed.
	 *
	 * @return void
	 */
	public function testUnchangedNameFieldsWriteNothing(): void {
		$old = $this->contactPerson(data: ['username' => 'known', 'voornaam' => 'Ada', 'telefoon' => '1']);
		$new = $this->contactPerson(data: ['username' => 'known', 'voornaam' => 'Ada', 'telefoon' => '2', 'achternaam' => '']);

		$this->assertSame([], $this->sync(new: $new, old: $old));
	}//end testUnchangedNameFieldsWriteNothing()

	/**
	 * A change without a username, or for an unknown account, writes nothing.
	 *
	 * @return void
	 */
	public function testAChangeWithoutAnAccountWritesNothing(): void {
		$this->assertSame([], $this->sync(new: $this->contactPerson(data: ['voornaam' => 'Ada', 'username' => '']), old: null));

		$this->assertSame(
			[
				['container.get', 'OCP\IUserManager'],
				['userManager.get', 'ghost'],
				['debug', 'ContactpersoonService: No Nextcloud user found for name sync', ['username' => 'ghost']],
			],
			$this->sync(new: $this->contactPerson(data: ['username' => 'ghost', 'role' => 'Beheer']), old: null)
		);
	}//end testAChangeWithoutAnAccountWritesNothing()

	/**
	 * Each name field change writes the whole contact data to the account.
	 *
	 * @return void
	 */
	public function testEachNameFieldChangeWritesTheContactData(): void {
		$base = ['username' => 'known', 'voornaam' => 'Ada', 'tussenvoegsel' => 'de', 'achternaam' => 'Vries', 'role' => 'Beheer', 'e-mailadres' => 'a@example.org', 'telefoon' => '1'];
		foreach (['voornaam', 'tussenvoegsel', 'achternaam', 'role', 'e-mailadres'] as $field) {
			$this->calls = [];
			$newData = array_merge($base, [$field => 'changed']);

			$this->assertSame(
				[
					['container.get', 'OCP\IUserManager'],
					['userManager.get', 'known'],
					[
						'info',
						'ContactpersoonService: Syncing contactpersoon name fields to user',
						[
							'username' => 'known',
							'contactId' => 17,
							'changedData' => array_intersect_key($newData, array_flip(['voornaam', 'tussenvoegsel', 'achternaam', 'role', 'e-mailadres'])),
						],
					],
					['store', $newData],
				],
				$this->sync(new: $this->contactPerson(data: $newData), old: $this->contactPerson(data: $base))
			);
		}
	}//end testEachNameFieldChangeWritesTheContactData()

}//end class
