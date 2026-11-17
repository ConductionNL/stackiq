<?php

/**
 * Unit tests for PublishedAanbod: what an anonymous caller reads from GET /api/aanbod.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Stackiq\Service\PublishedAanbod;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for PublishedAanbod.
 *
 * @spec openspec/changes/anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
 */
final class PublishedAanbodTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $queries = [];

	/** @var array<int, array{0: bool, 1: bool}> */
	private array $flags = [];

	/**
	 * The reader over a register with a koppeling, module and dienst schema.
	 *
	 * @param array<int|string, list<array<string, mixed>>> $bySchema Entries per schema id.
	 * @param array<string, mixed>                          $config   The voorzieningen config.
	 * @param bool                                          $fails    Whether every search throws.
	 *
	 * @return PublishedAanbod
	 */
	private function reader(array $bySchema, array $config = ['register' => 7, 'koppeling_schema' => 21, 'module_schema' => 22, 'dienst_schema' => 23], bool $fails = false): PublishedAanbod {
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn($config);

		$test = $this;
		$objectService = new class($test, $bySchema, $fails) {
			/**
			 * @param array<int|string, list<array<string, mixed>>> $bySchema
			 */
			public function __construct(private PublishedAanbodTest $test, private array $bySchema, private bool $fails) {
			}

			/**
			 * @param array<string, mixed> $query
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->record(query: $query, rbac: $_rbac, multitenancy: $_multitenancy);
				if ($this->fails === true) {
					throw new RuntimeException('down');
				}

				return ['results' => $this->bySchema[$query['@self']['schema']] ?? []];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new PublishedAanbod(settingsService: $settings, container: $container, logger: new NullLogger());

	}//end reader()

	/**
	 * Record one search the double received.
	 *
	 * @param array<string, mixed> $query        The query.
	 * @param bool                 $rbac         The _rbac flag.
	 * @param bool                 $multitenancy The _multitenancy flag.
	 *
	 * @return void
	 */
	public function record(array $query, bool $rbac, bool $multitenancy): void {
		$this->queries[] = $query;
		$this->flags[] = [$rbac, $multitenancy];

	}//end record()

	/**
	 * A published entry.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>
	 */
	private static function published(string $id): array {
		return ['id' => $id, 'name' => ucfirst($id), 'publicationDate' => '2020-01-01T00:00:00+00:00', '@self' => ['id' => $id, 'organisation' => 'org-1']];

	}//end published()

	public function testEverySchemaIsReadWithRbacOnAndNoOrganisationFilter(): void {
		$this->reader(bySchema: [])->page();

		self::assertSame([21, 22, 23], array_map(static fn (array $q): int => $q['@self']['schema'], $this->queries));
		foreach ($this->queries as $query) {
			self::assertSame(['@self', '_limit'], array_keys($query));
			self::assertSame(PublishedAanbod::LIMIT, $query['_limit']);
		}

		self::assertSame([[true, false], [true, false], [true, false]], $this->flags);

	}//end testEverySchemaIsReadWithRbacOnAndNoOrganisationFilter()

	public function testOnlyPublishedEntriesAreListedAndTypedBySchema(): void {
		$page = $this->reader(bySchema: [
			21 => [self::published('koppeling-a'), ['id' => 'draft', 'name' => 'Draft']],
			22 => [self::published('module-b'), ['id' => 'future', 'publicationDate' => '2999-01-01T00:00:00+00:00']],
			23 => [self::published('dienst-c')],
		])->page();

		self::assertSame(3, $page['total']);
		self::assertSame(['koppeling-a', 'module-b', 'dienst-c'], array_column($page['results'], 'id'));
		self::assertSame(['connection', 'module', 'catalogService'], array_column($page['results'], '_aanbod_type'));

	}//end testOnlyPublishedEntriesAreListedAndTypedBySchema()

	public function testPublishedMeansTheDateHasComeAndNoDepublicationHas(): void {
		$reader = $this->reader(bySchema: []);
		$now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

		self::assertTrue($reader->isPublished(entry: ['publicationDate' => '2026-11-17T12:00:00+00:00'], now: $now));
		self::assertTrue($reader->isPublished(entry: ['publicationDate' => '2026-11-01T00:00:00+00:00', 'depublicationDate' => '2026-12-01T00:00:00+00:00'], now: $now));
		self::assertFalse($reader->isPublished(entry: ['publicationDate' => '2026-11-01T00:00:00+00:00', 'depublicationDate' => '2026-11-17T12:00:00+00:00'], now: $now));
		self::assertFalse($reader->isPublished(entry: ['publicationDate' => '2026-11-18T00:00:00+00:00'], now: $now));
		self::assertFalse($reader->isPublished(entry: ['publicationDate' => 'not a date'], now: $now));
		self::assertFalse($reader->isPublished(entry: [], now: $now));

	}//end testPublishedMeansTheDateHasComeAndNoDepublicationHas()

	public function testTheProjectionDropsSelfAndInternalKeys(): void {
		$projected = $this->reader(bySchema: [])->project(
			entry: ['name' => 'X', '@self' => ['id' => 'x-1', 'organisation' => 'org-1', 'owner' => 'alice'], '_organisation' => 'org-1', 'licence' => 'EUPL-1.2'],
			type: 'module'
		);

		self::assertSame(['name' => 'X', 'licence' => 'EUPL-1.2', 'id' => 'x-1', '_aanbod_type' => 'module'], $projected);

	}//end testTheProjectionDropsSelfAndInternalKeys()

	public function testTheEntriesArePaged(): void {
		$entries = array_map(static fn (int $i): array => self::published('m' . $i), range(1, 5));
		$reader = $this->reader(bySchema: [22 => $entries]);

		$second = $reader->page(options: ['_limit' => 2, '_page' => 2]);
		self::assertSame(['m3', 'm4'], array_column($second['results'], 'id'));
		self::assertSame(['total' => 5, 'page' => 2, 'pages' => 3, 'limit' => 2, 'offset' => 2], array_diff_key($second, ['results' => true]));

		$offset = $reader->page(options: ['limit' => 3, 'offset' => 4]);
		self::assertSame(['m5'], array_column($offset['results'], 'id'));
		self::assertSame(20, $reader->page(options: ['_limit' => 0])['limit']);

	}//end testTheEntriesArePaged()

	public function testAnUnconfiguredRegisterAnswersTheEmptyEnvelopeWithoutReading(): void {
		$page = $this->reader(bySchema: [], config: [])->page();

		self::assertSame(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'limit' => 20, 'offset' => 0], $page);
		self::assertSame([], $this->queries);

	}//end testAnUnconfiguredRegisterAnswersTheEmptyEnvelopeWithoutReading()

	public function testAFailedReadListsNothing(): void {
		$page = $this->reader(bySchema: [], fails: true)->page();

		self::assertSame([], $page['results']);
		self::assertSame(0, $page['total']);
		self::assertCount(3, $this->queries);

	}//end testAFailedReadListsNothing()
}//end class
