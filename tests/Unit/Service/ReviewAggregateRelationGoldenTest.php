<?php

/**
 * Golden test for ReviewAggregateService::relationContainsSubject().
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

use OCA\Stackiq\Service\ReviewAggregateService;
use PHPUnit\Framework\TestCase;

/**
 * Pins which relation values count as referencing a subject.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
final class ReviewAggregateRelationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/review/relation-contains-subject.json';

	/**
	 * Every relation value gives the recorded answer.
	 *
	 * @return void
	 */
	public function testRelationMatchesGolden(): void {
		$values = [
			'null'               => null,
			'string'             => 's-1',
			'empty list'         => [],
			'uuid list'          => ['x', 's-1'],
			'other uuids'        => ['x', 'y'],
			'object by id'       => [['id' => 's-1']],
			'object by uuid'     => [['uuid' => 's-1']],
			'id wins over uuid'  => [['id' => 'x', 'uuid' => 's-1']],
			'numeric id'         => [['id' => 5, 'uuid' => 's-1']],
			'null id'            => [['id' => null, 'uuid' => 's-1']],
			'padded'             => [' s-1'],
			'case'               => ['S-1'],
			'numbers'            => [1, 2.0, true],
			'nested deeper'      => [[['id' => 's-1']]],
			'mixed'              => [null, ['name' => 'x'], 's-1'],
			'keyed'              => ['a' => ['id' => 's-1']],
			'empty entries'      => ['', ['id' => '']],
		];
		$reflection = new \ReflectionClass(ReviewAggregateService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod($service, 'relationContainsSubject');
		$method->setAccessible(true);

		$actual = [];
		foreach ($values as $name => $value) {
			$actual[$name] = [
				's-1' => $method->invoke($service, $value, 's-1'),
				'5'   => $method->invoke($service, $value, '5'),
				''    => $method->invoke($service, $value, ''),
			];
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);

	}//end testRelationMatchesGolden()
}//end class
