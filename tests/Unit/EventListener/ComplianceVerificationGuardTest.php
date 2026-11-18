<?php

/**
 * Ordinary writes cannot set a claim's verification; changed evidence resets it.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\EventListener
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

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\EventListener\ComplianceVerificationGuard;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers ComplianceVerificationGuard with the OpenRegister pre-save events.
 *
 * @covers \OCA\Stackiq\EventListener\ComplianceVerificationGuard
 * @uses   \OCA\Stackiq\Service\ComplianceVerification
 * @uses   \OCA\Stackiq\Service\ComplianceVerificationService
 */
final class ComplianceVerificationGuardTest extends TestCase {

	/**
	 * The guard, with compliancy schema 20.
	 *
	 * @return ComplianceVerificationGuard
	 */
	private function guard(): ComplianceVerificationGuard {
		$types = $this->createMock(ObjectTypeLocatorInterface::class);
		$types->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $t) => $t === 'compliancy' ? 20 : null);

		return new ComplianceVerificationGuard(types: $types);
	}//end guard()

	/**
	 * An object of a schema.
	 *
	 * @param array<string, mixed> $data   The data.
	 * @param int                  $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data, int $schema=20): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$entity->method('getSchema')->willReturn((string)$schema);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end object()

	public function testASupplierCannotCreateAClaimThatAlreadyReadsVerified(): void {
		$event = new ObjectCreatingEvent($this->object(data: ['url' => 'https://a', 'verifiedBy' => 'Supplier']));
		$this->guard()->handle($event);

		$this->assertSame(['message' => ComplianceVerificationGuard::REFUSED_MESSAGE], $event->getErrors());
		$this->assertTrue($event->isPropagationStopped());
	}

	public function testAnUpdateThatSetsTheVerificationIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->object(data: ['url' => 'https://a', 'verifiedBy' => 'Me', 'verifiedAt' => 'now']), $this->object(data: ['url' => 'https://a']));
		$this->guard()->handle($event);

		$this->assertSame(['message' => ComplianceVerificationGuard::REFUSED_MESSAGE], $event->getErrors());
	}

	public function testChangedEvidenceOnAVerifiedClaimReturnsItToClaimed(): void {
		$old = ['url' => 'https://a', 'verifiedBy' => 'Rita', 'verifiedAt' => '2026-11-17T10:00:00Z', 'verifiedByOrganisation' => 'org-g'];
		$event = new ObjectUpdatingEvent($this->object(data: ['url' => 'https://b'] + $old), $this->object(data: $old));
		$this->guard()->handle($event);

		$this->assertSame([], $event->getErrors());
		$this->assertSame(['verifiedBy' => null, 'verifiedAt' => null, 'verifiedByOrganisation' => null], $event->getModifiedData());
	}

	public function testAnOrdinaryEditKeepingTheVerificationAndOtherSchemasPass(): void {
		$old = ['url' => 'https://a', 'verifiedBy' => 'Rita'];
		$edit = new ObjectUpdatingEvent($this->object(data: $old + ['standardGemma' => 'x']), $this->object(data: $old));
		$other = new ObjectCreatingEvent($this->object(data: ['verifiedBy' => 'Me'], schema: 99));
		$this->guard()->handle($edit);
		$this->guard()->handle($other);

		$this->assertSame([[], [], [], []], [$edit->getErrors(), $edit->getModifiedData(), $other->getErrors(), $other->getModifiedData()]);
	}
}
