<?php

/**
 * MigrateContactsToNc over a populated register: linking, creating, skipping
 * and failing per object, and when the step marks itself converged.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Repair
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

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Repair\MigrateContactsToNc;
use OCA\Stackiq\Service\Contactpersoon\SystemContactWrites;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the per-object work of the contacts migration.
 */
#[CoversClass(MigrateContactsToNc::class)]
#[UsesClass(SystemContactWrites::class)]
class MigrateContactsToNcTest extends TestCase {

	/**
	 * Everything the output was told, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $said = [];

	/**
	 * The saveObject calls, as [uuid, contactsUid].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * The app config writes.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $configWrites = [];

	/**
	 * The contact sync double.
	 *
	 * @var StackiqContactSyncService&MockObject
	 */
	private StackiqContactSyncService&MockObject $contacts;

	/**
	 * The settings double.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Installed apps.
	 *
	 * @var string[]
	 */
	private array $installed = ['openregister'];

	/**
	 * Set up the shared doubles with a configured register and both schemas.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->contacts = $this->createMock(StackiqContactSyncService::class);
		$this->contacts->method('isAvailable')->willReturn(true);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(3);
		$this->settings->method('getSchemaIdForObjectType')->willReturnMap(
			[
				['contactPerson', 11],
				['organization', 12],
			]
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An object service whose register/schema reads answer the given objects per schema.
	 *
	 * @param array<int, array<int, mixed>> $bySchema Objects keyed by schema id.
	 * @param bool                          $saveFails Whether saveObject throws.
	 *
	 * @return ObjectServiceInterface The double.
	 */
	private function objectService(array $bySchema, bool $saveFails=false): ObjectServiceInterface {
		$service = $this->createMock(ObjectServiceInterface::class);
		$schema  = 0;
		$service->method('runAsSystem')->willReturnCallback(static fn (callable $work): mixed => $work());
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnCallback(
			function (string|int $id) use ($service, &$schema): ObjectServiceInterface {
				$schema = (int) $id;
				return $service;
			}
		);
		$service->method('findAll')->willReturnCallback(
			function () use (&$schema, $bySchema): array {
				return ($bySchema[$schema] ?? []);
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, mixed $register, mixed $schema, ?string $uuid) use ($saveFails): ObjectEntityInterface {
				if ($saveFails === true) {
					throw new RuntimeException('save refused');
				}

				$this->saved[] = [$register, $schema, $uuid, $object['contactsUid']];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		return $service;
	}//end objectService()

	/**
	 * Run the step and return what the output was told.
	 *
	 * @return array<int, array<int, mixed>> The output calls.
	 */
	private function runStep(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->configWrites[] = [$app, $key, $value];
				return true;
			}
		);
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $method) {
			$output->method($method)->willReturnCallback(
				function (string $message) use ($method): void {
					$this->said[] = [$method, $message];
				}
			);
		}

		$step = new MigrateContactsToNc(
			appManager: $appManager,
			settingsService: $this->settings,
			contactSync: $this->contacts,
			appConfig: $appConfig,
			logger: $this->logger
		);
		$step->run($output);

		return $this->said;
	}//end runStep()

	/**
	 * Every object kind is handled: kept, linked, created and failed, and a failure blocks convergence.
	 *
	 * @return void
	 */
	public function testEachObjectIsKeptLinkedCreatedOrFailed(): void {
		$entity = new class {

			/**
			 * @return array The object data.
			 */
			public function getObject(): array {
				return ['naam' => 'Kept', 'contactsUid' => 'uid-kept'];
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'obj-kept';
			}
		};
		$serialisable = new class implements \JsonSerializable {

			/**
			 * @return array The object data.
			 */
			public function jsonSerialize(): array {
				return ['naam' => 'Nieuw', '@self' => ['id' => 'obj-new']];
			}
		};
		$this->settings->method('getObjectService')->willReturn(
			$this->objectService(
				[
					11 => [$entity, ['naam' => 'Bekend', 'id' => 'obj-known'], $serialisable, ['naam' => 'Kapot', 'id' => 'obj-broken']],
					12 => [],
				]
			)
		);
		$this->contacts->method('findContactByUid')->willReturnCallback(
			static fn (string $uid): ?array => $uid === 'uid-kept' ? ['UID' => $uid] : null
		);
		$this->contacts->method('findContactForRecord')->willReturnCallback(
			static fn (string $type, array $data): ?array => $data['naam'] === 'Bekend' ? ['UID' => 'uid-known'] : null
		);
		$this->contacts->method('syncToContacts')->willReturnCallback(
			static fn (string $type, array $data): ?string => match ($data['naam']) {
				'Bekend' => 'uid-known',
				'Nieuw' => 'uid-new',
				default => null,
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with(
				'[MigrateContactsToNc] Could not resolve/create a contact; leaving object intact for re-run',
				['objectType' => 'contactPerson', 'uuid' => 'obj-broken']
			);

		$said = $this->runStep();

		$this->assertSame(expected: [[3, 11, 'obj-known', 'uid-known'], [3, 11, 'obj-new', 'uid-new']], actual: $this->saved);
		$this->assertSame(
			expected: [
				['info', 'Contacts migration [contactPerson]: 1 linked, 1 created, 1 already-linked, 1 skipped'],
				['info', 'Contacts migration [organization]: 0 linked, 0 created, 0 already-linked, 0 skipped'],
			],
			actual: $said
		);
		$this->assertSame(expected: [], actual: $this->configWrites, message: 'a failed object must leave the step to run again');
	}//end testEachObjectIsKeptLinkedCreatedOrFailed()

	/**
	 * A clean pass marks the migration converged.
	 *
	 * @return void
	 */
	public function testACleanPassConverges(): void {
		$this->settings->method('getObjectService')->willReturn(
			$this->objectService([11 => [['naam' => 'Nieuw', 'id' => 'obj-1']], 12 => [['naam' => 'Org', 'id' => 'org-1']]])
		);
		$this->contacts->method('findContactForRecord')->willReturn(null);
		$this->contacts->method('syncToContacts')->willReturnOnConsecutiveCalls('uid-1', 'uid-2');

		$said = $this->runStep();

		$this->assertSame(expected: [[3, 11, 'obj-1', 'uid-1'], [3, 12, 'org-1', 'uid-2']], actual: $this->saved);
		$this->assertSame(
			expected: ['info', 'Contacts migration [contactPerson]: 0 linked, 1 created, 0 already-linked, 0 skipped'],
			actual: $said[0]
		);
		$this->assertSame(expected: [['stackiq', 'contacts_migration_done', '1']], actual: $this->configWrites);
		$this->assertSame(expected: ['info', 'Contacts migration converged — future runs will skip.'], actual: end($said));
	}//end testACleanPassConverges()

	/**
	 * A save the engine refuses counts as failed and is logged, not thrown.
	 *
	 * @return void
	 */
	public function testARefusedSaveIsLoggedAndBlocksConvergence(): void {
		$this->settings->method('getObjectService')->willReturn(
			$this->objectService([11 => [['naam' => 'Nieuw', 'id' => 'obj-1']]], saveFails: true)
		);
		$this->contacts->method('syncToContacts')->willReturn('uid-1');
		$this->logger->expects($this->once())->method('error')
			->with(
				'[MigrateContactsToNc] Failed to migrate object — left intact for re-run',
				['objectType' => 'contactPerson', 'error' => 'save refused']
			);

		$said = $this->runStep();

		$this->assertSame(
			expected: ['info', 'Contacts migration [contactPerson]: 0 linked, 0 created, 0 already-linked, 1 skipped'],
			actual: $said[0]
		);
		$this->assertSame(expected: [], actual: $this->configWrites);
	}//end testARefusedSaveIsLoggedAndBlocksConvergence()

	/**
	 * Without an object service each type is skipped with a warning and nothing converges.
	 *
	 * @return void
	 */
	public function testNoObjectServiceSkipsEveryType(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$said = $this->runStep();

		$this->assertSame(
			expected: [
				['warning', 'OpenRegister ObjectService unavailable — skipping'],
				['info', 'Contacts migration [contactPerson]: 0 linked, 0 created, 0 already-linked, 0 skipped'],
				['warning', 'OpenRegister ObjectService unavailable — skipping'],
				['info', 'Contacts migration [organization]: 0 linked, 0 created, 0 already-linked, 0 skipped'],
				['info', 'Contacts migration converged — future runs will skip.'],
			],
			actual: $said
		);
	}//end testNoObjectServiceSkipsEveryType()

	/**
	 * Without OpenRegister the step says so and reads nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsRead(): void {
		$this->installed = ['contacts'];
		$this->settings->expects($this->never())->method('getVoorzieningenRegisterId');

		$this->assertSame(
			expected: [['info', 'OpenRegister not installed — skipping contacts migration']],
			actual: $this->runStep()
		);
	}//end testWithoutOpenRegisterNothingIsRead()

	/**
	 * The step has a readable name.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new MigrateContactsToNc(
			appManager: $this->createMock(IAppManager::class),
			settingsService: $this->settings,
			contactSync: $this->contacts,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->logger
		);

		$this->assertSame(expected: 'Migrate Stackiq contacts/organisations to the Nextcloud addressbook', actual: $step->getName());
	}//end testName()
}//end class
