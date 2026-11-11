<?php

/**
 * Golden test for the admin guard of SettingsController.
 *
 * Pins, for every action that requires an administrator, the refusal of an
 * anonymous caller (401) and of a signed-in non-admin (403), and for the six
 * user-group actions also the allowed outcome and the failure path: status,
 * body, service calls and log lines.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Records every log call as [level, message, context].
 */
class SettingsAdminGuardLogSpy extends AbstractLogger {
	/**
	 * Recorded log calls.
	 *
	 * @var array
	 */
	public array $records = [];

	/**
	 * Record one log call.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array              $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		$this->records[] = [(string)$level, (string)$message, $context];
	}
}

/**
 * Golden admin guard.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
class SettingsControllerAdminGuardGoldenTest extends TestCase {
	/**
	 * The actions that require an administrator.
	 */
	private const ADMIN_ACTIONS = [
		'importArchiMate',
		'getEmailSettings',
		'updateEmailSettings',
		'getGenericUserGroups',
		'setGenericUserGroups',
		'getOrganizationAdminGroups',
		'setOrganizationAdminGroups',
		'getSuperUserGroups',
		'setSuperUserGroups',
		'getAllGroups',
		'clearArchiMateImportStatus',
		'killArchiMateImport',
		'cancelArchiMateImport',
		'clearArchiMateExportStatus',
		'getEmailConfig',
		'getUserGroupsConfig',
		'updateUserGroupsConfig',
		'bulkSyncStandards',
	];

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null $uid      The caller, null for anonymous.
	 * @param array       $scenario isAdmin, params, service (method => result or 'throw').
	 *
	 * @return array{0: SettingsController, 1: SettingsAdminGuardLogSpy, 2: \ArrayObject}
	 */
	private function makeController(?string $uid, array $scenario): array {
		$calls    = new \ArrayObject();
		$settings = $this->createMock(SettingsService::class);
		foreach (($scenario['service'] ?? []) as $method => $result) {
			$settings->method($method)->willReturnCallback(
				function (...$args) use ($calls, $method, $result) {
					$calls[] = [$method, $args];
					if ($result === 'throw') {
						throw new \RuntimeException('service down');
					}

					return $result;
				}
			);
		}

		$groups  = $this->createMock(IGroupManager::class);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$groups->method('isAdmin')->willReturnCallback(
				function (string $id) use ($calls, $scenario) {
					$calls[] = ['isAdmin', [$id]];
					return $scenario['isAdmin'];
				}
			);
		}

		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($scenario['params'] ?? []);
		$log = new SettingsAdminGuardLogSpy();

		$controller = new SettingsController(
			'stackiq',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$groups,
			$session,
			$settings,
			$this->createMock(OrganizationSyncService::class),
			$this->createMock(ArchiMateService::class),
			$this->createMock(ProgressTracker::class),
			$this->createMock(ArchiMateImportControl::class),
			$this->createMock(EolSyncService::class),
			$log
		);
		return [$controller, $log, $calls];
	}

	/**
	 * Call one action and record the outcome.
	 *
	 * @param string      $action   The action.
	 * @param string|null $uid      The caller.
	 * @param array       $scenario The scenario.
	 *
	 * @return array
	 */
	private function outcome(string $action, ?string $uid, array $scenario): array {
		[$controller, $log, $calls] = $this->makeController(uid: $uid, scenario: $scenario);
		$response = $controller->$action();
		return [$response->getStatus(), $response->getData(), $calls->getArrayCopy(), $log->records];
	}

	/**
	 * Every admin action refuses an anonymous caller and a non-admin.
	 *
	 * @return void
	 */
	public function testEveryAdminActionRefusesAnonymousAndNonAdmins(): void {
		foreach (self::ADMIN_ACTIONS as $action) {
			$this->assertSame(
				[401, ['message' => 'Not authenticated'], [], []],
				$this->outcome(action: $action, uid: null, scenario: ['isAdmin' => false]),
				$action . ' anonymous'
			);
			$this->assertSame(
				[403, ['message' => 'Admin privileges required'], [['isAdmin', ['bob']]], []],
				$this->outcome(action: $action, uid: 'bob', scenario: ['isAdmin' => false]),
				$action . ' non-admin'
			);
		}
	}

	/**
	 * The user-group actions for an administrator: success and failure.
	 *
	 * @return void
	 */
	public function testUserGroupActionsForAnAdmin(): void {
		$cases = [
			'getGenericUserGroups' => ['getGenericUserGroups', ['g1']],
			'getOrganizationAdminGroups' => ['getOrganizationAdminGroups', ['g2']],
			'getSuperUserGroups' => ['getSuperUserGroups', ['g3']],
			'setGenericUserGroups' => ['updateGenericUserGroups', ['success' => true, 'message' => 'ok', 'groups' => ['g1']]],
			'setOrganizationAdminGroups' => ['updateOrganizationAdminGroups', ['success' => false, 'message' => 'bad group']],
			'setSuperUserGroups' => ['updateSuperUserGroups', ['success' => true, 'message' => 'ok', 'groups' => ['g3']]],
		];
		$params = ['groups' => ['g1', 'g9'], 'smtpPassword' => 'secret', 'note' => 'n'];
		$out    = [];
		foreach ($cases as $action => [$serviceMethod, $result]) {
			foreach (['ok' => $result, 'throws' => 'throw'] as $name => $answer) {
				$out[$action . ' ' . $name] = $this->outcome(
					action: $action,
					uid: 'admin',
					scenario: ['isAdmin' => true, 'params' => $params, 'service' => [$serviceMethod => $answer]]
				);
			}
		}

		$path = dirname(__DIR__, 2) . '/fixtures/settings/admin-user-groups.json';
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $out);
	}
}
