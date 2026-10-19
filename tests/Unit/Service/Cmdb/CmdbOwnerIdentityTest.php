<?php

/**
 * Tests for CmdbOwnerIdentity.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\CmdbOwnerIdentity;
use PHPUnit\Framework\TestCase;

/**
 * How an owner is identified across imports.
 *
 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
 */
class CmdbOwnerIdentityTest extends TestCase {
	/**
	 * The UID hides the number, is scoped to the municipality and ignores Excel's decimal form.
	 *
	 * @return void
	 */
	public function testTheContactUidDerivesFromTheNumberAndTheMunicipality(): void {
		$identity = new CmdbOwnerIdentity();
		$uid = $identity->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-1');

		$this->assertStringStartsWith(CmdbOwnerIdentity::UID_PREFIX, (string)$uid);
		$this->assertStringNotContainsString('1001', (string)$uid);
		$this->assertSame($uid, $identity->contactUid(employeeNumber: ' 1001.0 ', municipalityUuid: 'muni-1'));
		$this->assertNotSame($uid, $identity->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-2'));
		$this->assertNotSame($uid, $identity->contactUid(employeeNumber: '10010', municipalityUuid: 'muni-1'));
		$this->assertNull($identity->contactUid(employeeNumber: ' ', municipalityUuid: 'muni-1'));
	}//end testTheContactUidDerivesFromTheNumberAndTheMunicipality()

	/**
	 * The employee number wins over the e-mail address, which wins over the name.
	 *
	 * @return void
	 */
	public function testTheCacheKeyFollowsTheMatchOrder(): void {
		$identity = new CmdbOwnerIdentity();
		$this->assertSame('uid:x', $identity->cacheKey(contactUid: 'x', email: 'A@example.org', displayName: 'Naam'));
		$this->assertSame('email:a@example.org', $identity->cacheKey(contactUid: null, email: 'A@example.org', displayName: 'Naam'));
		$this->assertSame('name:naam', $identity->cacheKey(contactUid: null, email: '', displayName: 'Naam'));
	}//end testTheCacheKeyFollowsTheMatchOrder()
}//end class
