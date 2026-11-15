<?php

/**
 * Tests for PeerUsageService: what comparable organisations use.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use Exception;
use OCA\Stackiq\Service\PeerUsageService;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the comparable set (same type and inhabitants band, or the same type
 * without a value), the counts of distinct organisations per application per
 * reference component, the "fewer than 3" rule, and that no other
 * organisation's id or name is in the answer.
 *
 * @covers \OCA\Stackiq\Service\PeerUsageService
 * @uses   \OCA\Stackiq\Service\PortfolioReportDerivation
 */
final class PeerUsageServiceTest extends TestCase {

	private const CONFIG = ['register' => 5, 'gebruik_schema' => 6, 'module_schema' => 7, 'organisatie_schema' => 8];

	/**
	 * Every query the service sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Zuiddrecht (45,000) and its peers: 21 in its band using OpenZaak-UI, one using Djuma.
	 *
	 * @return void
	 */
	public function testAMunicipalityComparesWithItsBand(): void {
		$organisations = [['id' => 'zuiddrecht', 'type' => 'Gemeente', 'inhabitants' => 45000, 'name' => 'Zuiddrecht']];
		$usages = [['consumer' => 'zuiddrecht', 'module' => 'mozard']];
		for ($i = 1; $i <= 21; $i++) {
			$organisations[] = ['id' => 'peer-' . $i, 'type' => 'Gemeente', 'inhabitants' => 30000 + $i, 'name' => 'Peer ' . $i];
			$usages[] = ['consumer' => 'peer-' . $i, 'module' => 'openzaak'];
			// A second usage of the same application by the same organisation counts once.
			$usages[] = ['consumer' => 'peer-' . $i, 'module' => 'openzaak'];
		}

		$usages[] = ['consumer' => 'peer-1', 'module' => 'djuma'];
		$organisations[] = ['id' => 'big', 'type' => 'Gemeente', 'inhabitants' => 300000, 'name' => 'Big city'];
		$organisations[] = ['id' => 'unknown-size', 'type' => 'Gemeente', 'name' => 'No value'];
		$organisations[] = ['id' => 'vendor', 'type' => 'Leverancier', 'inhabitants' => 45000, 'name' => 'Vendor'];
		$modules = [
			['id' => 'openzaak', 'name' => 'OpenZaak-UI', 'referenceComponents' => ['zac']],
			['id' => 'djuma', 'name' => 'Djuma Kennisbank', 'referenceComponents' => [['id' => 'kennisbank']]],
		];

		$answer = $this->service(organisations: $organisations, usages: $usages, modules: $modules)->build(organisationUuid: 'zuiddrecht');

		self::assertSame(['type' => 'Gemeente', 'band' => ['min' => 30000, 'max' => 60000], 'organisations' => 21], $answer['comparable']);
		self::assertSame(
			[
				['referenceComponent' => 'zac', 'applications' => [['moduleId' => 'openzaak', 'moduleName' => 'OpenZaak-UI', 'count' => 21]]],
				['referenceComponent' => 'kennisbank', 'applications' => [['moduleId' => 'djuma', 'moduleName' => 'Djuma Kennisbank', 'count' => null, 'fewerThan' => 3]]],
			],
			$answer['components']
		);
		self::assertFalse($answer['truncated']);

		$encoded = (string)json_encode($answer);
		self::assertStringNotContainsString('peer-', $encoded);
		self::assertStringNotContainsString('Peer ', $encoded);
		self::assertStringNotContainsString('Big city', $encoded);

		$usageQuery = $this->queryFor(schema: 6);
		self::assertNotContains('zuiddrecht', $usageQuery['consumer']);
		self::assertNotContains('big', $usageQuery['consumer']);
		self::assertNotContains('unknown-size', $usageQuery['consumer']);
		self::assertNotContains('vendor', $usageQuery['consumer']);
	}//end testAMunicipalityComparesWithItsBand()

	/**
	 * Without an inhabitants value, comparable is the same type in any band.
	 *
	 * @return void
	 */
	public function testWithoutInhabitantsComparableIsTheSameType(): void {
		$organisations = [
			['id' => 'own', 'type' => 'Gemeente'],
			['id' => 'a', 'type' => 'Gemeente', 'inhabitants' => 10000],
			['id' => 'b', 'type' => 'Gemeente', 'inhabitants' => 900000],
			['id' => 'c', 'type' => 'Gemeente'],
			['id' => 'v', 'type' => 'Leverancier'],
		];
		$usages = [['consumer' => 'a', 'module' => 'm'], ['consumer' => 'b', 'module' => 'm'], ['consumer' => 'c', 'module' => 'm']];

		$answer = $this->service(organisations: $organisations, usages: $usages, modules: [['id' => 'm', 'name' => 'App', 'referenceComponents' => ['rc']]])->build(organisationUuid: 'own');

		self::assertNull($answer['comparable']['band']);
		self::assertSame(3, $answer['comparable']['organisations']);
		self::assertSame(3, $answer['components'][0]['applications'][0]['count']);
	}//end testWithoutInhabitantsComparableIsTheSameType()

	/**
	 * An organisation without a type has no peers and no usage is read.
	 *
	 * @return void
	 */
	public function testWithoutATypeNothingIsComparable(): void {
		$answer = $this->service(organisations: [['id' => 'own']], usages: [['consumer' => 'x', 'module' => 'm']], modules: [])->build(organisationUuid: 'own');

		self::assertSame(0, $answer['comparable']['organisations']);
		self::assertSame([], $answer['components']);
		self::assertNull($this->queryFor(schema: 6));
	}//end testWithoutATypeNothingIsComparable()

	/**
	 * A total above the page says truncated; the service identity is used.
	 *
	 * @return void
	 */
	public function testAPartialPageIsTruncated(): void {
		$answer = $this->service(
			organisations: [['id' => 'own', 'type' => 'Gemeente'], ['id' => 'a', 'type' => 'Gemeente']],
			usages: [['consumer' => 'a', 'module' => 'm']],
			modules: [['id' => 'm', 'referenceComponents' => ['rc']]],
			usageTotal: 9000
		)->build(organisationUuid: 'own');

		self::assertTrue($answer['truncated']);
		self::assertSame(500, $this->queryFor(schema: 6)['_limit']);
	}//end testAPartialPageIsTruncated()

	/**
	 * Missing OpenRegister or configuration is an error, before any query.
	 *
	 * @return void
	 */
	public function testMissingConfigurationIsRefused(): void {
		foreach ([['openregister'], []] as $apps) {
			$config = self::CONFIG;
			if ($apps !== []) {
				unset($config['organisatie_schema']);
			}

			try {
				$this->service(organisations: [], usages: [], modules: [], apps: $apps, config: $config)->build(organisationUuid: 'own');
				self::fail('No exception.');
			} catch (Exception $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}

		self::assertSame([], $this->queries);
	}//end testMissingConfigurationIsRefused()

	/**
	 * The bands of REQ-GPL-002.
	 *
	 * @return void
	 */
	public function testTheBands(): void {
		self::assertSame(['min' => 0, 'max' => 30000], PeerUsageService::band(inhabitants: 29999));
		self::assertSame(['min' => 30000, 'max' => 60000], PeerUsageService::band(inhabitants: 30000));
		self::assertSame(['min' => 60000, 'max' => 100000], PeerUsageService::band(inhabitants: '60000'));
		self::assertSame(['min' => 100000, 'max' => 250000], PeerUsageService::band(inhabitants: 249999));
		self::assertSame(['min' => 250000, 'max' => null], PeerUsageService::band(inhabitants: 250000));
		self::assertNull(PeerUsageService::band(inhabitants: null));
		self::assertNull(PeerUsageService::band(inhabitants: -5));
		self::assertNull(PeerUsageService::band(inhabitants: 'many'));
	}//end testTheBands()

	/**
	 * The query sent for a schema id, or null.
	 *
	 * @param int $schema The schema id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function queryFor(int $schema): ?array {
		foreach ($this->queries as $query) {
			if ($query['@self']['schema'] === $schema) {
				return $query;
			}
		}

		return null;
	}//end queryFor()

	/**
	 * The service over a fake object service that filters like OpenRegister would.
	 *
	 * @param array<int, array<string, mixed>> $organisations Organisations.
	 * @param array<int, array<string, mixed>> $usages        Usages.
	 * @param array<int, array<string, mixed>> $modules       Modules.
	 * @param int|null                         $usageTotal    The usage total to report.
	 * @param array<int, string>               $apps          Installed apps.
	 * @param array<string, int>               $config        The voorzieningen configuration.
	 *
	 * @return PeerUsageService
	 */
	private function service(array $organisations, array $usages, array $modules, ?int $usageTotal = null, array $apps = ['openregister'], array $config = self::CONFIG): PeerUsageService {
		$this->queries = [];
		$test = $this;
		$objects = new class($organisations, $usages, $modules, $usageTotal, $test) {
			/**
			 * Constructor.
			 *
			 * @param array                $organisations Organisations.
			 * @param array                $usages        Usages.
			 * @param array                $modules       Modules.
			 * @param int|null             $usageTotal    The usage total.
			 * @param PeerUsageServiceTest $test          Records the queries.
			 */
			public function __construct(private array $organisations, private array $usages, private array $modules, private ?int $usageTotal, private PeerUsageServiceTest $test) {
			}

			/**
			 * Filter the fake data like the query asks.
			 *
			 * @param array $query         The query.
			 * @param bool  $_rbac         Must be false.
			 * @param bool  $_multitenancy Must be false.
			 *
			 * @return array
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->record(query: $query + ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy]);
				$rows = [8 => $this->organisations, 6 => $this->usages, 7 => $this->modules][$query['@self']['schema']];
				foreach (['id', 'type', 'consumer'] as $key) {
					if (isset($query[$key]) === true) {
						$wanted = (array)$query[$key];
						$rows = array_values(array_filter($rows, static fn (array $row): bool => in_array(($row[$key] ?? null), $wanted, true)));
					}
				}

				$total = count($rows);
				if ($query['@self']['schema'] === 6 && $this->usageTotal !== null) {
					$total = $this->usageTotal;
				}

				return ['results' => $rows, 'total' => $total];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($apps);
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn($config);

		return new PeerUsageService(
			settingsService: $settings,
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			relations: new PortfolioReportDerivation()
		);
	}//end service()

	/**
	 * Record a query, asserting it ran with the service identity.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return void
	 */
	public function record(array $query): void {
		self::assertFalse($query['_rbac']);
		self::assertFalse($query['_multitenancy']);
		$this->queries[] = $query;
	}//end record()
}//end class
