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
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * How an owner is identified across imports.
 *
 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
 */
class CmdbOwnerIdentityTest extends TestCase {
	/**
	 * An identity whose instance has this secret.
	 *
	 * @param string $secret The `secret` from config.php.
	 *
	 * @return CmdbOwnerIdentity
	 */
	private function identity(string $secret = 'instance-secret'): CmdbOwnerIdentity {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = ''): string => $key === 'secret' ? $secret : $default);
		return new CmdbOwnerIdentity(config: $config, logger: $this->createMock(LoggerInterface::class));
	}//end identity()

	/**
	 * The UID is keyed with the instance secret, scoped to the municipality and ignores Excel's decimal form and leading zeros.
	 *
	 * @return void
	 */
	public function testTheContactUidDerivesFromTheNumberAndTheMunicipality(): void {
		$identity = $this->identity();
		$uid = $identity->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-1');

		$this->assertSame(CmdbOwnerIdentity::UID_PREFIX . hash_hmac('sha256', 'muni-1|1001', hash_hmac('sha256', CmdbOwnerIdentity::UID_PREFIX, 'instance-secret')), $uid);
		$this->assertNotSame(CmdbOwnerIdentity::UID_PREFIX . hash_hmac('sha256', 'muni-1|1001', 'instance-secret'), $uid, 'the raw instance secret is not the key');
		$this->assertNotSame(CmdbOwnerIdentity::UID_PREFIX . hash('sha256', 'muni-1|1001'), $uid, 'not an unkeyed hash anyone can recompute');
		$this->assertNotSame($uid, $this->identity(secret: 'other-secret')->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-1'));
		$this->assertSame($uid, $identity->contactUid(employeeNumber: ' 1001.0 ', municipalityUuid: 'muni-1'));
		$this->assertSame($uid, $identity->contactUid(employeeNumber: '01001', municipalityUuid: 'muni-1'));
		$this->assertSame($uid, $identity->contactUid(employeeNumber: "\u{00A0}1001\u{00A0}", municipalityUuid: 'muni-1'), 'non-breaking spaces');
		$this->assertNotSame($uid, $identity->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-2'));
		$this->assertNotSame($uid, $identity->contactUid(employeeNumber: '10010', municipalityUuid: 'muni-1'));
		$this->assertNotNull($identity->contactUid(employeeNumber: 'P1001', municipalityUuid: 'muni-1'), 'a number with letters is a number');
	}//end testTheContactUidDerivesFromTheNumberAndTheMunicipality()

	/**
	 * An empty cell or a placeholder is no employee number, and without a secret no number is used.
	 *
	 * @return void
	 */
	public function testAValueWithoutANumberGivesNoUid(): void {
		$identity = $this->identity();
		foreach ([' ', '-', '0', '000', '0.0', 'n.v.t.', 'onbekend', '?'] as $placeholder) {
			$this->assertNull($identity->contactUid(employeeNumber: $placeholder, municipalityUuid: 'muni-1'), $placeholder);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$withoutSecret = new CmdbOwnerIdentity(config: $config, logger: $logger);
		$this->assertNull($withoutSecret->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-1'), 'fail-closed without a secret');
		$this->assertNull($withoutSecret->contactUid(employeeNumber: '2002', municipalityUuid: 'muni-1'), 'logged once per run');
	}//end testAValueWithoutANumberGivesNoUid()

	/**
	 * A contact without an employee number gets a random UUID, never one with the number prefix.
	 *
	 * @return void
	 */
	public function testANewContactUidIsARandomUuid(): void {
		$uid = $this->identity()->newContactUid();
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uid);
		$this->assertNotSame($uid, $this->identity()->newContactUid());
	}//end testANewContactUidIsARandomUuid()

	/**
	 * The employee number wins over the e-mail address, which wins over the name.
	 *
	 * @return void
	 */
	public function testTheCacheKeyFollowsTheMatchOrder(): void {
		$identity = $this->identity();
		$this->assertSame('uid:x', $identity->cacheKey(contactUid: 'x', email: 'A@example.org', displayName: 'Naam'));
		$this->assertSame('email:a@example.org', $identity->cacheKey(contactUid: null, email: 'A@example.org', displayName: 'Naam'));
		$this->assertSame('name:naam', $identity->cacheKey(contactUid: null, email: '', displayName: 'Naam'));
	}//end testTheCacheKeyFollowsTheMatchOrder()
}//end class
