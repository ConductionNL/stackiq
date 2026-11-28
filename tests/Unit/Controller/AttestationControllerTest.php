<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Stackiq\Controller\AttestationController;
use DomainException;
use OCA\Stackiq\Service\AttestationAnswerService;
use OCA\Stackiq\Service\AttestationRoundAccess;
use OutOfBoundsException;
use UnexpectedValueException;
use OCA\Stackiq\Service\AttestationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-OAT-001: only an admin or a maintainer of the organisation starts a round, checked before anything is read.
 *
 * @covers \OCA\Stackiq\Controller\AttestationController
 * @covers \OCA\Stackiq\Service\AttestationRoundAccess
 * @spec   openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 */
final class AttestationControllerTest extends TestCase {

	private const PARAMS = ['organisation' => 'org-1', 'scopeSchema' => 'usage', 'deadline' => '2026-12-01', 'name' => ' Q4 '];

	/**
	 * A user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * An organisation double.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return object
	 */
	private static function organisation(string $uuid): object {
		return new class($uuid) {
			public function __construct(private string $uuid) {
			}

			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end organisation()

	/**
	 * The access check for a caller with these groups and memberships.
	 *
	 * @param bool                   $admin       Whether the caller is a Nextcloud admin.
	 * @param array<int, string>     $groups      The caller's groups.
	 * @param array<int, string>|null $memberships The caller's organisations, or null when they cannot be read.
	 *
	 * @return AttestationRoundAccess
	 */
	private function access(bool $admin, array $groups, ?array $memberships): AttestationRoundAccess {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($admin);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => in_array($gid, $groups, true));

		$organisations = new class($memberships) {
			public function __construct(private ?array $memberships) {
			}

			public function getUserOrganisations(): array {
				if ($this->memberships === null) {
					throw new RuntimeException('session gone');
				}

				return array_map(static fn (string $uuid): object => AttestationControllerTest::organisationFor($uuid), $this->memberships);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\OrganisationService')->willReturn($organisations);

		return new AttestationRoundAccess($groupManager, $container, $this->createMock(LoggerInterface::class));
	}//end access()

	/**
	 * Public wrapper so the anonymous double can build organisations.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return object
	 */
	public static function organisationFor(string $uuid): object {
		return self::organisation($uuid);
	}//end organisationFor()

	/**
	 * The controller for a caller.
	 *
	 * @param IUser|null             $user    The signed-in user.
	 * @param AttestationRoundAccess $access  The access check.
	 * @param AttestationService     $service The round service.
	 * @param array<string, string>  $params  The request parameters.
	 * @param AttestationAnswerService|null $answers The answer service, a double when omitted.
	 *
	 * @return AttestationController
	 */
	private function controller(?IUser $user, AttestationRoundAccess $access, AttestationService $service, array $params = self::PARAMS, ?AttestationAnswerService $answers = null): AttestationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AttestationController('stackiq', $request, $session, $access, $service, $this->createMock(LoggerInterface::class), $answers ?? $this->createMock(AttestationAnswerService::class));
	}//end controller()

	/**
	 * A user who is no admin and no maintainer is refused with 403 before anything is read.
	 *
	 * @return void
	 */
	public function testANonMaintainerIsRefused(): void {
		$service = $this->createMock(AttestationService::class);
		$service->expects($this->never())->method('start');

		$response = $this->controller($this->user('eve'), $this->access(false, ['gebruik-beheerder'], ['org-1']), $service)->start();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testANonMaintainerIsRefused()

	/**
	 * A maintainer of another organisation, or whose memberships cannot be read, is refused.
	 *
	 * @return void
	 */
	public function testAMaintainerOfAnotherOrganisationIsRefused(): void {
		$service = $this->createMock(AttestationService::class);
		$service->expects($this->never())->method('start');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($this->user('max'), $this->access(false, ['maintainer'], ['org-2']), $service)->start()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($this->user('max'), $this->access(false, ['maintainer'], null), $service)->start()->getStatus());
		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller($this->user('max'), $this->access(true, [], []), $service, ['scopeSchema' => 'usage'])->start()->getStatus(),
			'no organisation is never allowed'
		);
	}//end testAMaintainerOfAnotherOrganisationIsRefused()

	/**
	 * A maintainer of the organisation starts the round; the answer lists the unassigned entries.
	 *
	 * @return void
	 */
	public function testAMaintainerOfTheOrganisationStartsTheRound(): void {
		$result = ['round' => ['id' => 'r1'], 'requestCount' => 4, 'unassigned' => [['entryId' => 'u5', 'entryName' => 'DMS']]];
		$service = $this->createMock(AttestationService::class);
		$service->expects($this->once())->method('start')->with('usage', 'org-1', '2026-12-01', 'Q4', 'max')->willReturn($result);

		$response = $this->controller($this->user('max'), $this->access(false, ['maintainer'], ['org-2', 'org-1']), $service)->start();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame($result, $response->getData());
	}//end testAMaintainerOfTheOrganisationStartsTheRound()

	/**
	 * An admin needs no membership.
	 *
	 * @return void
	 */
	public function testAnAdminStartsTheRound(): void {
		$service = $this->createMock(AttestationService::class);
		$service->expects($this->once())->method('start')->willReturn(['round' => [], 'requestCount' => 0, 'unassigned' => []]);

		self::assertSame(Http::STATUS_CREATED, $this->controller($this->user('root'), $this->access(true, [], null), $service)->start()->getStatus());
	}//end testAnAdminStartsTheRound()

	/**
	 * Without a user the answer is 401; invalid input 400; a failure 500 without its message.
	 *
	 * @return void
	 */
	public function testTheErrorAnswers(): void {
		$service = $this->createMock(AttestationService::class);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null, $this->access(true, [], []), $service)->start()->getStatus());

		$invalid = $this->createMock(AttestationService::class);
		$invalid->method('start')->willThrowException(new InvalidArgumentException('deadline must be a date as YYYY-MM-DD'));
		$response = $this->controller($this->user('root'), $this->access(true, [], []), $invalid)->start();
		self::assertSame([Http::STATUS_BAD_REQUEST, ['message' => 'deadline must be a date as YYYY-MM-DD']], [$response->getStatus(), $response->getData()]);

		$broken = $this->createMock(AttestationService::class);
		$broken->method('start')->willThrowException(new RuntimeException('SQLSTATE secret'));
		$response = $this->controller($this->user('root'), $this->access(true, [], []), $broken)->start();
		self::assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['message' => 'The round could not be started']], [$response->getStatus(), $response->getData()]);
	}//end testTheErrorAnswers()

	/**
	 * Confirm passes the signed-in user to the service and answers the confirmed request.
	 *
	 * @return void
	 */
	public function testTheOwnerConfirmsTheirRequest(): void {
		$answers = $this->createMock(AttestationAnswerService::class);
		$answers->expects(self::once())->method('confirm')->with('req-1', 'anna')->willReturn(['id' => 'req-1', 'status' => 'confirmed']);

		$response = $this->controller($this->user('anna'), $this->access(false, [], []), $this->createMock(AttestationService::class), [], $answers)->confirm('req-1');

		self::assertSame([Http::STATUS_OK, ['id' => 'req-1', 'status' => 'confirmed']], [$response->getStatus(), $response->getData()]);
	}//end testTheOwnerConfirmsTheirRequest()

	/**
	 * Without a session the service is never asked; each refusal maps to its status.
	 *
	 * @return void
	 */
	public function testTheConfirmRefusals(): void {
		$never = $this->createMock(AttestationAnswerService::class);
		$never->expects(self::never())->method('confirm');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null, $this->access(false, [], []), $this->createMock(AttestationService::class), [], $never)->confirm('req-1')->getStatus());

		$cases = [
			[new DomainException('Only the owner this request was sent to can answer it'), Http::STATUS_FORBIDDEN],
			[new OutOfBoundsException('Confirmation request not found'), Http::STATUS_NOT_FOUND],
			[new UnexpectedValueException('This request was already answered'), Http::STATUS_CONFLICT],
			[new RuntimeException('database gone'), Http::STATUS_INTERNAL_SERVER_ERROR],
		];
		foreach ($cases as [$exception, $status]) {
			$answers = $this->createMock(AttestationAnswerService::class);
			$answers->method('confirm')->willThrowException($exception);
			$response = $this->controller($this->user('bob'), $this->access(false, [], []), $this->createMock(AttestationService::class), [], $answers)->confirm('req-1');
			$message  = $exception->getMessage();
			if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
				$message = 'The request could not be confirmed';
			}

			self::assertSame([$status, ['message' => $message]], [$response->getStatus(), $response->getData()]);
		}
	}//end testTheConfirmRefusals()
}//end class
