<?php

/**
 * Entry references tests
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\EntryType
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\EntryType;

use OCA\Stackiq\Service\EntryType\EntryReferences;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A schema or register, shaped like OpenRegister's entities.
 */
class EntryReferencesFakeSchema {

	/**
	 * Constructor.
	 *
	 * @param int $id The id.
	 * @param string $slug The slug.
	 * @param array<string, mixed> $properties The properties.
	 * @param string $uuid The uuid.
	 * @param array<int, int> $schemas The schemas, when this is a register.
	 */
	public function __construct(
		private int $id,
		private string $slug,
		private array $properties = [],
		private string $uuid = '',
		private array $schemas = [],
	) {
	}//end __construct()

	public function getId(): int {
		return $this->id;
	}//end getId()

	public function getSlug(): string {
		return $this->slug;
	}//end getSlug()

	public function getUuid(): string {
		return $this->uuid;
	}//end getUuid()

	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()

	public function getSchemas(): array {
		return $this->schemas;
	}//end getSchemas()
}//end class

/**
 * A mapper that finds by id.
 */
class EntryReferencesFakeMapper {

	/**
	 * Constructor.
	 *
	 * @param array<int, object> $rows The rows by id.
	 */
	public function __construct(private array $rows) {
	}//end __construct()

	public function find(int $id): object {
		return $this->rows[$id] ?? throw new RuntimeException('not found: ' . $id);
	}//end find()
}//end class

/**
 * OpenRegister's ObjectService, answering one search per schema id.
 */
class EntryReferencesFakeObjects {

	/**
	 * The searches made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>|RuntimeException> $answers The answer per schema id.
	 */
	public function __construct(private array $answers) {
	}//end __construct()

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->queries[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		$answer = $this->answers[$query['@self']['schema']] ?? ['total' => 0];
		if ($answer instanceof RuntimeException) {
			throw $answer;
		}

		return $answer;
	}//end searchObjectsPaginated()
}//end class

/**
 * Tests for EntryReferences.
 *
 * @covers \OCA\Stackiq\Service\EntryType\EntryReferences
 */
class EntryReferencesTest extends TestCase {

	private const UUID = 'aaaaaaaa-0000-0000-0000-000000000001';

	/**
	 * The references reader over a register 10 holding schemas 1 (target), 2 and 3.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param bool $mappersFail Whether resolving the mappers throws.
	 *
	 * @return EntryReferences
	 */
	private function references(LoggerInterface $logger, bool $mappersFail = false): EntryReferences {
		$schemas = new EntryReferencesFakeMapper(
			[
				1 => new EntryReferencesFakeSchema(1, 'module', ['name' => ['type' => 'string']], 'mod-uuid'),
				2 => new EntryReferencesFakeSchema(
					2,
					'usage',
					[
						'module'       => ['type' => 'object', '$ref' => '#/components/schemas/module'],
						'applications' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/module']],
						'note'         => 'not a definition',
						'plain'        => ['type' => 'string'],
					]
				),
				3 => new EntryReferencesFakeSchema(
					3,
					'connection',
					[
						'byId'   => ['$ref' => '1'],
						'byUuid' => ['$ref' => 'mod-uuid'],
						'other'  => ['$ref' => '#/components/schemas/usage'],
					]
				),
			]
		);
		$registers = new EntryReferencesFakeMapper([10 => new EntryReferencesFakeSchema(10, 'catalogus', schemas: [1, 2, 3])]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match (true) {
				$mappersFail === true => throw new RuntimeException('openregister is not installed'),
				$id === EntryReferences::SCHEMA_MAPPER => $schemas,
				$id === EntryReferences::REGISTER_MAPPER => $registers,
				default => throw new RuntimeException('not registered: ' . $id),
			}
		);

		return new EntryReferences($container, $logger);
	}//end references()

	/**
	 * Single-object refs by slug path, id and uuid count; arrays and other schemas do not.
	 *
	 * @return void
	 */
	public function testReferencingFieldsFindsSingleObjectRefsBySlugIdAndUuid(): void {
		$fields = $this->references($this->createMock(LoggerInterface::class))->referencingFields(registerId: 10, schemaId: 1);

		$this->assertSame(
			[
				['schemaId' => 2, 'slug' => 'usage', 'property' => 'module'],
				['schemaId' => 3, 'slug' => 'connection', 'property' => 'byId'],
				['schemaId' => 3, 'slug' => 'connection', 'property' => 'byUuid'],
			],
			$fields
		);
	}//end testReferencingFieldsFindsSingleObjectRefsBySlugIdAndUuid()

	/**
	 * Each field is counted as the system, and a field without references is left out.
	 *
	 * @return void
	 */
	public function testBlockersCountEachFieldAndLeaveOutTheEmptyOnes(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$objects = new EntryReferencesFakeObjects([2 => ['total' => 4], 3 => ['total' => 0]]);

		$blockers = $this->references($logger)->blockers(objects: $objects, registerId: 10, schemaId: 1, uuid: self::UUID);

		$this->assertSame([['schema' => 'usage', 'field' => 'module', 'count' => 4]], $blockers);
		$this->assertCount(3, $objects->queries);
		$this->assertSame(
			['query' => ['@self' => ['register' => 10, 'schema' => 2], 'module' => self::UUID, '_limit' => 1], 'rbac' => false, 'multitenancy' => false],
			$objects->queries[0]
		);
	}//end testBlockersCountEachFieldAndLeaveOutTheEmptyOnes()

	/**
	 * Without a total, the number of results returned is the count.
	 *
	 * @return void
	 */
	public function testBlockersFallBackOnTheResultsWhenNoTotalIsGiven(): void {
		$objects = new EntryReferencesFakeObjects([2 => ['results' => [['id' => 'x'], ['id' => 'y']]], 3 => []]);

		$blockers = $this->references($this->createMock(LoggerInterface::class))->blockers(objects: $objects, registerId: 10, schemaId: 1, uuid: self::UUID);

		$this->assertSame([['schema' => 'usage', 'field' => 'module', 'count' => 2]], $blockers);
	}//end testBlockersFallBackOnTheResultsWhenNoTotalIsGiven()

	/**
	 * A search that fails blocks with count -1 and is logged, it is never read as zero.
	 *
	 * @return void
	 */
	public function testAFailedCountBlocksWithMinusOneAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'EntryReferences: references could not be counted',
			['schema' => 'usage', 'exception' => 'search down']
		);
		$objects = new EntryReferencesFakeObjects([2 => new RuntimeException('search down'), 3 => ['total' => 0]]);

		$blockers = $this->references($logger)->blockers(objects: $objects, registerId: 10, schemaId: 1, uuid: self::UUID);

		$this->assertSame([['schema' => 'usage', 'field' => 'module', 'count' => -1]], $blockers);
	}//end testAFailedCountBlocksWithMinusOneAndIsLogged()

	/**
	 * When the register schemas cannot be read the answer is one unknown blocker, never an empty list.
	 *
	 * @return void
	 */
	public function testAScanThatCannotRunIsOneUnknownBlocker(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'EntryReferences: the register schemas could not be read',
			['exception' => 'openregister is not installed']
		);
		$objects = new EntryReferencesFakeObjects([]);

		$blockers = $this->references($logger, mappersFail: true)->blockers(objects: $objects, registerId: 10, schemaId: 1, uuid: self::UUID);

		$this->assertSame([['schema' => '', 'field' => '', 'count' => -1]], $blockers);
		$this->assertSame([], $objects->queries);
	}//end testAScanThatCannotRunIsOneUnknownBlocker()
}//end class
