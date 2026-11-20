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
	private function reader(
		array $bySchema,
		array $config = ['register' => 7, 'koppeling_schema' => 21, 'module_schema' => 22, 'dienst_schema' => 23],
		bool $fails = false
	): PublishedAanbod {
		$settings = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn($config);

		$test = $this;
		$objectService = new class($test, $bySchema, $fails) {
			/**
			 * The double over the given entries.
			 *
			 * @param PublishedAanbodTest                           $test     The test that records each search.
			 * @param array<int|string, list<array<string, mixed>>> $bySchema Entries per schema id.
			 * @param bool                                          $fails    Whether every search throws.
			 */
			public function __construct(private PublishedAanbodTest $test, private array $bySchema, private bool $fails) {
			}

			/**
			 * Answer one search from the entries of its schema.
			 *
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         The _rbac flag.
			 * @param bool                 $_multitenancy The _multitenancy flag.
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

		$container = $this->createMock(originalClassName: ContainerInterface::class);
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
		return [
			'id'              => $id,
			'name'            => ucfirst(string: $id),
			'publicationDate' => '2020-01-01T00:00:00+00:00',
			'@self'           => ['id' => $id, 'organisation' => 'org-1'],
		];

	}//end published()

	/**
	 * Every schema is read with RBAC on and no organisation filter.
	 *
	 * @return void
	 */
	public function testEverySchemaIsReadWithRbacOnAndNoOrganisationFilter(): void {
		$this->reader(bySchema: [])->page();

		$schemas = array_map(callback: static fn (array $q): int => $q['@self']['schema'], array: $this->queries);
		self::assertSame(expected: [21, 22, 23], actual: $schemas);
		foreach ($this->queries as $query) {
			self::assertSame(expected: ['@self', '_limit'], actual: array_keys(array: $query));
			self::assertSame(expected: PublishedAanbod::LIMIT, actual: $query['_limit']);
		}

		self::assertSame(expected: [[true, false], [true, false], [true, false]], actual: $this->flags);

	}//end testEverySchemaIsReadWithRbacOnAndNoOrganisationFilter()

	/**
	 * Only published entries are listed, each typed by its schema.
	 *
	 * @return void
	 */
	public function testOnlyPublishedEntriesAreListedAndTypedBySchema(): void {
		$page = $this->reader(bySchema: [
			21 => [self::published(id: 'koppeling-a'), ['id' => 'draft', 'name' => 'Draft']],
			22 => [self::published(id: 'module-b'), ['id' => 'future', 'publicationDate' => '2999-01-01T00:00:00+00:00']],
			23 => [self::published(id: 'dienst-c')],
		])->page();

		self::assertSame(expected: 3, actual: $page['total']);
		self::assertSame(expected: ['koppeling-a', 'module-b', 'dienst-c'], actual: array_column(array: $page['results'], column_key: 'id'));
		self::assertSame(expected: ['connection', 'module', 'catalogService'], actual: array_column(array: $page['results'], column_key: '_aanbod_type'));

	}//end testOnlyPublishedEntriesAreListedAndTypedBySchema()

	/**
	 * Published means the publication date has come and no depublication date has.
	 *
	 * @return void
	 */
	public function testPublishedMeansTheDateHasComeAndNoDepublicationHas(): void {
		$reader = $this->reader(bySchema: []);
		$now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

		self::assertTrue(condition: $reader->isPublished(entry: ['publicationDate' => '2026-11-17T12:00:00+00:00'], now: $now));
		$open = ['publicationDate' => '2026-11-01T00:00:00+00:00', 'depublicationDate' => '2026-12-01T00:00:00+00:00'];
		self::assertTrue(condition: $reader->isPublished(entry: $open, now: $now));
		$closed = ['publicationDate' => '2026-11-01T00:00:00+00:00', 'depublicationDate' => '2026-11-17T12:00:00+00:00'];
		self::assertFalse(condition: $reader->isPublished(entry: $closed, now: $now));
		self::assertFalse(condition: $reader->isPublished(entry: ['publicationDate' => '2026-11-18T00:00:00+00:00'], now: $now));
		self::assertFalse(condition: $reader->isPublished(entry: ['publicationDate' => 'not a date'], now: $now));
		self::assertFalse(condition: $reader->isPublished(entry: [], now: $now));

	}//end testPublishedMeansTheDateHasComeAndNoDepublicationHas()

	/**
	 * The projection drops @self and the internal keys.
	 *
	 * @return void
	 */
	public function testTheProjectionDropsSelfAndInternalKeys(): void {
		$projected = $this->reader(bySchema: [])->project(
			entry: [
				'name'          => 'X',
				'@self'         => ['id' => 'x-1', 'organisation' => 'org-1', 'owner' => 'alice'],
				'_organisation' => 'org-1',
				'licence'       => 'EUPL-1.2',
			],
			type: 'module'
		);

		self::assertSame(expected: ['name' => 'X', 'licence' => 'EUPL-1.2', 'id' => 'x-1', '_aanbod_type' => 'module'], actual: $projected);

	}//end testTheProjectionDropsSelfAndInternalKeys()

	/**
	 * The entries are paged.
	 *
	 * @return void
	 */
	public function testTheEntriesArePaged(): void {
		$entries = array_map(callback: static fn (int $i): array => self::published(id: 'm' . $i), array: range(start: 1, end: 5));
		$reader = $this->reader(bySchema: [22 => $entries]);

		$second = $reader->page(options: ['_limit' => 2, '_page' => 2]);
		self::assertSame(expected: ['m3', 'm4'], actual: array_column(array: $second['results'], column_key: 'id'));
		$envelope = array_diff_key($second, ['results' => true]);
		self::assertSame(expected: ['total' => 5, 'page' => 2, 'pages' => 3, 'limit' => 2, 'offset' => 2], actual: $envelope);

		$offset = $reader->page(options: ['limit' => 3, 'offset' => 4]);
		self::assertSame(expected: ['m5'], actual: array_column(array: $offset['results'], column_key: 'id'));
		self::assertSame(expected: 20, actual: $reader->page(options: ['_limit' => 0])['limit']);

	}//end testTheEntriesArePaged()

	/**
	 * An unconfigured register answers the empty envelope without reading.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterAnswersTheEmptyEnvelopeWithoutReading(): void {
		$page = $this->reader(bySchema: [], config: [])->page();

		$empty = ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'limit' => 20, 'offset' => 0];
		self::assertSame(expected: $empty, actual: $page);
		self::assertSame(expected: [], actual: $this->queries);

	}//end testAnUnconfiguredRegisterAnswersTheEmptyEnvelopeWithoutReading()

	/**
	 * A failed read lists nothing.
	 *
	 * @return void
	 */
	public function testAFailedReadListsNothing(): void {
		$page = $this->reader(bySchema: [], fails: true)->page();

		self::assertSame(expected: [], actual: $page['results']);
		self::assertSame(expected: 0, actual: $page['total']);
		self::assertCount(expectedCount: 3, haystack: $this->queries);

	}//end testAFailedReadListsNothing()
}//end class
