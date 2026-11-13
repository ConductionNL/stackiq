<?php

/**
 * Golden tests for GemmaObjectReader::endpoint().
 *
 * Pins which identifier a relationship end resolves to: the flattened
 * property, then the XML element, then the XML attribute, each as a string or
 * as an element with an href or value, skipping empty and non-string values,
 * so splitting the method keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\ArchiMate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\Stackiq\Service\ArchiMate\GemmaObjectReader;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for one end of an ArchiMate relationship.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\ArchiMate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class GemmaObjectReaderEndpointGoldenTest extends TestCase {

	/**
	 * Every relationship shape resolves to the recorded end.
	 *
	 * @return void
	 */
	public function testEveryShapeResolvesToTheRecordedEnd(): void {
		$cases = [
			[['source' => 'flat'], 'flat'],
			[['source' => '', 'xml' => ['source' => 'element']], 'element'],
			[['source' => 5, 'xml' => ['_attributes' => ['source' => 'attribute']]], 'attribute'],
			[['source' => ['_attributes' => ['href' => '#href'], '_value' => 'value']], '#href'],
			[['source' => ['_value' => 'value']], 'value'],
			[['source' => ['_attributes' => ['href' => '']], 'xml' => ['source' => ['_value' => 'later']]], 'later'],
			[['source' => ['_attributes' => ['href' => null], '_value' => 'fallback']], 'fallback'],
			[['source' => ['other' => 'x'], 'xml' => ['source' => null, '_attributes' => ['source' => '']]], null],
			[['target' => 'other end'], null],
			[[], null],
		];
		$reader = new GemmaObjectReader();

		foreach ($cases as [$relationship, $expected]) {
			$this->assertSame($expected, $reader->endpoint($relationship, 'source'), json_encode($relationship));
		}

		$this->assertSame('other end', $reader->endpoint(['target' => 'other end'], 'target'));
	}//end testEveryShapeResolvesToTheRecordedEnd()

}//end class
