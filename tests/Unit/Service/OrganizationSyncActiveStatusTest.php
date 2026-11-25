<?php

/**
 * Unit tests for the organisation batch of the sync against the current schema.
 *
 * The organization schema stores the English status `Active`. The scheduled
 * sync matched only `actief`, so it never found an organisation to give its
 * OpenRegister organisation.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/connections-derived-dependencies/tasks.md#task-4-suggested-connections-panel
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cases for the organisation batch query.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 *
 * @spec openspec/changes/connections-derived-dependencies/tasks.md#task-4-suggested-connections-panel
 */
class OrganizationSyncActiveStatusTest extends TestCase {

	/**
	 * The database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Every named parameter the queries created, as [value, type].
	 *
	 * @var list<array{0: mixed, 1: mixed}>
	 */
	private array $parameters = [];

	/**
	 * Every SQL function the queries created.
	 *
	 * @var list<string>
	 */
	private array $functions = [];

	/**
	 * Every andWhere() condition given as a string.
	 *
	 * @var list<string>
	 */
	private array $conditions = [];

	/**
	 * The service under test.
	 *
	 * @var OrganizationSyncService
	 */
	private OrganizationSyncService $service;

	/**
	 * Build the service on register 26 and organisation schema 46.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn(
			['register' => '26', 'organisatie_schema' => '46', 'contactpersoon_schema' => '45']
		);
		$this->db = $this->createMock(IDBConnection::class);

		$this->service = new OrganizationSyncService(
			$this->createMock(OrganisatieService::class),
			$this->createMock(ContactpersoonService::class),
			$this->createMock(SymfonyEmailService::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$settings,
			$this->db,
			$this->createMock(ContactPersonHandler::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(OrganisationMapper::class),
		);

	}//end setUp()

	/**
	 * A query builder that records its parameters, functions and conditions
	 * and answers no rows.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function recordingQuery(): IQueryBuilder&MockObject {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$result->method('fetchAll')->willReturn([]);

		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'leftJoin', 'innerJoin', 'where', 'orderBy', 'setMaxResults'] as $method) {
			$query->method($method)->willReturnSelf();
		}

		$query->method('andWhere')->willReturnCallback(
			function (mixed ...$conditions) use ($query): IQueryBuilder {
				foreach ($conditions as $condition) {
					if (is_string($condition) === true) {
						$this->conditions[] = $condition;
					}
				}

				return $query;
			}
		);
		$query->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): string {
				$this->parameters[] = [$value, $type];
				return ':p' . count($this->parameters);
			}
		);
		$query->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$this->functions[] = $sql;
				return $this->createMock(IQueryFunction::class);
			}
		);
		$query->method('executeQuery')->willReturn($result);
		return $query;

	}//end recordingQuery()

	/**
	 * The organisation batch counts `Active` (and the old `actief`) as active,
	 * and leaves deleted rows out.
	 *
	 * @return void
	 */
	public function testTheOrganisationBatchMatchesTheEnglishStatusAndSkipsDeletedRows(): void {
		$this->db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingQuery());

		$stats = $this->service->performOrganizationsSync();

		$this->assertSame(0, $stats['totalRemaining']);
		$this->assertContains([['active', 'actief'], IQueryBuilder::PARAM_STR_ARRAY], $this->parameters);
		$this->assertContains('LOWER(o.status) IN (:p1)', $this->functions);
		$this->assertContains('o._deleted IS NULL', $this->conditions);
		$this->assertNotContains(['actief', IQueryBuilder::PARAM_STR], $this->parameters, 'actief alone matched no current organisation');

	}//end testTheOrganisationBatchMatchesTheEnglishStatusAndSkipsDeletedRows()

}//end class
