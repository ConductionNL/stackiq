<?php

/**
 * Tests for the import rules a CMDB mapping pack must follow.
 *
 * The rules per edit are exercised through the loader in
 * CmdbImportProfileTest::testAStoredPackThatBreaksAnImportRuleIsRefused();
 * this covers the rules on their own: the shipped packs follow them, the
 * register fragments count, and an unreadable register fails closed.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackRules;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * CmdbImportPackRules.
 */
class CmdbImportPackRulesTest extends TestCase {
	/**
	 * A shipped file, decoded.
	 *
	 * @param string $file The file name.
	 *
	 * @return array<string, mixed>
	 */
	private function shipped(string $file): array {
		return json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/' . $file), true);
	}//end shipped()

	/**
	 * Every shipped pack follows the rules, including the module fields only a register fragment declares.
	 *
	 * @return void
	 */
	public function testTheShippedPacksFollowTheRules(): void {
		$rules = new CmdbImportPackRules();
		$profile = $this->shipped(file: 'topdesk-profile.json');

		foreach ($profile['packs'] as $target => $file) {
			$this->assertSame([], $rules->violations(target: $target, pack: $this->shipped(file: $file), profile: $profile), $target);
		}
	}//end testTheShippedPacksFollowTheRules()

	/**
	 * Several broken rules are all named, once each.
	 *
	 * @return void
	 */
	public function testEveryBrokenRuleIsNamedOnce(): void {
		$profile = $this->shipped(file: 'topdesk-profile.json');
		$pack = $this->shipped(file: 'topdesk-manufacturer.json');
		$pack['fieldMappings'][0]['required'] = false;
		$pack['fieldMappings'][] = ['source' => 'Leverancier', 'target' => 'provider'];
		$pack['fieldMappings'][] = ['source' => 'Hostingpartij', 'target' => 'provider'];
		unset($pack['defaults']['type']);

		$this->assertSame(
			[
				'"name" must be mapped as required',
				'"provider" is set by the import and cannot be mapped',
				'default "type" must stay "Supplier"',
			],
			(new CmdbImportPackRules())->violations(target: 'manufacturer', pack: $pack, profile: $profile)
		);
	}//end testEveryBrokenRuleIsNamedOnce()

	/**
	 * Without the register definition no pack can be checked, so the import fails closed.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsMappingUnavailable(): void {
		$rules = new CmdbImportPackRules(settingsDirectory: '/nonexistent');

		try {
			$rules->violations(target: 'usage', pack: $this->shipped(file: 'topdesk-usage.json'), profile: []);
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame('The stackiq register definition cannot be read, so the CMDB import packs cannot be checked', $e->getMessage());
		}
	}//end testAnUnreadableRegisterIsMappingUnavailable()
}//end class
