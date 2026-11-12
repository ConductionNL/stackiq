<?php

/**
 * Golden test for the per-section config endpoints of SettingsController.
 *
 * Pins, for the ArchiMate, email, AMEF, Voorzieningen, object-count,
 * object-statistics and user-groups config endpoints, the outcome for an
 * anonymous caller, a signed-in non-admin and an administrator, with the
 * service answering and with the service throwing: status, body, service
 * calls and log lines.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
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
class SettingsConfigLogSpy extends AbstractLogger {
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
 * Golden config endpoints.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
class SettingsControllerConfigEndpointsGoldenTest extends TestCase {
	/**
	 * Each action and the service method it delegates to.
	 */
	private const ACTIONS = [
		'getArchiMateConfig' => 'getArchiMateConfig',
		'updateArchiMateConfig' => 'updateArchiMateConfig',
		'getEmailConfig' => 'getEmailConfigFocused',
		'updateEmailConfig' => 'updateEmailConfig',
		'getAmefConfig' => 'getAmefConfigFocused',
		'updateAmefConfig' => 'updateAmefConfig',
		'getVoorzieningenConfig' => 'getVoorzieningenConfigFocused',
		'updateVoorzieningenConfig' => 'updateVoorzieningenConfig',
		'getObjectsCounts' => 'getObjectsCounts',
		'getObjectsStatistics' => 'getObjectsStatistics',
		'getUserGroupsConfig' => 'getUserGroupsConfig',
		'updateUserGroupsConfig' => 'updateUserGroupsConfig',
	];

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null $uid      The caller, null for anonymous.
	 * @param array       $scenario isAdmin, service (method => result or 'throw').
	 *
	 * @return array{0: SettingsController, 1: SettingsConfigLogSpy, 2: \ArrayObject}
	 */
	private function makeController(?string $uid, array $scenario): array {
		$calls    = new \ArrayObject();
		$settings = $this->createMock(originalClassName: SettingsService::class);
		foreach ($scenario['service'] as $method => $result) {
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

		$groups  = $this->createMock(originalClassName: IGroupManager::class);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$groups->method('isAdmin')->willReturnCallback(
				function (string $id) use ($calls, $scenario) {
					$calls[] = ['isAdmin', [$id]];
					return $scenario['isAdmin'];
				}
			);
		}

		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(['mode' => 'm', 'smtpPassword' => 'secret']);
		$log = new SettingsConfigLogSpy();

		$controller = new SettingsController(
			appName: 'stackiq',
			request: $request,
			config: $this->createMock(originalClassName: IAppConfig::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $groups,
			userSession: $session,
			settingsService: $settings,
			orgSyncSvc: $this->createMock(originalClassName: OrganizationSyncService::class),
			archiMateService: $this->createMock(originalClassName: ArchiMateService::class),
			progressTracker: $this->createMock(originalClassName: ProgressTracker::class),
			eolSyncService: $this->createMock(originalClassName: EolSyncService::class),
			logger: $log
		);
		return [$controller, $log, $calls];
	}

	/**
	 * Every outcome of every config action, keyed "<action> <caller> <answer>".
	 *
	 * @return array
	 */
	private function outcomes(): array {
		$callers = ['anonymous' => [null, false], 'non-admin' => ['bob', false], 'admin' => ['admin', true]];
		$out     = [];
		foreach (self::ACTIONS as $action => $serviceMethod) {
			foreach ($callers as $callerName => [$uid, $isAdmin]) {
				foreach (['ok' => ['answer' => 'a'], 'throws' => 'throw'] as $name => $answer) {
					$scenario = ['isAdmin' => $isAdmin, 'service' => [$serviceMethod => $answer]];
					[$controller, $log, $calls] = $this->makeController(uid: $uid, scenario: $scenario);
					$response = $controller->$action();
					$out[$action . ' ' . $callerName . ' ' . $name] = [
						$response->getStatus(),
						$response->getData(),
						$calls->getArrayCopy(),
						$log->records,
					];
				}
			}
		}

		return $out;
	}

	/**
	 * The config actions answer every caller as pinned.
	 *
	 * @return void
	 */
	public function testConfigActionsForEveryCaller(): void {
		$path = dirname(__DIR__, 2) . '/fixtures/settings/config-endpoints.json';
		$this->assertSame(
			expected: json_decode(json: (string) file_get_contents(filename: $path), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR),
			actual: $this->outcomes()
		);
	}
}
