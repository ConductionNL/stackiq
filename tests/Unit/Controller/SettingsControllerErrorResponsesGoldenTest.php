<?php

/**
 * Golden test for the failure answers of SettingsController's email,
 * email-template, group, ArchiMate-status, object-count and cronjob
 * endpoints.
 *
 * Pins, for an administrator, the outcome with the service answering and
 * with the service throwing: status, body, service calls and log lines.
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
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\EmailSettings;
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
class SettingsErrorResponsesLogSpy extends AbstractLogger {
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
 * Golden failure answers.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
class SettingsControllerErrorResponsesGoldenTest extends TestCase {
	/**
	 * Each action: its argument (null for none), the service method that
	 * answers or throws, and that method's answer.
	 */
	private const ACTIONS = [
		'getEmailSettings' => [null, 'getEmailSettings', ['host' => 'h']],
		'updateEmailSettings' => [null, 'updateEmailSettings', ['host' => 'h']],
		'getEmailTemplates' => [null, 'getAllEmailTemplates', ['welcome' => 'W']],
		'getEmailTemplate' => ['welcome', 'getEmailTemplate', 'W'],
		'updateEmailTemplate' => ['welcome', 'updateEmailTemplate', true],
		'getEmailTemplateDefault' => ['welcome', 'getDefaultEmailTemplate', 'D'],
		'getEmailTemplateVariables' => ['welcome', 'getEmailTemplateVariables', ['name']],
		'getAllGroups' => [null, 'getAllGroups', ['admin']],
		'clearArchiMateImportStatus' => [null, 'clearArchiMateImportStatus', ['cleared' => 1]],
		'killArchiMateImport' => [null, 'killArchiMateImport', ['killed' => 1]],
		'cancelArchiMateImport' => [null, 'cancelArchiMateImport', ['cancelled' => true]],
		'clearArchiMateExportStatus' => [null, 'clearArchiMateExportStatus', null],
		'getArchiMateSettings' => [null, 'getArchiMateStatus', null],
		'getObjectCounts' => [null, 'getObjectCountsStatistics', ['apps' => 2]],
		'getCronjobConfig' => [null, 'getCronjobConfig', ['enabled' => true]],
		'updateCronjobConfig' => [null, 'updateCronjobConfig', ['success' => true]],
	];

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null $uid      The caller, null for anonymous.
	 * @param array       $scenario isAdmin, service (method => result or 'throw').
	 *
	 * @return array{0: SettingsController, 1: SettingsErrorResponsesLogSpy, 2: \ArrayObject}
	 */
	private function makeController(?string $uid, array $scenario): array {
		$calls    = new \ArrayObject();
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$collaborators = [
			CronjobSettings::class => $this->createMock(originalClassName: CronjobSettings::class),
			EmailSettings::class => $this->createMock(originalClassName: EmailSettings::class),
		];
		foreach ($scenario['service'] as $method => $result) {
			$double = $settings;
			foreach ($collaborators as $class => $collaborator) {
				if (method_exists($class, $method) === true) {
					$double = $collaborator;
					break;
				}
			}

			$double->method($method)->willReturnCallback(
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
		$request->method('getParams')->willReturn(['template' => 'T', 'smtpPassword' => 'secret', 'note' => 'n']);
		$log = new SettingsErrorResponsesLogSpy();

		$controller = new SettingsController(
			appName: 'stackiq',
			request: $request,
			config: $this->createMock(originalClassName: IAppConfig::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $groups,
			userSession: $session,
			settingsService: $settings,
			cronjobSettings: $collaborators[CronjobSettings::class],
			emailSettings: $collaborators[EmailSettings::class],
			orgSyncSvc: $this->createMock(originalClassName: OrganizationSyncService::class),
			archiMateService: $this->createMock(originalClassName: ArchiMateService::class),
			progressTracker: $this->createMock(originalClassName: ProgressTracker::class),
			eolSyncService: $this->createMock(originalClassName: EolSyncService::class),
			logger: $log
		);
		return [$controller, $log, $calls];
	}

	/**
	 * Every outcome of every action for an administrator, keyed "<action> <answer>".
	 *
	 * The answered ArchiMate settings carry the current time, so only their failure is pinned.
	 *
	 * @return array
	 */
	private function outcomes(): array {
		$out = [];
		foreach (self::ACTIONS as $action => [$argument, $serviceMethod, $answer]) {
			foreach (['ok' => $answer, 'throws' => 'throw'] as $name => $result) {
				if ($action === 'getArchiMateSettings' && $name === 'ok') {
					continue;
				}

				$service = [$serviceMethod => $result, 'redactEmailSecrets' => ['host' => 'redacted']];
				[$controller, $log, $calls] = $this->makeController(uid: 'admin', scenario: ['isAdmin' => true, 'service' => $service]);
				$response = $controller->$action(...array_filter([$argument]));
				$out[$action . ' ' . $name] = [
					$response->getStatus(),
					$response->getData(),
					$calls->getArrayCopy(),
					$log->records,
				];
			}
		}

		return $out;
	}

	/**
	 * The actions answer and fail as pinned.
	 *
	 * @return void
	 */
	public function testAnswersAndFailuresForAnAdmin(): void {
		$path = dirname(__DIR__, 2) . '/fixtures/settings/error-responses.json';
		$this->assertSame(
			expected: json_decode(json: (string) file_get_contents(filename: $path), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR),
			actual: $this->outcomes()
		);
	}
}
