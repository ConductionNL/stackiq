<?php

/**
 * Golden test for SbomParserService::extractVexPairs().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SbomParserService;
use PHPUnit\Framework\TestCase;

/**
 * Pins which CycloneDX VEX entries become cve/component pairs.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
class SbomParserVexPairsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/sbom/vex-pairs.json';

	/**
	 * The vulnerability blocks under test.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function blocks(): array {
		return [
			'empty'              => [],
			'non-array entries'  => ['CVE-1', 7, null],
			'no id'              => [['affects' => [['ref' => 'a']]]],
			'empty id'           => [['id' => '', 'affects' => [['ref' => 'a']]]],
			'numeric id'         => [['id' => 2024, 'affects' => [['ref' => 'a']]]],
			'no affects'         => [['id' => 'CVE-1']],
			'empty affects'      => [['id' => 'CVE-1', 'affects' => []]],
			'odd affects'        => [['id' => 'CVE-1', 'affects' => ['a', null, ['ref' => ''], ['ref' => 5], ['other' => 'x']]]],
			'one pair'           => [['id' => 'CVE-1', 'affects' => [['ref' => 'pkg:a']]]],
			'id times refs'      => [
				['id' => 'CVE-1', 'affects' => [['ref' => 'pkg:a'], ['ref' => 'pkg:b']]],
				['id' => 'CVE-2', 'affects' => [['ref' => 'pkg:a']]],
				'skip',
				['id' => 'CVE-1', 'affects' => [['ref' => 'pkg:a']]],
			],
			'keyed affects'      => [['id' => 'CVE-3', 'affects' => ['x' => ['ref' => 'pkg:x'], 'y' => ['ref' => 'pkg:y']]]],
			'keyed block'        => ['first' => ['id' => 'CVE-4', 'affects' => [['ref' => 'pkg:z']]]],
		];
	}//end blocks()

	/**
	 * Every block yields the recorded pairs.
	 *
	 * @return void
	 */
	public function testVexPairsMatchGolden(): void {
		$reflection = new \ReflectionClass(SbomParserService::class);
		$parser     = $reflection->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod($parser, 'extractVexPairs');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::blocks() as $name => $block) {
			$actual[$name] = $method->invoke($parser, $block);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);

	}//end testVexPairsMatchGolden()
}//end class
