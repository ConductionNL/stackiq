<?php

/**
 * Register shape for the supplier's answer on a claimed usage.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * The usage schema carries the supplier's answer, and only an admin edits it directly.
 *
 * @coversNothing
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
class ClaimedUsageRegisterShapeTest extends TestCase {

	/**
	 * The usage schema has providerResponse (accepted, declined), its date and its user, none required.
	 *
	 * @return void
	 */
	public function testTheUsageCarriesTheSupplierAnswer(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
		$usage    = $register['components']['schemas']['usage'];
		$props    = $usage['properties'];

		self::assertSame(['accepted', 'declined'], $props['providerResponse']['enum']);
		self::assertSame('date-time', $props['providerResponseAt']['format']);
		self::assertSame('string', $props['providerResponseBy']['type']);
		foreach (['providerResponse', 'providerResponseAt', 'providerResponseBy'] as $name) {
			// The claiming organisation owns the usage and may edit its fields; it may not write the supplier's answer.
			self::assertSame(['update' => ['admin']], $props[$name]['authorization'], $name);
			self::assertNotContains($name, ($usage['required'] ?? []), $name);
		}

	}//end testTheUsageCarriesTheSupplierAnswer()
}//end class
