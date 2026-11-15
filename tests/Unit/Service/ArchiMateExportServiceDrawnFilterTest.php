<?php

/**
 * The full ArchiMate export leaves drawn objects out.
 *
 * The export reads every AMEF schema across organisations, so it keeps only
 * GEMMA content: objects whose origin is empty or `imported`. Drawn views,
 * elements and relations stay with the organisation that drew them.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-006-drawn-views-shall-stay-inside-the-organisation-that-drew-them
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ViewOrigin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * getObjectsFromDatabase() keeps imported and legacy objects only.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateExportService
 * @covers \OCA\Stackiq\Service\ViewOrigin
 */
final class ArchiMateExportServiceDrawnFilterTest extends TestCase {

	/**
	 * An object read from the register.
	 *
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * One schema's objects: an imported, a legacy and a drawn one.
	 *
	 * @param string $prefix The schema's prefix for ids.
	 *
	 * @return array<int, ObjectEntityInterface>
	 */
	private function objects(string $prefix): array {
		return [
			$this->entity(['id' => $prefix . '-gemma', 'origin' => ViewOrigin::IMPORTED]),
			$this->entity(['id' => $prefix . '-legacy']),
			$this->entity(['id' => $prefix . '-drawn', 'origin' => 'drawn']),
		];
	}//end objects()

	/**
	 * Per schema, drawn views, elements and relations are left out; imported and legacy ones stay.
	 *
	 * @return void
	 */
	public function testDrawnObjectsAreLeftOutOfEachSchema(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			fn (array $query): array => $this->objects(prefix: (string)$query['@self']['schema'])
		);

		$objects = (new ArchiMateExportService(logger: $this->createMock(LoggerInterface::class)))
			->getObjectsFromDatabase(objectService: $objectService, registerId: 7, schemaIdMap: [1 => 'element', 2 => 'relationship', 3 => 'view']);

		$this->assertSame(['1-gemma', '1-legacy', '2-gemma', '2-legacy', '3-gemma', '3-legacy'], array_column($objects, 'id'));
		$this->assertSame(['element', 'element', 'relationship', 'relationship', 'view', 'view'], array_column($objects, 'section'));
	}//end testDrawnObjectsAreLeftOutOfEachSchema()

	/**
	 * The register-wide fallback read leaves drawn objects out too.
	 *
	 * @return void
	 */
	public function testTheFallbackReadLeavesDrawnObjectsOut(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturn($this->objects(prefix: 'all'));

		$objects = (new ArchiMateExportService(logger: $this->createMock(LoggerInterface::class)))
			->getObjectsFromDatabase(objectService: $objectService, registerId: 7);

		$ids = array_map(static fn (ObjectEntityInterface $object): string => $object->jsonSerialize()['id'], $objects);
		$this->assertSame(['all-gemma', 'all-legacy'], $ids);
	}//end testTheFallbackReadLeavesDrawnObjectsOut()
}//end class
