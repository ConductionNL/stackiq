<?php

/**
 * Golden test for ContractApprovalController::authorizeContract().
 *
 * Records, per caller, whether raising a decision for a contract is allowed
 * (null) or refused (status and body), the groups and active organisation
 * handed to the ownership check, and the log line written.
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

use OCA\Stackiq\Controller\ContractApprovalController;
use OCA\Stackiq\Service\ContractApprovalService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused outcomes of the contract submit guard.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 */
final class ContractApprovalGuardGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/contract-approval/guard.json';

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		return [
			'anonymous'           => ['user' => null],
			'owner'               => ['user' => 'vera', 'groups' => ['aanbod-beheerder'], 'org' => 'org-1', 'authorized' => true],
			'admin'               => ['user' => 'admin', 'groups' => ['admin', 'users'], 'org' => '', 'authorized' => true],
			'not the owner'       => ['user' => 'vera', 'groups' => ['aanbod-beheerder'], 'org' => 'org-2', 'authorized' => false],
			'no groups'           => ['user' => 'piet', 'groups' => [], 'org' => 'org-1', 'authorized' => false],
			'numeric org setting' => ['user' => 'piet', 'groups' => ['users'], 'org' => 7, 'authorized' => false],
			'other contract'      => ['user' => 'vera', 'groups' => ['aanbod-beheerder'], 'org' => 'org-1', 'authorized' => false, 'contract' => 'contract-2'],
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
		$groups->method('getUserGroups')->willReturnCallback(function () use (&$trace, $case): array {
			$trace[] = ['getUserGroups'];
			return array_map(function (string $gid): IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('getGID')->willReturn($gid);
				return $group;
			}, $case['groups']);
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(function (...$args) use (&$trace, $case) {
			$trace[] = ['getUserValue', $args];
			return $case['org'];
		});
		$approvals = $this->createMock(ContractApprovalService::class);
		$approvals->method('authorizeSubmit')->willReturnCallback(function (...$args) use (&$trace, $case): bool {
			$trace[] = ['authorizeSubmit', $args];
			return $case['authorized'];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$trace): void {
			$trace[] = ['warning', $message, $context];
		});

		$controller = new ContractApprovalController($this->createMock(IRequest::class), $approvals, $session, $groups, $config, $logger);
		$method     = new \ReflectionMethod($controller, 'authorizeContract');
		$method->setAccessible(true);
		$response = $method->invoke($controller, ($case['contract'] ?? 'contract-1'));

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
