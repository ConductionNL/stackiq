<?php

/**
 * Golden test for OrganisationMembersController::authorizeMaintainer().
 *
 * Records, per caller, whether managing an organisation's members is
 * allowed (null) or refused (status and body), the checks made and the
 * log lines written.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\OrganisationMembersController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused outcomes of the member-management guard.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 */
final class OrganisationMembersGuardGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/organisation-members/guard.json';

	/**
	 * The cases: caller, group membership, the caller's organisations, the target.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		return [
			'anonymous'               => ['user' => null],
			'not a beheerder'         => ['user' => 'anne', 'beheerder' => false, 'orgs' => ['org-1']],
			'beheerder of target'     => ['user' => 'bob', 'beheerder' => true, 'orgs' => ['org-1']],
			'beheerder, target later' => ['user' => 'bob', 'beheerder' => true, 'orgs' => ['org-2', 'org-3', 'org-1']],
			'beheerder elsewhere'     => ['user' => 'bob', 'beheerder' => true, 'orgs' => ['org-2']],
			'beheerder of none'       => ['user' => 'bob', 'beheerder' => true, 'orgs' => []],
			'case differs'            => ['user' => 'bob', 'beheerder' => true, 'orgs' => ['ORG-1']],
			'empty target'            => ['user' => 'bob', 'beheerder' => true, 'orgs' => ['org-1'], 'target' => ''],
			'lookup fails'            => ['user' => 'bob', 'beheerder' => true, 'orgsFail' => true],
		];
	}//end cases()

	/**
	 * Run one case and record the outcome.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $case): array {
		$trace   = [];
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($case['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(function (string $uid, string $group) use (&$trace, $case): bool {
			$trace[] = ['isInGroup', $uid, $group];
			return $case['beheerder'];
		});
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserOrganisations')->willReturnCallback(function () use (&$trace, $case): array {
			$trace[] = ['getUserOrganisations'];
			if (($case['orgsFail'] ?? false) === true) {
				throw new \RuntimeException('session lost');
			}

			return array_map(function (string $uuid): Organisation {
				$organisation = $this->createMock(Organisation::class);
				$organisation->method('getUuid')->willReturn($uuid);
				return $organisation;
			}, $case['orgs']);
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use (&$trace, $level): void {
				$trace[] = [$level, $message, $context];
			});
		}

		$controller = new OrganisationMembersController(
			$this->createMock(IRequest::class),
			$session,
			$groups,
			$this->createMock(IUserManager::class),
			$logger,
			$organisations
		);
		$method     = new \ReflectionMethod($controller, 'authorizeMaintainer');
		$method->setAccessible(true);
		$response = $method->invoke($controller, ($case['target'] ?? 'org-1'));

		$outcome = ['allowed' => true];
		if ($response !== null) {
			$outcome = ['allowed' => false, 'status' => $response->getStatus(), 'data' => $response->getData()];
		}

		return $outcome + ['trace' => $trace];
	}//end record()

	/**
	 * Every case matches the recorded outcome.
	 *
	 * @return void
	 */
	public function testGuardMatchesGolden(): void {
		$actual = [];
		$lines  = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
			$lines[]       = json_encode($name) . ': ' . json_encode($actual[$name], JSON_UNESCAPED_SLASHES);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));

	}//end testGuardMatchesGolden()
}//end class
