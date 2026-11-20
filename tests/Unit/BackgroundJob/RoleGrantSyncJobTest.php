<?php

/**
 * Unit tests for RoleGrantSyncJob.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\BackgroundJob\RoleGrantSyncJob;
use OCA\Stackiq\Service\RoleGrantSeeder;
use OCA\Stackiq\Service\RoleGrantSync;
use OCA\Stackiq\Service\RoleGroupMap;
use OCA\Stackiq\Service\SearchBounds;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The nightly sync walks every contact person in bounded batches.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
#[CoversClass(RoleGrantSyncJob::class)]
#[UsesClass(SearchBounds::class)]
final class RoleGrantSyncJobTest extends TestCase {

	/**
	 * The sync double.
	 *
	 * @var RoleGrantSync&MockObject
	 */
	private RoleGrantSync&MockObject $sync;

	/**
	 * The seeder double.
	 *
	 * @var RoleGrantSeeder&MockObject
	 */
	private RoleGrantSeeder&MockObject $seeder;

	/**
	 * The role map double.
	 *
	 * @var RoleGroupMap&MockObject
	 */
	private RoleGroupMap&MockObject $roleGroupMap;

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The contact persons OpenRegister holds, in order.
	 *
	 * @var list<mixed>
	 */
	private array $contactPersons = [];

	/**
	 * Every search query, in order.
	 *
	 * @var list<array>
	 */
	private array $queries = [];

	/**
	 * The usernames that have an account.
	 *
	 * @var list<string>
	 */
	private array $accounts = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sync = $this->createMock(originalClassName: RoleGrantSync::class);
		$this->seeder = $this->createMock(originalClassName: RoleGrantSeeder::class);
		$this->roleGroupMap = $this->createMock(originalClassName: RoleGroupMap::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy): array {
				$this->assertFalse(condition: $_rbac);
				$this->assertFalse(condition: $_multitenancy);
				$this->queries[] = $query;
				return array_slice($this->contactPersons, $query['_offset'], $query['_limit']);
			}
		);
	}//end setUp()

	/**
	 * Run the job once.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$registerConfig = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$registerConfig->method('getObjectService')->willReturn($this->objectService);
		$registerConfig->method('getRegisterIdForObjectType')->willReturn(1);
		$registerConfig->method('getSchemaIdForObjectType')->willReturn(3);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (in_array($uid, $this->accounts, true) === false) {
					return null;
				}

				$user = $this->createMock(originalClassName: IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);

		$job = new RoleGrantSyncJob(
			timeFactory: $this->createMock(originalClassName: ITimeFactory::class),
			roleGrantSync: $this->sync,
			seeder: $this->seeder,
			roleGroupMap: $this->roleGroupMap,
			registerConfig: $registerConfig,
			userManager: $userManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * Contact persons with an account are synced in pages of the batch size, until a short page.
	 *
	 * @return void
	 */
	public function testEveryContactPersonIsSyncedInBoundedBatches(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(true);
		$this->seeder->expects($this->never())->method('seed');
		for ($i = 0; $i < 130; $i++) {
			$this->contactPersons[] = ['username' => 'u' . $i];
			$this->accounts[] = 'u' . $i;
		}

		$synced = [];
		$this->sync->method('sync')->willReturnCallback(
			function (IUser $user, ?array $contactPerson) use (&$synced): array {
				$this->assertSame(expected: $user->getUID(), actual: $contactPerson['username']);
				$synced[] = $user->getUID();
				return ['added' => [], 'removed' => []];
			}
		);

		$this->runJob();

		$this->assertCount(expectedCount: 130, haystack: $synced);
		$this->assertSame(expected: [0, 100], actual: array_column($this->queries, '_offset'));
		$this->assertSame(expected: [100, 100], actual: array_column($this->queries, '_limit'));
		$this->assertSame(expected: ['register' => 1, 'schema' => 3], actual: $this->queries[0]['@self']);
	}//end testEveryContactPersonIsSyncedInBoundedBatches()

	/**
	 * Contact persons without a username or without an account are skipped, and one failure does not stop the rest.
	 *
	 * @return void
	 */
	public function testRowsWithoutAnAccountAreSkippedAndAFailureDoesNotStopTheRun(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(true);
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(['username' => 'cee']);
		$this->contactPersons = [['username' => 'ann'], ['name' => 'no account'], ['username' => 'gone'], $entity];
		$this->accounts = ['ann', 'cee'];

		$synced = [];
		$this->sync->method('sync')->willReturnCallback(
			function (IUser $user) use (&$synced): array {
				$synced[] = $user->getUID();
				if ($user->getUID() === 'ann') {
					throw new RuntimeException('group backend down');
				}

				return ['added' => [], 'removed' => []];
			}
		);

		$this->runJob();

		$this->assertSame(expected: ['ann', 'cee'], actual: $synced);
	}//end testRowsWithoutAnAccountAreSkippedAndAFailureDoesNotStopTheRun()

	/**
	 * Before the role groups are seeded the job seeds, and syncs only when that finished.
	 *
	 * @return void
	 */
	public function testAnUnfinishedSeedingIsRetriedBeforeSyncing(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(false);
		$this->seeder->expects($this->once())->method('seed')->willReturn(['seeded' => true]);
		$this->contactPersons = [['username' => 'ann']];
		$this->accounts = ['ann'];
		$this->sync->expects($this->once())->method('sync');

		$this->runJob();
	}//end testAnUnfinishedSeedingIsRetriedBeforeSyncing()

	/**
	 * A seeding that still fails means nobody is synced.
	 *
	 * @return void
	 */
	public function testNothingIsSyncedWhileTheSeedingFails(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(false);
		$this->seeder->method('seed')->willReturn(['seeded' => false]);
		$this->contactPersons = [['username' => 'ann']];
		$this->accounts = ['ann'];
		$this->sync->expects($this->never())->method('sync');

		$this->runJob();

		$this->assertSame(expected: [], actual: $this->queries);
	}//end testNothingIsSyncedWhileTheSeedingFails()
}//end class
