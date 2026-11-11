<?php

/**
 * Golden test for the organisation report gate of the data quality,
 * reference component coverage and portfolio report controllers.
 *
 * Pins, for each controller, the answer to an anonymous caller (401), a
 * request without organisation (400), a caller who may not read the
 * organisation (403, before any report is built), a caller who may (200,
 * JSON and CSV) and a failing report (500), with every report call.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/portfolio-rationalization-time/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\DataQualityReportController;
use OCA\Stackiq\Controller\PortfolioReportController;
use OCA\Stackiq\Controller\ReferenceComponentCoverageController;
use OCA\Stackiq\Controller\OrganisationReportRequest;
use OCA\Stackiq\Service\DataQualityReportService;
use OCA\Stackiq\Service\OrganisationReportAccess;
use OCA\Stackiq\Service\PortfolioReportService;
use OCA\Stackiq\Service\ReferenceComponentCoverageService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden organisation report gate.
 *
 * @spec openspec/specs/portfolio-rationalization-time/spec.md
 */
class OrganisationReportGateGoldenTest extends TestCase {

	/**
	 * Each controller: its report service and the method a JSON report calls.
	 */
	private const CONTROLLERS = [
		DataQualityReportController::class => [DataQualityReportService::class, 'build'],
		ReferenceComponentCoverageController::class => [ReferenceComponentCoverageService::class, 'build'],
		PortfolioReportController::class => [PortfolioReportService::class, 'buildReport'],
	];

	/**
	 * The cases: caller, request params, the caller's own organisation and whether the report throws.
	 */
	private const CASES = [
		'anonymous'          => ['user' => null, 'params' => ['organisation' => 'zuiddrecht'], 'own' => 'zuiddrecht', 'throws' => false],
		'no organisation'    => ['user' => 'pieter', 'params' => [], 'own' => 'zuiddrecht', 'throws' => false],
		'other organisation' => ['user' => 'pieter', 'params' => ['organisation' => 'alblasserdam'], 'own' => 'zuiddrecht', 'throws' => false],
		'own organisation'   => ['user' => 'pieter', 'params' => ['organisation' => 'zuiddrecht'], 'own' => 'zuiddrecht', 'throws' => false],
		'own as csv'         => ['user' => 'pieter', 'params' => ['organisation' => 'zuiddrecht', 'format' => 'csv'], 'own' => 'zuiddrecht', 'throws' => false],
		'report fails'       => ['user' => 'pieter', 'params' => ['organisation' => 'zuiddrecht'], 'own' => 'zuiddrecht', 'throws' => true],
	];

	/**
	 * Every controller answers every case as pinned.
	 *
	 * @return void
	 */
	public function testEveryControllerAnswersEveryCaseAsPinned(): void {
		$out = [];
		foreach (self::CONTROLLERS as $controller => [$serviceClass, $jsonMethod]) {
			foreach (self::CASES as $name => $case) {
				$out[substr(string: $controller, offset: 23) . ' ' . $name] = $this->outcome(controller: $controller, case: $case);
			}
		}

		$path = dirname(__DIR__, 2) . '/fixtures/organisation-report-gate.json';
		if (getenv(name: 'RECORD_GOLDEN') !== false) {
			file_put_contents(filename: $path, data: json_encode(value: $out, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(
			expected: json_decode(json: (string) file_get_contents(filename: $path), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR),
			actual: $out
		);

	}//end testEveryControllerAnswersEveryCaseAsPinned()

	/**
	 * Run one case against one controller.
	 *
	 * @param class-string         $controller The controller.
	 * @param array<string, mixed> $case       The case.
	 *
	 * @return array{status: int, body: mixed, calls: array}
	 */
	private function outcome(string $controller, array $case): array {
		[$serviceClass, $jsonMethod] = self::CONTROLLERS[$controller];
		$calls   = new \ArrayObject();
		$service = $this->createMock(originalClassName: $serviceClass);
		foreach ([$jsonMethod => ['report' => 'json'], 'buildCsv' => "a;b\n"] as $method => $answer) {
			if (method_exists(object_or_class: $serviceClass, method: $method) === false) {
				continue;
			}

			$service->method($method)->willReturnCallback(
				static function (string $organisationUuid) use ($calls, $method, $answer, $case) {
					$calls[] = [$method, $organisationUuid];
					if ($case['throws'] === true) {
						throw new \RuntimeException('report down');
					}

					return $answer;
				}
			);
		}

		$response = $this->controller(controller: $controller, case: $case, service: $service)->index();
		if ($response instanceof DataDownloadResponse) {
			$headers = (new \ReflectionProperty(class: \OCP\AppFramework\Http\Response::class, property: 'headers'))->getValue($response);
			$body    = [$response->render(), $headers['Content-Disposition'] ?? null];
		} else {
			$body = $response->getData();
		}

		return ['status' => $response->getStatus(), 'body' => $body, 'calls' => $calls->getArrayCopy()];

	}//end outcome()

	/**
	 * Build one controller for a case.
	 *
	 * @param class-string         $controller The controller.
	 * @param array<string, mixed> $case       The case.
	 * @param object               $service    The report service double.
	 *
	 * @return object
	 */
	private function controller(string $controller, array $case, object $service): object {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($case['user'] !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturn($case['own']);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $case['params'][$key] ?? $default);

		return new $controller(
			appName: 'stackiq',
			request: $request,
			reportService: $service,
			reportRequest: new OrganisationReportRequest(userSession: $session, request: $request, access: new OrganisationReportAccess(groupManager: $groupManager, config: $config))
		);

	}//end controller()

}//end class
