<?php

/**
 * Golden tests for the MigrateContactsToNc repair step flow.
 *
 * Pins every skip path of migrate() with its progress and output calls, the
 * per-type summary lines, when the step marks itself converged, and how
 * extractUuid() picks an object's UUID, so splitting them keeps every outcome.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Repair\MigrateContactsToNc;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\App\IAppManager;
use OCP\Contacts\IManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Golden tests for MigrateContactsToNc::migrate() and extractUuid().
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class MigrateContactsToNcGoldenTest extends TestCase {

	/**
	 * Every output and config call, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * The stored converged flag.
	 *
	 * @var string
	 */
	private string $converged = '';

	/**
	 * The installed apps.
	 *
	 * @var array<int, string>
	 */
	private array $installed = ['openregister'];

	/**
	 * Whether Nextcloud Contacts is available.
	 *
	 * @var boolean
	 */
	private bool $contactsAvailable = true;

	/**
	 * The voorzieningen register id.
	 *
	 * @var integer|null
	 */
	private ?int $registerId = 3;

	/**
	 * The schema id per object type.
	 *
	 * @var array<string, int>
	 */
	private array $schemaIds = [];

	/**
	 * Build the repair step.
	 *
	 * @return MigrateContactsToNc
	 */
	private function step(): MigrateContactsToNc {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturnCallback(fn (): ?int => $this->registerId);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $type): ?int => $this->schemaIds[$type] ?? null);
		// Reading the objects fails, inside the elevated run.
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('runAsSystem')->willReturnCallback(fn (callable $work): mixed => $work());
		$objectService->method('setRegister')->willThrowException(new \RuntimeException('read failed'));
		$settings->method('getObjectService')->willReturn($objectService);

		$manager = $this->createMock(IManager::class);
		$manager->method('isEnabled')->willReturnCallback(fn (): bool => $this->contactsAvailable);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default): string {
				$this->calls[] = ['getValueString', $app, $key, $default];
				return $this->converged;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = ['setValueString', $app, $key, $value];
				return true;
			}
		);

		return new MigrateContactsToNc(
			appManager: $appManager,
			settingsService: $settings,
			contactSync: new StackiqContactSyncService(contactsManager: $manager, logger: new NullLogger()),
			appConfig: $appConfig,
			logger: new NullLogger()
		);
	}//end step()

	/**
	 * Run migrate() and return the recorded calls.
	 *
	 * @return array<int, array>
	 */
	private function migrate(): array {
		$output = $this->createMock(IOutput::class);
		foreach (['startProgress', 'advance', 'finishProgress', 'info', 'warning'] as $method) {
			$output->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method): void {
					// advance() is recorded without its default description.
					if ($method === 'advance') {
						$args = [$args[0]];
					}

					$this->calls[] = array_merge([$method], $args);
				}
			);
		}

		$target = (new ReflectionClass(objectOrClass: MigrateContactsToNc::class))->getMethod(name: 'migrate');
		$target->setAccessible(accessible: true);
		$target->invoke($this->step(), $output);
		return $this->calls;
	}//end migrate()

	/**
	 * A converged step skips quietly.
	 *
	 * @return void
	 */
	public function testAConvergedStepSkipsQuietly(): void {
		$this->converged = '1';

		$this->assertSame(
			[
				['startProgress', 2],
				['getValueString', 'stackiq', 'contacts_migration_done', ''],
				['advance', 2],
				['finishProgress'],
			],
			$this->migrate()
		);
	}//end testAConvergedStepSkipsQuietly()

	/**
	 * Each missing precondition skips with its own message.
	 *
	 * @return void
	 */
	public function testEachMissingPreconditionSkipsWithItsMessage(): void {
		$cases = [
			'OpenRegister not installed — skipping contacts migration' => fn () => $this->installed = ['files'],
			'Nextcloud Contacts not available — skipping contacts migration (will retry on next upgrade)' => fn () => $this->contactsAvailable = false,
			'Voorzieningen register not configured — skipping contacts migration' => fn () => $this->registerId = null,
		];
		foreach ($cases as $message => $arrange) {
			$this->calls = [];
			$this->installed = ['openregister'];
			$this->contactsAvailable = true;
			$this->registerId = 3;
			$arrange();

			$this->assertSame(
				[
					['startProgress', 2],
					['getValueString', 'stackiq', 'contacts_migration_done', ''],
					['info', $message],
					['advance', 2],
					['finishProgress'],
				],
				$this->migrate()
			);
		}
	}//end testEachMissingPreconditionSkipsWithItsMessage()

	/**
	 * Both types reported; with nothing failed the step converges.
	 *
	 * @return void
	 */
	public function testACleanRunReportsBothTypesAndConverges(): void {
		$this->assertSame(
			[
				['startProgress', 2],
				['getValueString', 'stackiq', 'contacts_migration_done', ''],
				['warning', 'Schema id for "contactPerson" not configured — skipping'],
				['info', 'Contacts migration [contactPerson]: 0 linked, 0 created, 0 already-linked, 0 skipped'],
				['advance', 1],
				['warning', 'Schema id for "organization" not configured — skipping'],
				['info', 'Contacts migration [organization]: 0 linked, 0 created, 0 already-linked, 0 skipped'],
				['advance', 1],
				['setValueString', 'stackiq', 'contacts_migration_done', '1'],
				['info', 'Contacts migration converged — future runs will skip.'],
				['finishProgress'],
			],
			$this->migrate()
		);
	}//end testACleanRunReportsBothTypesAndConverges()

	/**
	 * A type whose objects cannot be read keeps the step from converging.
	 *
	 * @return void
	 */
	public function testAReadFailureKeepsTheStepFromConverging(): void {
		$this->schemaIds = ['organization' => 8];

		$this->assertSame(
			[
				['startProgress', 2],
				['getValueString', 'stackiq', 'contacts_migration_done', ''],
				['warning', 'Schema id for "contactPerson" not configured — skipping'],
				['info', 'Contacts migration [contactPerson]: 0 linked, 0 created, 0 already-linked, 0 skipped'],
				['advance', 1],
				['warning', 'Could not read "organization" objects: read failed'],
				['info', 'Contacts migration [organization]: 0 linked, 0 created, 0 already-linked, 0 skipped'],
				['advance', 1],
				['finishProgress'],
			],
			$this->migrate()
		);
	}//end testAReadFailureKeepsTheStepFromConverging()

	/**
	 * The UUID comes from the entity, else the data id, else the @self id.
	 *
	 * @return void
	 */
	public function testTheUuidComesFromTheEntityThenTheData(): void {
		$entity = fn (mixed $uuid): object => new class($uuid) {
			/**
			 * Build the entity.
			 *
			 * @param mixed $uuid The uuid.
			 */
			public function __construct(private mixed $uuid) {
			}

			/**
			 * The uuid.
			 *
			 * @return mixed
			 */
			public function getUuid(): mixed {
				return $this->uuid;
			}
		};
		$cases = [
			[$entity('uuid-entity'), ['id' => 'data-id'], 'uuid-entity'],
			[$entity(42), [], '42'],
			[$entity(''), ['id' => 'data-id'], 'data-id'],
			[$entity(null), ['@self' => ['id' => 'self-id']], 'self-id'],
			[new \stdClass(), ['id' => 7], '7'],
			[['getUuid' => 'x'], ['id' => '', '@self' => ['id' => 'self-id']], 'self-id'],
			['plain', ['id' => null, '@self' => ['id' => '']], null],
			[null, [], null],
		];
		$target = (new ReflectionClass(objectOrClass: MigrateContactsToNc::class))->getMethod(name: 'extractUuid');
		$target->setAccessible(accessible: true);
		$step = $this->step();

		foreach ($cases as [$object, $data, $expected]) {
			$this->assertSame($expected, $target->invoke($step, $object, $data));
		}
	}//end testTheUuidComesFromTheEntityThenTheData()

}//end class
