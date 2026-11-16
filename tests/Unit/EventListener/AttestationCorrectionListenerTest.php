<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\AttestationCorrectionListener;
use OCA\Stackiq\Kernel\Contracts\AttestationAnswerServiceInterface;
use OCA\Stackiq\Service\AttestationAnswerService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-OAT-003: the owner's own edit of an entry answers their request; a colleague's edit and a confirmation do not.
 *
 * @covers \OCA\Stackiq\EventListener\AttestationCorrectionListener
 * @uses   \OCA\Stackiq\Service\AttestationAnswerService
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 */
final class AttestationCorrectionListenerTest extends TestCase {

	/** @var AttestationAnswerService&MockObject */
	private AttestationAnswerService $answers;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Prepare the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->answers = $this->createPartialMock(AttestationAnswerService::class, ['markCorrected']);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The listener, with the given user signed in.
	 *
	 * @param string|null $uid The signed-in user, or null for none.
	 *
	 * @return AttestationCorrectionListener
	 */
	private function listener(?string $uid): AttestationCorrectionListener {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): ?int => (['usage' => 21, 'module' => 22][$type] ?? null));

		$services = [
			IUserSession::class               => $session,
			ObjectTypeLocatorInterface::class => $locator,
			AttestationAnswerServiceInterface::class => $this->answers,
			LoggerInterface::class            => $this->logger,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new AttestationCorrectionListener($container);
	}//end listener()

	/**
	 * An object entity double.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn('u1');
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Anna changes the version of her usage: her request for it is marked corrected (spec scenario).
	 *
	 * @return void
	 */
	public function testTheOwnersEditMarksTheRequestCorrected(): void {
		$this->answers->expects(self::once())->method('markCorrected')->with('usage', 'u1', 'anna')->willReturn(1);

		$this->listener('anna')->handle(new ObjectUpdatedEvent($this->entity('21', ['version' => '2.0']), $this->entity('21', ['version' => '1.0'])));
	}//end testTheOwnersEditMarksTheRequestCorrected()

	/**
	 * A colleague's edit is passed with the colleague's uid, so only the colleague's own requests can move.
	 *
	 * @return void
	 */
	public function testAColleaguesEditIsPassedAsTheColleague(): void {
		$this->answers->expects(self::once())->method('markCorrected')->with('module', 'u1', 'carl')->willReturn(0);

		$this->listener('carl')->handle(new ObjectUpdatedEvent($this->entity('22', ['name' => 'B']), $this->entity('22', ['name' => 'A'])));
	}//end testAColleaguesEditIsPassedAsTheColleague()

	/**
	 * A confirmation (only lastConfirmedAt and the score changed), a create, another schema and a save without a user answer nothing.
	 *
	 * @return void
	 */
	public function testNothingElseAnswersARequest(): void {
		$this->answers->expects(self::never())->method('markCorrected');

		$this->listener('anna')->handle(new ObjectUpdatedEvent(
			$this->entity('21', ['version' => '1.0', 'lastConfirmedAt' => '2026-11-14T10:00:00Z', 'qualityScore' => 0.9]),
			$this->entity('21', ['version' => '1.0', 'lastConfirmedAt' => '2025-01-01T00:00:00Z', 'qualityScore' => 0.5])
		));
		$this->listener('anna')->handle(new ObjectCreatedEvent($this->entity('21', ['version' => '1.0'])));
		$this->listener('anna')->handle(new ObjectUpdatedEvent($this->entity('99', ['a' => 2]), $this->entity('99', ['a' => 1])));
		$this->listener(null)->handle(new ObjectUpdatedEvent($this->entity('21', ['version' => '2.0']), $this->entity('21', ['version' => '1.0'])));
	}//end testNothingElseAnswersARequest()

	/**
	 * A failing answer is logged, never thrown into OpenRegister's save.
	 *
	 * @return void
	 */
	public function testAFailureIsLogged(): void {
		$this->answers->method('markCorrected')->willThrowException(new RuntimeException('down'));
		$this->logger->expects(self::once())->method('error')->with('AttestationCorrectionListener: could not mark the request corrected', ['objectId' => 'u1', 'exception' => 'down']);

		$this->listener('anna')->handle(new ObjectUpdatedEvent($this->entity('21', ['version' => '2.0']), $this->entity('21', ['version' => '1.0'])));
	}//end testAFailureIsLogged()
}//end class
