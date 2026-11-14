<?php

/**
 * Unit tests for RelativeDemoDates.
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

use DateTimeImmutable;
use OCA\Stackiq\Service\RelativeDemoDates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Relative demo dates resolve against the import date.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 */
#[CoversClass(RelativeDemoDates::class)]
final class RelativeDemoDatesTest extends TestCase {

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

		$out = RelativeDemoDates::resolve(data: $data, now: $now);

		$this->assertSame('2026-11-07T12:00:00+00:00', $out['components']['objects'][0]['accessReviewedAt']);
		$this->assertSame('Voorbeeld', $out['components']['objects'][0]['role']);
		$this->assertSame('2025-09-14T12:00:00+00:00', $out['components']['objects'][1]['accessReviewedAt']);
		$this->assertSame(['-P7D'], $out['components']['objects'][2]['roles'], 'Only top-level string fields are resolved.');
		$this->assertSame(['@self' => ['schema' => 'contactPerson'], 'note' => '-P', 'other' => 'P7D', 'count' => 3], $out['components']['objects'][3]);
		$this->assertSame('not an object', $out['components']['objects'][4]);
		$this->assertSame(['stackiq' => ['since' => '-P7D']], $out['components']['registers']);
	}//end testRelativeValuesBecomeDatesBeforeTheImport()

	/**
	 * A descriptor without an objects list is returned unchanged.
	 *
	 * @return void
	 */
	public function testADescriptorWithoutObjectsIsUnchanged(): void {
		$now = new DateTimeImmutable('2026-11-14T12:00:00+00:00');

		$this->assertSame(['components' => ['objects' => 'x']], RelativeDemoDates::resolve(data: ['components' => ['objects' => 'x']], now: $now));
		$this->assertSame([], RelativeDemoDates::resolve(data: [], now: $now));
	}//end testADescriptorWithoutObjectsIsUnchanged()
}//end class
