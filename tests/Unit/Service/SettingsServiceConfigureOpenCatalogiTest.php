<?php

/**
 * Unit tests for SettingsService::configureOpenCatalogi(): pointing the
 * opencatalogi app at the theme schema of the publication register.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Which register and schema opencatalogi is configured with, and when it is left alone.
 */
#[CoversClass(SettingsService::class)]
final class SettingsServiceConfigureOpenCatalogiTest extends TestCase {

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $config;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Registers the register mapper returns.
	 *
	 * @var array<int, object>
	 */
	private array $registers = [];

	/**
	 * Schemas the schema mapper returns.
	 *
	 * @var array<int, object>
	 */
	private array $schemas = [];

	/**
	 * Row count per magic-mapper table name; a missing table throws.
	 *
	 * @var array<string, int>
	 */
	private array $tableCounts = [];

	/**
	 * SQL statements the connection received.
	 *
	 * @var array<int, string>
	 */
	private array $queries = [];

	/**
	 * Create the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An entity double exposing a slug and an id.
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 *
	 * @return object The entity.
	 */
	private function entity(int $id, string $slug): object {
		return new class($id, $slug) {
			/**
			 * @param int    $id   The id.
			 * @param string $slug The slug.
			 */
			public function __construct(private int $id, private string $slug) {
			}

			/**
			 * @return int The id.
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return string The slug.
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}//end entity()

	/**
	 * Build the service with a container serving the mapper and connection doubles.
	 *
	 * @param \Throwable|null $registerFailure Thrown by the register mapper when set.
	 *
	 * @return SettingsService
	 */
	private function service(?\Throwable $registerFailure = null): SettingsService {
		$registerMapper = new class($this->registers, $registerFailure) {
			/**
			 * @param array<int, object> $rows    The registers.
			 * @param \Throwable|null    $failure Thrown by findAll() when set.
			 */
			public function __construct(private array $rows, private ?\Throwable $failure) {
			}

			/**
			 * @return array<int, object> The registers.
			 */
			public function findAll(): array {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $this->rows;
			}
		};
		$schemaMapper = new class($this->schemas) {
			/**
			 * @param array<int, object> $rows The schemas.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @return array<int, object> The schemas.
			 */
			public function findAll(): array {
				return $this->rows;
			}
		};

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('executeQuery')->willReturnCallback(
			function (string $sql): IResult {
				$this->queries[] = $sql;
				foreach ($this->tableCounts as $table => $count) {
					if (str_contains($sql, ' FROM ' . $table . ' ') === true) {
						$result = $this->createMock(IResult::class);
						$result->method('fetch')->willReturn(['cnt' => (string) $count]);
						return $result;
					}
				}

				throw new \RuntimeException('Table not found');
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
				'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
				'OCP\IDBConnection' => $connection,
				default => throw new \RuntimeException("No {$id}"),
			}
		);

		return new SettingsService(
			catalogue: $this->createMock(CatalogueLocator::class),
			config: $this->config,
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			container: $container,
			appManager: $this->appManager,
			logger: $this->logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->createMock(EmailSettings::class)
		);
	}//end service()

	/**
	 * Without opencatalogi installed nothing is looked up or written.
	 *
	 * @return void
	 */
	public function testNotInstalledLeavesItAlone(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['files', 'openregister']);
		$this->config->expects($this->never())->method('setValueString');

		$result = $this->service()->configureOpenCatalogi();

		$this->assertSame(
			['success' => false, 'message' => 'OpenCatalogi app is not installed', 'configured' => []],
			$result
		);
		$this->assertSame([], $this->queries);
	}//end testNotInstalledLeavesItAlone()

	/**
	 * Without a register slugged `publication` nothing is written.
	 *
	 * @return void
	 */
	public function testMissingPublicationRegisterIsReported(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['opencatalogi']);
		$this->registers = [$this->entity(1, 'stackiq'), $this->entity(2, 'publications')];
		$this->config->expects($this->never())->method('setValueString');

		$result = $this->service()->configureOpenCatalogi();

		$this->assertSame('Publication register not found', $result['message']);
		$this->assertFalse($result['success']);
	}//end testMissingPublicationRegisterIsReported()

	/**
	 * The first theme schema whose magic-mapper table holds live rows is
	 * written as opencatalogi's theme schema and register; other slugs and
	 * empty tables are ignored.
	 *
	 * @return void
	 */
	public function testThemeSchemaWithDataIsConfigured(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['opencatalogi']);
		$this->registers = [$this->entity(3, 'other'), $this->entity(5, 'publication')];
		$this->schemas = [
			$this->entity(10, 'page'),
			$this->entity(11, 'theme'),
			$this->entity(12, 'theme'),
			$this->entity(13, 'theme'),
		];
		$this->tableCounts = [
			'oc_openregister_table_5_10' => 4,
			'oc_openregister_table_5_11' => 0,
			'oc_openregister_table_5_12' => 2,
			'oc_openregister_table_5_13' => 9,
		];

		$written = [];
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written): bool {
				$written[] = [$app, $key, $value];
				return true;
			}
		);
		$this->logger->expects($this->once())->method('info')->with(
			'OpenCatalogi configuration set',
			['theme_schema' => '12', 'theme_register' => '5']
		);

		$result = $this->service()->configureOpenCatalogi();

		$this->assertSame(
			[
				'success' => true,
				'message' => 'OpenCatalogi configured successfully',
				'configured' => ['theme_schema' => '12', 'theme_register' => '5'],
			],
			$result
		);
		$this->assertSame(
			[
				['opencatalogi', 'theme_schema', '12'],
				['opencatalogi', 'theme_register', '5'],
			],
			$written
		);
		// Only theme schemas are probed, and probing stops once one is found.
		$this->assertCount(2, $this->queries);
		$this->assertSame(
			'SELECT COUNT(*) as cnt FROM oc_openregister_table_5_11 WHERE _deleted IS NULL LIMIT 1',
			$this->queries[0]
		);
	}//end testThemeSchemaWithDataIsConfigured()

	/**
	 * A theme schema whose table is missing counts as empty, so nothing is configured.
	 *
	 * @return void
	 */
	public function testMissingTableMeansNothingIsConfigured(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['opencatalogi']);
		$this->registers = [$this->entity(5, 'publication')];
		$this->schemas = [$this->entity(11, 'theme')];
		$this->config->expects($this->never())->method('setValueString');

		$result = $this->service()->configureOpenCatalogi();

		$this->assertSame(
			['success' => false, 'message' => 'No page/menu/theme schemas with data found', 'configured' => []],
			$result
		);
		$this->assertCount(1, $this->queries);
	}//end testMissingTableMeansNothingIsConfigured()

	/**
	 * A failing lookup is caught, logged and reported in the message.
	 *
	 * @return void
	 */
	public function testLookupFailureIsReported(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['opencatalogi']);
		$this->logger->expects($this->once())->method('error')->with(
			'OpenCatalogi configuration failed',
			['exception' => 'mapper down']
		);

		$result = $this->service(registerFailure: new \RuntimeException('mapper down'))->configureOpenCatalogi();

		$this->assertSame(
			['success' => false, 'message' => 'Failed to configure OpenCatalogi: mapper down', 'configured' => []],
			$result
		);
	}//end testLookupFailureIsReported()
}//end class
