<?php

/**
 * Golden test for OrganizationSyncService::performUserSync.
 *
 * Pins, per case, the contact table the user sync queries, the query it
 * builds on each database platform, which contacts are added to which
 * organisation (and when none are), and every log line, in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organization-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

if (class_exists('Doctrine\\DBAL\\Platforms\\PostgreSQLPlatform') === false) {
	require_once __DIR__ . '/../../Stubs/Doctrine/PostgreSQLPlatform.php';
}

/**
 * Golden cases for adding contact person accounts to their organisation.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
class OrganizationUserSyncTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Per-case behaviour of the doubles.
	 *
	 * @var array<string, mixed>
	 */
	private array $plan = [];

	/**
	 * The default plan, overridden per case.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaultPlan(): array {
		return [
			'config'   => ['register' => '3', 'contactpersoon_schema' => '9'],
			'postgres' => false,
			'rows'     => [],
			'refuse'   => [],
		];

	}//end defaultPlan()

	/**
	 * The database double: a query builder that records every call.
	 *
	 * @return IDBConnection
	 */
	private function database(): IDBConnection {
		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetchAll')->willReturnCallback(
			function () {
				$this->trace[] = ['fetchAll'];
				return $this->plan['rows'];
			}
		);

		$query = $this->createMock(originalClassName: IQueryBuilder::class);
		foreach (['select', 'from', 'leftJoin', 'where', 'andWhere'] as $method) {
			$query->method($method)->willReturnCallback(
				function (...$arguments) use ($method, &$query) {
					$this->trace[] = array_merge([$method], array_map(fn ($argument) => is_object($argument) === true ? (string) $argument : $argument, $arguments));
					return $query;
				}
			);
		}

		$query->method('createFunction')->willReturnCallback(
			function (string $call) {
				$this->trace[] = ['createFunction', $call];
				$function = $this->createMock(originalClassName: IQueryFunction::class);
				$function->method('__toString')->willReturn('fn(' . $call . ')');
				return $function;
			}
		);
		$query->method('createNamedParameter')->willReturnCallback(
			function ($value) {
				$this->trace[] = ['createNamedParameter', $value];
				return ':param';
			}
		);
		$query->method('executeQuery')->willReturnCallback(
			function () use ($result) {
				$this->trace[] = ['executeQuery'];
				return $result;
			}
		);

		$database = $this->createMock(originalClassName: IDBConnection::class);
		$database->method('getDatabasePlatform')->willReturnCallback(
			function () {
				$this->trace[] = ['getDatabasePlatform'];
				if ($this->plan['postgres'] === true) {
					return new \Doctrine\DBAL\Platforms\PostgreSQLPlatform();
				}

				return new \stdClass();
			}
		);
		$database->method('getQueryBuilder')->willReturnCallback(
			function () use ($query) {
				$this->trace[] = ['getQueryBuilder'];
				return $query;
			}
		);

		return $database;

	}//end database()

	/**
	 * Run the user sync.
	 *
	 * @param array $plan Overrides of the default plan.
	 *
	 * @return array{0: array, 1: array}
	 */
	private function runCase(array $plan): array {
		$this->trace = [];
		$this->plan  = array_merge(self::defaultPlan(), $plan);

		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function () {
				$this->trace[] = ['getVoorzieningenConfig'];
				return $this->plan['config'];
			}
		);

		$organisations = $this->createMock(originalClassName: OrganisatieService::class);
		$organisations->method('addUsersToOrganization')->willReturnCallback(
			function (string $organisation, array $usernames) {
				$this->trace[] = ['addUsersToOrganization', $organisation, $usernames];
				if (in_array($organisation, $this->plan['refuse'], true) === true) {
					throw new \RuntimeException('org refused');
				}

				return true;
			}
		);

		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$properties = [
			'logger'              => $logger,
			'settingsService'     => $settings,
			'organisationService' => $organisations,
			'db'                  => $this->database(),
		];
		foreach ($properties as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		$result = $service->performUserSync();
		return [$result, $this->trace];

	}//end runCase()

	/**
	 * The query on the contact table of register 3, schema 9.
	 *
	 * @param string $jsonCheck The platform's "username not in users" check.
	 *
	 * @return array
	 */
	private static function query(string $jsonCheck): array {
		return [
			['getVoorzieningenConfig'],
			['getDatabasePlatform'],
			['getQueryBuilder'],
			['select', 'o._uuid as uuid', 'o.username', 'o.organization', 'oo.users'],
			['from', 'openregister_table_3_9', 'o'],
			['leftJoin', 'o', 'openregister_organisations', 'oo', 'oo.uuid = o.organization'],
			['createFunction', 'o.username IS NOT NULL'],
			['where', 'fn(o.username IS NOT NULL)'],
			['createNamedParameter', ''],
			['createFunction', 'o.username <> :param'],
			['andWhere', 'fn(o.username <> :param)'],
			['createFunction', 'o.organization IS NOT NULL'],
			['andWhere', 'fn(o.organization IS NOT NULL)'],
			['createFunction', $jsonCheck],
			['andWhere', 'fn(' . $jsonCheck . ')'],
			['executeQuery'],
			['fetchAll'],
		];

	}//end query()

	/**
	 * Every contact whose username is missing from its organisation is added, a failure is logged and skipped.
	 *
	 * @return void
	 */
	public function testMissingUsersAreAddedToTheirOrganisation(): void {
		[$result, $trace] = $this->runCase(
			plan: [
				'rows'   => [
					['uuid' => 'c1', 'username' => 'alice', 'organization' => 'org-1', 'users' => '[]'],
					['uuid' => 'c2', 'username' => 'bob', 'organization' => 'org-2', 'users' => '["x"]'],
					['uuid' => 'c3', 'username' => 'carol', 'organization' => 'org-1', 'users' => '[]'],
				],
				'refuse' => ['org-2'],
			]
		);

		$this->assertSame([], $result);
		$this->assertSame(
			array_merge(
				self::query(jsonCheck: "JSON_CONTAINS(oo.users, CONCAT('\"', o.username, '\"')) = 0"),
				[
					['log', 'info', 'UserSync: adding 3 users to their org entities', []],
					['addUsersToOrganization', 'org-1', ['alice']],
					['addUsersToOrganization', 'org-2', ['bob']],
					['log', 'error', 'UserSync: failed to add user bob to org org-2', ['error' => 'org refused']],
					['addUsersToOrganization', 'org-1', ['carol']],
				]
			),
			$trace
		);

	}//end testMissingUsersAreAddedToTheirOrganisation()

	/**
	 * PostgreSQL uses a jsonb containment check; without rows nobody is added and nothing is logged.
	 *
	 * @return void
	 */
	public function testPostgresQueryWithoutRowsAddsNobody(): void {
		[$result, $trace] = $this->runCase(plan: ['postgres' => true]);

		$this->assertSame([], $result);
		$this->assertSame(self::query(jsonCheck: 'NOT (oo.users::jsonb @> to_jsonb(o.username::text))'), $trace);

	}//end testPostgresQueryWithoutRowsAddsNobody()

	/**
	 * A missing or non-positive register or contact schema stops the sync before any query.
	 *
	 * @return void
	 */
	public function testInvalidConfigurationStopsBeforeQuerying(): void {
		$missing = ['log', 'warning', 'UserSync: voorzieningen config missing register or contactpersoon_schema', []];
		foreach ([['register' => '3'], ['contactpersoon_schema' => '9'], ['register' => '0', 'contactpersoon_schema' => '9']] as $config) {
			[$result, $trace] = $this->runCase(plan: ['config' => $config]);
			$this->assertSame([], $result);
			$this->assertSame([['getVoorzieningenConfig'], $missing], $trace);
		}

		foreach ([['register' => 'abc', 'contactpersoon_schema' => '9'], ['register' => '-3', 'contactpersoon_schema' => '9'], ['register' => '3', 'contactpersoon_schema' => 'abc'], ['register' => '3; DROP', 'contactpersoon_schema' => '-1']] as $config) {
			[$result, $trace] = $this->runCase(plan: ['config' => $config]);
			$this->assertSame([], $result);
			$this->assertSame(
				[
					['getVoorzieningenConfig'],
					[
						'log',
						'warning',
						'UserSync: register or contactpersoon_schema is not a valid positive integer',
						['register' => $config['register'], 'contactSchema' => $config['contactpersoon_schema']],
					],
				],
				$trace
			);
		}

	}//end testInvalidConfigurationStopsBeforeQuerying()
}//end class
