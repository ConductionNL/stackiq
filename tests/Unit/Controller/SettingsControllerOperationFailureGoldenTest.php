<?php

/**
 * Golden test for the failure answers of SettingsController's status,
 * version, manual import and consolidated auto-configure endpoints.
 *
 * Pins, for each, the status, body and log lines when the settings service
 * throws. The timestamp in the body is checked to be an int and masked.
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
 * Golden operation failures.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
class SettingsControllerOperationFailureGoldenTest extends TestCase {

	/**
	 * Each action and the settings service method that throws.
	 */
	private const ACTIONS = [
		'status' => 'getConfigurationStatus',
		'getVersionInfo' => 'getVersionInfo',
		'manualImport' => 'manualImport',
		'consolidatedAutoConfigure' => 'performConsolidatedAutoConfiguration',
	];

	/**
	 * Every action answers a throwing service as pinned.
	 *
	 * @return void
	 */
	public function testEveryActionAnswersAFailureAsPinned(): void {
		$out = [];
		foreach (self::ACTIONS as $action => $serviceMethod) {
			$out[$action] = $this->outcome(action: $action, serviceMethod: $serviceMethod);
		}

		$expected = [];
		foreach (['status' => 'Failed to get configuration status', 'getVersionInfo' => 'Failed to get version info'] as $action => $message) {
			$expected[$action] = [500, ['error' => 'service down', 'timestamp' => 'masked'], [['error', 'SettingsController: ' . $message, ['exception_message' => 'service down', 'exception' => 'RuntimeException']]]];
		}

		$runs = [
			'manualImport' => ['SettingsController: Manual import failed', 'Import failed: service down'],
			'consolidatedAutoConfigure' => ['SettingsController: Consolidated auto-configuration failed', 'Auto-configuration failed: service down'],
		];
		foreach ($runs as $action => [$log, $message]) {
			$expected[$action] = [
				500,
				['success' => false, 'message' => $message, 'error' => 'service down', 'timestamp' => 'masked'],
				[['error', $log, ['exception_message' => 'service down', 'exception' => 'RuntimeException']]],
			];
		}

		$this->assertSame(expected: $expected, actual: $out);

	}//end testEveryActionAnswersAFailureAsPinned()

	/**
	 * Call one action with a throwing settings service; keep only the error log lines.
	 *
	 * @param string $action        The action.
	 * @param string $serviceMethod The settings service method that throws.
	 *
	 * @return array{0: int, 1: array, 2: array}
	 */
	private function outcome(string $action, string $serviceMethod): array {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method($serviceMethod)->willThrowException(new \RuntimeException('service down'));
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $default);
		$request->method('getParams')->willReturn([]);
		$records = new \ArrayObject();
		$logger  = new class($records) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param \ArrayObject $records The records.
			 */
			public function __construct(private \ArrayObject $records) {
			}

			/**
			 * Record an error line, with the exception as its class.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				if ((string) $level !== 'error') {
					return;
				}

				if (isset($context['exception']) === true && $context['exception'] instanceof \Throwable) {
					$context['exception'] = get_class($context['exception']);
				}

				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};

		$controller = new SettingsController(
			appName: 'stackiq',
			request: $request,
			config: $this->createMock(originalClassName: IAppConfig::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			userSession: $session,
			settingsService: $settings,
			orgSyncSvc: $this->createMock(originalClassName: OrganizationSyncService::class),
			archiMateService: $this->createMock(originalClassName: ArchiMateService::class),
			progressTracker: $this->createMock(originalClassName: ProgressTracker::class),
			eolSyncService: $this->createMock(originalClassName: EolSyncService::class),
			logger: $logger
		);
		$response = $controller->$action();
		$data     = $response->getData();
		$this->assertIsInt(actual: $data['timestamp']);
		$data['timestamp'] = 'masked';
		return [$response->getStatus(), $data, $records->getArrayCopy()];

	}//end outcome()

}//end class
