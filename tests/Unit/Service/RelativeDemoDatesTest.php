<?php

/**
 * Demo date placeholders resolved against the import day
 * (contracts-expiry-and-owner task 6: a seeded contract that keeps reading Expiring).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Stackiq\Service\RelativeDemoDates;
use PHPUnit\Framework\TestCase;

/**
 * `{{today±N[d|m|y]}}` becomes a Y-m-d date; everything else is left alone.
 *
 * @covers \OCA\Stackiq\Service\RelativeDemoDates
 */
class RelativeDemoDatesTest extends TestCase {

	/**
	 * Resolve one object's fields against 14 Nov 2026, 15:30.
	 *
	 * @param array<string, mixed> $object The demo object.
	 *
	 * @return array<string, mixed> The resolved object.
	 */
	private function resolveObject(array $object): array {
		$data = (new RelativeDemoDates())->resolve(
			data: ['components' => ['objects' => [$object]]],
			today: new DateTimeImmutable('2026-11-14 15:30:00')
		);

		return $data['components']['objects'][0];
	}//end resolveObject()

	/**
	 * Days, months and years, forward and back, and today itself.
	 *
	 * @return void
	 */
	public function testEachUnitAndDirectionResolvesAgainstTheImportDay(): void {
		$this->assertSame(
			expected: [
				'today' => '2026-11-14',
				'soon' => '2026-12-29',
				'past' => '2026-11-13',
				'months' => '2027-02-14',
				'years' => '2024-11-14',
			],
			actual: $this->resolveObject(
				object: [
					'today' => '{{today}}',
					'soon' => '{{today+45d}}',
					'past' => '{{today-1d}}',
					'months' => '{{today+3m}}',
					'years' => '{{today-2y}}',
				]
			)
		);
	}//end testEachUnitAndDirectionResolvesAgainstTheImportDay()

	/**
	 * Placeholders inside nested objects and lists are resolved too.
	 *
	 * @return void
	 */
	public function testNestedValuesAreResolved(): void {
		$this->assertSame(
			expected: ['window' => ['from' => '2026-11-14', 'dates' => ['2026-11-24', 'x']]],
			actual: $this->resolveObject(object: ['window' => ['from' => '{{today}}', 'dates' => ['{{today+10d}}', 'x']]])
		);
	}//end testNestedValuesAreResolved()

	/**
	 * A string that only contains a placeholder, an unknown unit or another
	 * type is not a placeholder.
	 *
	 * @return void
	 */
	public function testAnythingButAWholePlaceholderIsLeftAsItIs(): void {
		$object = [
			'embedded' => 'ends {{today+45d}}',
			'unit' => '{{today+45w}}',
			'nodigits' => '{{today+d}}',
			'other' => '{{tomorrow}}',
			'date' => '2026-03-01',
			'number' => 45,
			'empty' => null,
			'flag' => true,
		];

		$this->assertSame(expected: $object, actual: $this->resolveObject(object: $object));
	}//end testAnythingButAWholePlaceholderIsLeftAsItIs()

	/**
	 * Only demo objects are walked: definitions keep their text, and a
	 * descriptor without an objects list is returned unchanged.
	 *
	 * @return void
	 */
	public function testOnlyTheObjectsListIsResolved(): void {
		$dates = new RelativeDemoDates();
		$today = new DateTimeImmutable('2026-11-14');
		$data = [
			'components' => [
				'schemas' => ['catalogContract' => ['description' => '{{today}}']],
				'objects' => [['endDate' => '{{today}}']],
			],
		];

		$resolved = $dates->resolve(data: $data, today: $today);

		$this->assertSame(expected: '{{today}}', actual: $resolved['components']['schemas']['catalogContract']['description']);
		$this->assertSame(expected: '2026-11-14', actual: $resolved['components']['objects'][0]['endDate']);
		$this->assertSame(
			expected: ['components' => ['objects' => 'x']],
			actual: $dates->resolve(data: ['components' => ['objects' => 'x']], today: $today)
		);
		$this->assertSame(expected: ['info' => []], actual: $dates->resolve(data: ['info' => []], today: $today));
	}//end testOnlyTheObjectsListIsResolved()

	/**
	 * A week and fourteen months before the import, and nothing else changed.
	 *
	 * @return void
	 */
	public function testRelativeValuesBecomeDatesBeforeTheImport(): void {
		$now = new DateTimeImmutable('2026-11-14T12:00:00+00:00');
		$data = [
			'components' => [
				'objects' => [
					['@self' => ['schema' => 'contactPerson'], 'accessReviewedAt' => '-P7D', 'role' => 'Voorbeeld'],
					['@self' => ['schema' => 'contactPerson'], 'accessReviewedAt' => '-P14M'],
					['@self' => ['schema' => 'contactPerson'], 'roles' => ['-P7D']],
					['@self' => ['schema' => 'contactPerson'], 'note' => '-P', 'other' => 'P7D', 'count' => 3],
					'not an object',
				],
				'registers' => ['stackiq' => ['since' => '-P7D']],
			],
		];

		$out = RelativeDemoDates::resolveDurations(data: $data, now: $now);

		$this->assertSame(expected: '2026-11-07T12:00:00+00:00', actual: $out['components']['objects'][0]['accessReviewedAt']);
		$this->assertSame(expected: 'Voorbeeld', actual: $out['components']['objects'][0]['role']);
		$this->assertSame(expected: '2025-09-14T12:00:00+00:00', actual: $out['components']['objects'][1]['accessReviewedAt']);
		$this->assertSame(
			expected: ['-P7D'],
			actual: $out['components']['objects'][2]['roles'],
			message: 'Only top-level string fields are resolved.'
		);
		$this->assertSame(
			expected: ['@self' => ['schema' => 'contactPerson'], 'note' => '-P', 'other' => 'P7D', 'count' => 3],
			actual: $out['components']['objects'][3]
		);
		$this->assertSame(expected: 'not an object', actual: $out['components']['objects'][4]);
		$this->assertSame(expected: ['stackiq' => ['since' => '-P7D']], actual: $out['components']['registers']);
	}//end testRelativeValuesBecomeDatesBeforeTheImport()

	/**
	 * A descriptor without an objects list is returned unchanged.
	 *
	 * @return void
	 */
	public function testADescriptorWithoutObjectsKeepsItsDurations(): void {
		$now = new DateTimeImmutable('2026-11-14T12:00:00+00:00');

		$this->assertSame(
			expected: ['components' => ['objects' => 'x']],
			actual: RelativeDemoDates::resolveDurations(data: ['components' => ['objects' => 'x']], now: $now)
		);
		$this->assertSame(expected: [], actual: RelativeDemoDates::resolveDurations(data: [], now: $now));
	}//end testADescriptorWithoutObjectsKeepsItsDurations()
}//end class
