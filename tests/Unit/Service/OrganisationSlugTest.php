<?php

/**
 * Tests for the slug and name stackiq gives an OpenRegister organisation.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\OrganisationSlug;
use PHPUnit\Framework\TestCase;

/**
 * The slug matches OpenRegister's generateSlug(), and the name is the one OpenRegister receives.
 *
 * @covers \OCA\Stackiq\Service\OrganisationSlug
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */
class OrganisationSlugTest extends TestCase {

	/**
	 * Names and the slugs OpenRegister gives them.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function slugs(): array {
		return [
			'plain'                           => ['Gemeente A', 'gemeente-a'],
			'runs of other characters'        => ['  Gemeente  G & Zn! ', 'gemeente-g-zn'],
			'cut at 100'                      => [str_repeat('a', 120), str_repeat('a', 100)],
			'hyphen at position 100 is kept'  => [str_repeat('a', 99) . ' b', str_repeat('a', 99) . '-'],
			'no letters or digits'            => ['!!!', ''],
		];
	}//end slugs()

	/**
	 * The slug of a name.
	 *
	 * @param string $name The name.
	 * @param string $slug The slug OpenRegister gives it.
	 *
	 * @return void
	 *
	 * @dataProvider slugs
	 */
	public function testTheSlugOfAName(string $name, string $slug): void {
		self::assertSame($slug, (new OrganisationSlug())->fromName(name: $name));
	}//end testTheSlugOfAName()

	/**
	 * Organisatie data and the name OpenRegister receives for it.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function names(): array {
		$id = 'abcd1234-5678-4abc-8def-0123456789ab';
		return [
			'its own name'    => [['id' => $id, 'name' => 'Gemeente A'], 'Gemeente A'],
			'no name'         => [['id' => $id], 'Organisation abcd1234'],
			'empty name'      => [['id' => $id, 'name' => ''], 'Organisation abcd1234'],
			'named Unknown'   => [['id' => $id, 'name' => 'Unknown'], 'Organisation abcd1234'],
			'named 0'         => [['id' => $id, 'name' => '0'], 'Organisation abcd1234'],
			'named unknown'   => [['id' => $id, 'name' => 'unknown'], 'unknown'],
			'no id'           => [['name' => 'Unknown'], 'Organisation '],
		];
	}//end names()

	/**
	 * The name, and the slug of that name.
	 *
	 * @param array  $objectData The organisatie object data.
	 * @param string $name       The name OpenRegister receives.
	 *
	 * @return void
	 *
	 * @dataProvider names
	 */
	public function testTheNameOpenRegisterReceives(array $objectData, string $name): void {
		$slugs = new OrganisationSlug();
		self::assertSame($name, $slugs->nameFor(objectData: $objectData));
		self::assertSame($slugs->fromName(name: $name), $slugs->fromObjectData(objectData: $objectData));
	}//end testTheNameOpenRegisterReceives()
}//end class
