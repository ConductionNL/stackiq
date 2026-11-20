<?php

/**
 * Who may verify a compliance claim, and when it returns to claimed.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ComplianceVerification;
use PHPUnit\Framework\TestCase;

/**
 * Covers ComplianceVerification.
 *
 * @covers \OCA\Stackiq\Service\ComplianceVerification
 */
final class ComplianceVerificationTest extends TestCase {

	private const EVIDENCED = ['url' => 'https://example.org/report.pdf'];

	/**
	 * Areviewer of another organisation may verify an evidenced claim.
	 *
	 * @return void
	 */
	public function testAReviewerOfAnotherOrganisationMayVerifyAnEvidencedClaim(): void {
		$this->assertNull(
			actual: ComplianceVerification::refusal(
				groups: ['functioneel-beheerder'],
				organisation: 'org-gemeente',
				supplier: 'org-supplier',
				record: self::EVIDENCED
			)
		);
	}

	/**
	 * The supplier cannot verify its own claim.
	 *
	 * @return void
	 */
	public function testTheSupplierCannotVerifyItsOwnClaim(): void {
		$this->assertSame(
			expected: [403, 'A supplier cannot verify its own claim'],
			actual: ComplianceVerification::refusal(
				groups: ['software-catalog-admins'],
				organisation: 'org-supplier',
				supplier: 'org-supplier',
				record: self::EVIDENCED
			)
		);
	}

	/**
	 * Refusals without review rights organisation supplier or evidence.
	 *
	 * @return void
	 */
	public function testRefusalsWithoutReviewRightsOrganisationSupplierOrEvidence(): void {
		$this->assertSame(
			expected: 403,
			actual: ComplianceVerification::refusal(
				groups: ['aanbod-beheerder'],
				organisation: 'org-g',
				supplier: 'org-s',
				record: self::EVIDENCED
			)[0]
		);
		$this->assertSame(
			expected: [403, 'A reviewer needs an active organisation'],
			actual: ComplianceVerification::refusal(groups: ['admin'], organisation: '', supplier: 'org-s', record: self::EVIDENCED)
		);
		$this->assertSame(
			expected: 409,
			actual: ComplianceVerification::refusal(groups: ['admin'], organisation: 'org-g', supplier: null, record: self::EVIDENCED)[0]
		);
		$this->assertSame(
			expected: [422, 'A claim without evidence cannot be verified'],
			actual: ComplianceVerification::refusal(
				groups: ['admin'],
				organisation: 'org-g',
				supplier: 'org-s',
				record: ['url' => '  ', 'evidence' => []]
			)
		);
	}

	/**
	 * Evidence is any of the three fields.
	 *
	 * @return void
	 */
	public function testEvidenceIsAnyOfTheThreeFields(): void {
		$this->assertTrue(condition: ComplianceVerification::hasEvidence(record: ['evidenceReference' => 'files/1']));
		$this->assertTrue(condition: ComplianceVerification::hasEvidence(record: ['evidence' => ['name' => 'x.pdf']]));
		$this->assertFalse(condition: ComplianceVerification::hasEvidence(record: ['evidence' => null, 'url' => '']));
	}

	/**
	 * Changed evidence on averified claim clears the verification.
	 *
	 * @return void
	 */
	public function testChangedEvidenceOnAVerifiedClaimClearsTheVerification(): void {
		$old = ['url' => 'https://a', 'verifiedBy' => 'Reviewer', 'verifiedAt' => '2026-11-17T10:00:00Z'];

		$this->assertSame(
			expected: ['verifiedBy' => null, 'verifiedAt' => null, 'verifiedByOrganisation' => null],
			actual: ComplianceVerification::resetOnEvidenceChange(new: ['url' => 'https://b'] + $old, old: $old)
		);
		$this->assertSame(expected: [], actual: ComplianceVerification::resetOnEvidenceChange(new: $old, old: $old));
		$this->assertSame(
			expected: [],
			actual: ComplianceVerification::resetOnEvidenceChange(new: ['url' => 'https://b'], old: ['url' => 'https://a'])
		);
	}

	/**
	 * Awrite that sets or changes the verification is detected.
	 *
	 * @return void
	 */
	public function testAWriteThatSetsOrChangesTheVerificationIsDetected(): void {
		$this->assertTrue(condition: ComplianceVerification::changesVerification(new: ['verifiedBy' => 'Me'], old: []));
		$this->assertTrue(condition: ComplianceVerification::changesVerification(new: ['verifiedBy' => 'Me'], old: ['verifiedBy' => 'Reviewer']));
		$this->assertFalse(
			condition: ComplianceVerification::changesVerification(
				new: ['verifiedBy' => 'Reviewer', 'name' => 'x'],
				old: ['verifiedBy' => 'Reviewer']
			)
		);
		$this->assertFalse(condition: ComplianceVerification::changesVerification(new: ['verifiedBy' => ''], old: []));
	}
}
