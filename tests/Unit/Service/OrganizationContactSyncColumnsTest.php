<?php

/**
 * The scheduled sync's contact and user steps skip a contact table without e-mail or username columns.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/organization-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers performContactSync() and performUserSync() against the current contactPerson table.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 */
class OrganizationContactSyncColumnsTest extends TestCase {

	/**
	 * A database error with the given reason.
	 *
	 * @param int $reason One of the OCP\DB\Exception reasons
	 *
	 * @return DbException
	 */
	private static function dbError(int $reason): DbException {
		return new class('column o.e_mailadres does not exist', $reason) extends DbException {
			/**
			 * Constructor.
			 *
			 * @param string $message The driver message
			 * @param int    $why     The reason
			 */
			public function __construct(string $message, private readonly int $why) {
				parent::__construct(message: $message);
			}

			/**
			 * The reason given at construction.
			 *
			 * @return int|null
			 */
			public function getReason(): ?int {
				return $this->why;
			}
		};
	}//end dbError()

	/**
	 * A service whose contact query fails with the given error.
	 *
	 * @param DbException $error What executeQuery() throws
	 *
	 * @return OrganizationSyncService
	 */
	private function service(DbException $error): OrganizationSyncService {
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		foreach (['select', 'from', 'innerJoin', 'leftJoin', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('executeQuery')->willThrowException($error);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('getDatabasePlatform')->willReturn(new class {
		});

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '26', 'contactpersoon_schema' => '45']);

		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (['logger' => new NullLogger(), 'settingsService' => $settings, 'db' => $db] as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service;
	}//end service()

	/**
	 * A table without the columns is skipped, so the rest of the scheduled sync runs.
	 *
	 * @return void
	 */
	public function testAMissingColumnSkipsTheContactStep(): void {
		$stats = $this->service(error: self::dbError(reason: DbException::REASON_INVALID_FIELD_NAME))->performContactSync();

		$this->assertSame(expected: 0, actual: $stats['contactPersonsProcessed']);
		$this->assertSame(expected: [], actual: $stats['errors']);
		$this->assertSame(expected: 'the contact person table has no e-mail or username column', actual: $stats['skipped']);
	}//end testAMissingColumnSkipsTheContactStep()

	/**
	 * The user step reads `username`, which the table no longer has: skipped as well.
	 *
	 * @return void
	 */
	public function testAMissingColumnSkipsTheUserStep(): void {
		$stats = $this->service(error: self::dbError(reason: DbException::REASON_INVALID_FIELD_NAME))->performUserSync();

		$this->assertSame(expected: ['skipped' => 'the contact person table has no e-mail or username column'], actual: $stats);
	}//end testAMissingColumnSkipsTheUserStep()

	/**
	 * Any other database failure still surfaces.
	 *
	 * @return void
	 */
	public function testAnotherDatabaseErrorIsRethrown(): void {
		$error = self::dbError(reason: DbException::REASON_CONNECTION_LOST);

		$this->expectExceptionObject(exception: $error);
		$this->service(error: $error)->performContactSync();
	}//end testAnotherDatabaseErrorIsRethrown()
}//end class
