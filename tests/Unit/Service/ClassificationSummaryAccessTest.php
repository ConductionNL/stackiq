<?php

/**
 * ClassificationSummaryAccessTest
 *
 * Who may read how organisations classify an application
 * (security-baseline-classification REQ-BCL-004).
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Kernel\Contracts\OpenRegisterLookupInterface;
use OCA\Stackiq\Service\ClassificationSummaryAccess;
use OCA\Stackiq\Service\UsageClassificationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClassificationSummaryAccess.
 *
 * @covers \OCA\Stackiq\Service\ClassificationSummaryAccess
 */
class ClassificationSummaryAccessTest extends TestCase {

	/**
	 * The access check for a caller.
	 *
	 * @param string|null        $organisation The active organisation.
	 * @param array<int, string> $groups       The caller's groups.
	 * @param string|null        $uid          The signed-in user, or null.
	 *
	 * @return ClassificationSummaryAccess
	 */
	private function access(?string $organisation, array $groups = [], ?string $uid = 'someone'): ClassificationSummaryAccess {
		$classification = $this->createMock(UsageClassificationService::class);
		$classification->method('organisationTypeOf')->willReturnCallback(
			static fn (string $id): ?string => ['org-sup' => 'Supplier', 'org-gem' => 'Municipality', 'org-sam' => 'Collaboration'][$id] ?? null
		);
		$lookup = $this->createMock(OpenRegisterLookupInterface::class);
		$lookup->method('activeOrganisationUuid')->willReturn($organisation);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $u): bool => $u === 'someone' && in_array('admin', $groups, true));
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $u, string $gid): bool => $u === 'someone' && in_array($gid, $groups, true));

		return new ClassificationSummaryAccess(classification: $classification, lookup: $lookup, userSession: $session, groupManager: $groupManager);
	}//end access()

	/**
	 * The caller id is the session user's uid, or null without one.
	 *
	 * @return void
	 */
	public function testTheCallerIsTheSessionUser(): void {
		$this->assertSame('alice', $this->access(organisation: null, uid: 'alice')->callerId());
		$this->assertNull($this->access(organisation: null, uid: null)->callerId());
	}//end testTheCallerIsTheSessionUser()

	/**
	 * Municipalities, collaborations, ambtenaar and admins may read; suppliers and callers without an organisation may not.
	 *
	 * @return void
	 */
	public function testOnlyMunicipalReadersMayRead(): void {
		$this->assertTrue($this->access(organisation: 'org-gem')->mayReadSummary(userId: 'someone'));
		$this->assertTrue($this->access(organisation: 'org-sam')->mayReadSummary(userId: 'someone'));
		$this->assertTrue($this->access(organisation: 'org-sup', groups: ['ambtenaar'])->mayReadSummary(userId: 'someone'));
		$this->assertTrue($this->access(organisation: null, groups: ['admin'])->mayReadSummary(userId: 'someone'));
		$this->assertFalse($this->access(organisation: 'org-sup')->mayReadSummary(userId: 'someone'));
		$this->assertFalse($this->access(organisation: null)->mayReadSummary(userId: 'someone'));
		$this->assertFalse($this->access(organisation: 'org-unknown')->mayReadSummary(userId: 'someone'));
	}//end testOnlyMunicipalReadersMayRead()
}//end class
