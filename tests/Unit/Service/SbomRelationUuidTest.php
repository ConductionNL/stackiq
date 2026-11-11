<?php

/**
 * Characterisation tests for SbomImportService::resolveRelationUuid.
 *
 * Pins which relation values resolve to a uuid.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sbom-import/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SbomImportService;
use PHPUnit\Framework\TestCase;

/**
 * Cases for resolving a relation value.
 *
 * @covers \OCA\Stackiq\Service\SbomImportService
 *
 * @spec openspec/specs/sbom-import/spec.md
 */
class SbomRelationUuidTest extends TestCase {

	/**
	 * A non-empty string, or the uuid (else id) an array or object carries.
	 *
	 * @return void
	 */
	public function testRelationValuesResolveToAUuid(): void {
		$service = (new \ReflectionClass(SbomImportService::class))->newInstanceWithoutConstructor();
		$resolve = new \ReflectionMethod(SbomImportService::class, 'resolveRelationUuid');

		$cases = [
			['u-1', 'u-1'],
			['', null],
			[null, null],
			[5, null],
			[['uuid' => 'u-2', 'id' => 'i-2'], 'u-2'],
			[['id' => 'i-3'], 'i-3'],
			[['uuid' => null, 'id' => 'i-4'], 'i-4'],
			[['uuid' => ''], ''],
			[[], null],
			[(object) ['uuid' => 'u-5', 'id' => 'i-5'], 'u-5'],
			[(object) ['id' => 'i-6'], 'i-6'],
			[new \stdClass(), null],
		];
		foreach ($cases as [$relation, $expected]) {
			$this->assertSame($expected, $resolve->invoke($service, $relation), var_export($relation, true));
		}

		$this->expectException(\TypeError::class);
		$resolve->invoke($service, ['id' => 7]);

	}//end testRelationValuesResolveToAUuid()
}//end class
