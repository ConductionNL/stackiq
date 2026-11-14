<?php

/**
 * Golden test of the whole reference component coverage derivation.
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

use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\ReferenceComponentCoverageDerivation;
use PHPUnit\Framework\TestCase;

/**
 * Pins the summary and every row of derive(), including its order.
 */
final class ReferenceComponentCoverageDerivationGoldenTest extends TestCase {
	/**
	 * The derivation of a mixed landscape matches the golden.
	 *
	 * @return void
	 */
	public function testDeriveMatchesTheGolden(): void {
		$components = [
			['id' => 'zrc', 'name' => 'Zaakregistratiecomponent', 'grouping' => 'Mid-office', 'documentation' => 'Zaken'],
			['@self' => ['id' => 'fin'], 'name' => 'Financiële component', 'grouping' => 'Back-office'],
			['id' => 'doc', 'name' => 'Documentbeheercomponent', 'grouping' => 'Mid-office'],
			['id' => 'kb', 'name' => 'Kennisbankcomponent', 'grouping' => 'Front-office'],
			['id' => ' ab ', 'name' => 'Alpha', 'grouping' => 'Mid-office'],
			['name' => 'No id'],
		];
		$usages = [
			['id' => 'u1', 'module' => 'mozard', 'status' => 'In production', 'usedForReferenceComponents' => ['zrc', 'zrc', '']],
			['@self' => ['id' => 'u2'], 'module' => ['id' => 'openzaak'], 'status' => 'To be phased out', 'usedForReferenceComponents' => [['id' => 'zrc'], ['uuid' => 'ab']]],
			['id' => 'u3', 'module' => 'key2', 'status' => 'In production', 'usedForReferenceComponents' => ['fin']],
			['id' => 'u4', 'module' => 'key2', 'status' => 'In production', 'usedForReferenceComponents' => ['fin']],
			['id' => 'u5', 'module' => 'exact', 'status' => 'Planned', 'usedForReferenceComponents' => ['fin', 'kb']],
			['id' => 'u6', 'module' => 'djuma', 'status' => 'In production'],
			['id' => 'u7', 'module' => 'ghost', 'usedForReferenceComponents' => 'not a list'],
			['id' => 'u8', 'usedForReferenceComponents' => ['doc']],
		];
		$names = ['mozard' => 'Mozard', 'openzaak' => 'OpenZaak', 'key2' => 'Key2Financien', 'exact' => 'Exact', 'djuma' => 'Djuma'];
		$moduleComponents = ['mozard' => ['zrc', 'doc'], 'exact' => ['kb', 'kb'], 'nameless' => ['kb']];

		$out = (new ReferenceComponentCoverageDerivation(new PortfolioReportDerivation()))
			->derive(components: $components, usages: $usages, moduleNames: $names, moduleComponents: $moduleComponents);

		$path = dirname(__DIR__, 2) . '/fixtures/coverage/derive.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testDeriveMatchesTheGolden()
}//end class
