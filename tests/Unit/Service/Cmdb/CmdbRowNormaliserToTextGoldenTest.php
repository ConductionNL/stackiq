<?php

/**
 * Golden test for CmdbRowNormaliser::toText().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\CmdbRowNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Pins how a raw cell value becomes text.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\Cmdb
 */
class CmdbRowNormaliserToTextGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../../fixtures/cmdb/row-to-text.json';

	/**
	 * The raw values under test.
	 *
	 * @return array<string, mixed>
	 */
	private static function values(): array {
		return [
			'null'             => null,
			'true'             => true,
			'false'            => false,
			'int'              => 42,
			'negative int'     => -7,
			'zero float'       => 0.0,
			'negative zero'    => -0.0,
			'whole float'      => 3.0,
			'negative whole'   => -12.0,
			'fraction'         => 3.25,
			'huge float'       => 1.0e19,
			'huge negative'    => -1.0e19,
			'small exponent'   => 1.0e-7,
			'infinity'         => INF,
			'nan'              => NAN,
			'array'            => ['a'],
			'object'           => new \stdClass(),
			'plain string'     => 'abc',
			'padded string'    => "  abc \t\n",
			'nbsp padded'      => "\u{00A0}abc\u{00A0}",
			'zero width'       => "\u{200B}abc\u{FEFF}",
			'inner spaces'     => ' a  b ',
			'invalid utf8'     => " \xC3\x28 ",
			'only whitespace'  => " \u{00A0} ",
			'numeric string'   => ' 007 ',
		];
	}//end values()

	/**
	 * Every value converts to the recorded text.
	 *
	 * @return void
	 */
	public function testToTextMatchesGolden(): void {
		$reflection = new \ReflectionClass(CmdbRowNormaliser::class);
		$normaliser = $reflection->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod($normaliser, 'toText');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::values() as $name => $value) {
			$actual[$name] = bin2hex($method->invoke($normaliser, $value));
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);

	}//end testToTextMatchesGolden()
}//end class
