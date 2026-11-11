<?php

/**
 * Characterisation test for the camelCase property names of ArchiMateService
 * and ArchiMateImportService.
 *
 * Pins, for both services, the name each property label becomes: one word,
 * several words, mixed case, surrounding and repeated spaces, an empty
 * label, and a second call for the same label.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the camelCase property names.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateCamelCaseTest extends TestCase {

	/**
	 * Each label and the name it becomes.
	 */
	private const NAMES = [
		'Name' => 'name',
		'Object ID' => 'objectId',
		'Business Unit Owner' => 'businessUnitOwner',
		'sYSTEM nAME' => 'systemName',
		'  Padded Label  ' => 'paddedLabel',
		'Double  Space' => 'doubleSpace',
		'' => '',
		'GEMMA-type' => 'gemma-type',
	];

	/**
	 * The services.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function services(): array {
		return [
			'ArchiMateService'       => [ArchiMateService::class],
			'ArchiMateImportService' => [ArchiMateImportService::class],
		];

	}//end services()

	/**
	 * Every label becomes the pinned name, on the first and on a repeated call.
	 *
	 * @param class-string $class The service.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testEveryLabelBecomesThePinnedName(string $class): void {
		$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		$convert = new \ReflectionMethod(objectOrMethod: $class, method: 'convertToCamelCase');
		foreach ([1, 2] as $round) {
			$names = [];
			foreach (array_keys(self::NAMES) as $label) {
				$names[$label] = $convert->invoke($service, (string) $label);
			}

			$this->assertSame(expected: self::NAMES, actual: $names, message: 'round ' . $round);
		}

	}//end testEveryLabelBecomesThePinnedName()

}//end class
