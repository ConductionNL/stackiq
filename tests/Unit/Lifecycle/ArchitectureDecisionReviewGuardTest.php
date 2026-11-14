<?php

/**
 * Unit tests for ArchitectureDecisionReviewGuard.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/architecture-decision-register/tasks.md#task-2-review-guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Lifecycle\ArchitectureDecisionReviewGuard;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The review rules: submit, accept and reject, supersede.
 *
 * @covers \OCA\Stackiq\Lifecycle\ArchitectureDecisionReviewGuard
 */
class ArchitectureDecisionReviewGuardTest extends TestCase {

	/**
	 * Users that exist.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * Resolves the object service.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators; users `anna` and `bram` exist.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->users = $this->createMock(originalClassName: IUserManager::class);
		$this->users->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['anna', 'bram'], true)
		);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger    = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The guard under test.
	 *
	 * @return ArchitectureDecisionReviewGuard
	 */
	private function guard(): ArchitectureDecisionReviewGuard {
		return new ArchitectureDecisionReviewGuard(
			userManager: $this->users,
			container: $this->container,
			logger: $this->logger
		);
	}//end guard()

	/**
	 * Submit passes with a reviewer who is another existing user.
	 *
	 * @return void
	 */
	public function testSubmitWithAColleagueAsReviewerIsAllowed(): void {
		$result = $this->guard()->check(object: ['title' => 'x', 'reviewer' => 'bram'], action: 'submit', userId: 'anna');

		$this->assertTrue($result->isAllowed());
		$this->assertNull($result->getMessage());
	}//end testSubmitWithAColleagueAsReviewerIsAllowed()

	/**
	 * Submit is denied without a reviewer, with an unknown one, and with the caller as reviewer.
	 *
	 * @return void
	 */
	public function testSubmitIsDeniedWithoutAValidSecondReviewer(): void {
		$cases = [
			'empty'   => [['reviewer' => ''], 'Name a reviewer before you submit the decision.'],
			'missing' => [[], 'Name a reviewer before you submit the decision.'],
			'unknown' => [['reviewer' => 'nobody'], 'The reviewer is not a user of this Nextcloud.'],
			'self'    => [['reviewer' => 'anna'], 'The reviewer must be someone other than you.'],
		];

		foreach ($cases as $name => [$object, $message]) {
			$result = $this->guard()->check(object: $object, action: 'submit', userId: 'anna');
			$this->assertFalse($result->isAllowed(), $name);
			$this->assertSame($message, $result->getMessage(), $name);
		}
	}//end testSubmitIsDeniedWithoutAValidSecondReviewer()

	/**
	 * Only the reviewer accepts or rejects.
	 *
	 * @return void
	 */
	public function testOnlyTheReviewerAcceptsOrRejects(): void {
		foreach (['accept', 'reject'] as $action) {
			$this->assertTrue(
				$this->guard()->check(object: ['reviewer' => 'bram'], action: $action, userId: 'bram')->isAllowed(),
				$action
			);

			$denied = $this->guard()->check(object: ['reviewer' => 'bram'], action: $action, userId: 'anna');
			$this->assertFalse($denied->isAllowed(), $action);
			$this->assertSame('Only the reviewer of the decision can accept or reject it.', $denied->getMessage());

			$this->assertFalse(
				$this->guard()->check(object: ['reviewer' => ''], action: $action, userId: '')->isAllowed(),
				$action . ' without reviewer'
			);
		}
	}//end testOnlyTheReviewerAcceptsOrRejects()

	/**
	 * Supersede reads an embedded superseding decision without a request.
	 *
	 * @return void
	 */
	public function testSupersedeReadsAnEmbeddedDecision(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertTrue(
			$this->guard()->check(object: ['supersededBy' => ['id' => 'u2', 'status' => 'accepted']], action: 'supersede', userId: 'anna')->isAllowed()
		);

		$denied = $this->guard()->check(object: ['supersededBy' => ['id' => 'u2', 'status' => 'draft']], action: 'supersede', userId: 'anna');
		$this->assertFalse($denied->isAllowed());
		$this->assertStringContainsString('accepted decision', (string) $denied->getMessage());
	}//end testSupersedeReadsAnEmbeddedDecision()

	/**
	 * A superseding decision named by uuid is fetched from the stackiq register.
	 *
	 * @return void
	 */
	public function testSupersedeFetchesADecisionNamedByUuid(): void {
		$this->expectFind(uuid: 'u2', status: 'accepted');

		$this->assertTrue(
			$this->guard()->check(object: ['supersededBy' => 'u2'], action: 'supersede', userId: 'anna')->isAllowed()
		);
	}//end testSupersedeFetchesADecisionNamedByUuid()

	/**
	 * A draft superseding decision, none, or one that cannot be read, is denied.
	 *
	 * @return void
	 */
	public function testSupersedeIsDeniedUnlessTheSupersedingDecisionIsAccepted(): void {
		$this->expectFind(uuid: 'u3', status: 'draft');
		$this->assertFalse($this->guard()->check(object: ['supersededBy' => 'u3'], action: 'supersede', userId: 'a')->isAllowed());

		$this->assertFalse($this->guard()->check(object: [], action: 'supersede', userId: 'a')->isAllowed());
		$this->assertFalse($this->guard()->check(object: ['supersededBy' => ''], action: 'supersede', userId: 'a')->isAllowed());
	}//end testSupersedeIsDeniedUnlessTheSupersedingDecisionIsAccepted()

	/**
	 * A read failure fails closed and is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableSupersedingDecisionFailsClosed(): void {
		$service = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$service->method('find')->willThrowException(new RuntimeException('gone'));
		$this->container->method('get')->willReturn($service);
		$this->logger->expects($this->once())->method('warning');

		$this->assertFalse($this->guard()->check(object: ['supersededBy' => 'u4'], action: 'supersede', userId: 'a')->isAllowed());
	}//end testAnUnreadableSupersedingDecisionFailsClosed()

	/**
	 * Other actions pass, and the guard reads without writing.
	 *
	 * @return void
	 */
	public function testOtherActionsPassWithoutWriting(): void {
		$service = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$service->expects($this->never())->method('saveObject');
		$service->expects($this->never())->method('deleteObject');
		$this->container->method('get')->willReturn($service);
		$this->users->expects($this->never())->method('createUser');

		foreach (['rework', 'deprecate', 'anything'] as $action) {
			$this->assertTrue($this->guard()->check(object: ['reviewer' => 'anna'], action: $action, userId: 'anna')->isAllowed(), $action);
		}
	}//end testOtherActionsPassWithoutWriting()

	/**
	 * Make the object service find one decision by uuid in the stackiq register.
	 *
	 * @param string $uuid The uuid.
	 * @param string $status Its status.
	 *
	 * @return void
	 */
	private function expectFind(string $uuid, string $status): void {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(['status' => $status]);

		$service = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$service->expects($this->once())->method('find')
			->with($uuid, [], false, 'stackiq', 'architectureDecision')
			->willReturn($entity);

		$this->container->expects($this->once())->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($service);
	}//end expectFind()
}//end class
