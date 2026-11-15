<?php

/**
 * Golden test for ItsmExchangeService::fill().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ItsmExchangeService;
use PHPUnit\Framework\TestCase;

/**
 * Pins how flow templates are filled with placeholder values.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
final class ItsmExchangeFillGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/itsm/fill.json';

	/**
	 * Every template fills to the recorded value.
	 *
	 * @return void
	 */
	public function testFillMatchesGolden(): void {
		$values = [
			'NAME'   => 'Desk',
			'COUNT'  => 3,
			'RATIO'  => 1.5,
			'ON'     => true,
			'OFF'    => false,
			'NONE'   => null,
			'LIST'   => ['a', 'b'],
			'MAP'    => ['k' => 'v'],
			'EMPTY'  => '',
			'PREFIX' => 'pre',
			'PREFIX_LONG' => 'long',
		];
		$templates = [
			'plain'          => 'no placeholders',
			'whole string'   => '%NAME%',
			'whole number'   => '%COUNT%',
			'whole list'     => '%LIST%',
			'whole map'      => '%MAP%',
			'whole null'     => '%NONE%',
			'whole false'    => '%OFF%',
			'whole unknown'  => '%MISSING%',
			'whole lower'    => '%name%',
			'embedded'       => 'Hello %NAME%, %COUNT% x %RATIO% (%ON%/%OFF%/%NONE%) [%EMPTY%]',
			'embedded list'  => 'items: %LIST% and %MAP%',
			'overlap'        => '%PREFIX_LONG% then %PREFIX%',
			'repeated'       => '%NAME%%NAME%',
			'padded whole'   => ' %NAME%',
			'number'         => 42,
			'float'          => 2.5,
			'bool'           => true,
			'null'           => null,
			'nested'         => ['a' => '%NAME%', 'b' => ['%LIST%', 'x %COUNT%'], 7 => '%MAP%', 'c' => 5],
			'list'           => ['%ON%', '%OFF%', 'id-%COUNT%'],
			'empty array'    => [],
		];

		$actual = [];
		foreach ($templates as $name => $template) {
			$actual[$name] = ItsmExchangeService::fill(value: $template, values: $values);
		}

		$actual['no values'] = ItsmExchangeService::fill(value: ['%NAME%', 'x %NAME%'], values: []);

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));

	}//end testFillMatchesGolden()
}//end class
