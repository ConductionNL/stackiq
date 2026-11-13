<?php

/**
 * Golden test of the view listing and view endpoints of ViewController.
 *
 * Pins, for no user, a successful, a failed and a throwing service and, for
 * one view, an empty id and a missing view, the status, the body, every log
 * line and the enrichment options passed to the service.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ViewController;
use OCA\Stackiq\Service\ViewService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Records log calls; stack traces are masked.
 */
class ViewEndpointLogRecorder extends AbstractLogger {
	/**
	 * The recorded calls.
	 *
	 * @var array<int, array>
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
		if (isset($context['trace']) === true) {
			$context['trace'] = '<trace>';
		}

		$this->records[] = [(string)$level, (string)$message, $context];
	}
}

/**
 * Golden test of the view endpoints.
 */
class ViewControllerGoldenTest extends TestCase {
	/**
	 * Call one endpoint and record what it did.
	 *
	 * @param array $call The endpoint, its argument, the params, the user and the service answer.
	 *
	 * @return array
	 */
	private function record(array $call): array {
		$params = $call['params'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($call['user'] === true ? $this->createMock(IUser::class) : null);
		$received = [];
		$service = $this->createMock(ViewService::class);
		$answer = static function (...$args) use ($call, &$received): array {
			$received[] = $args;
			if ($call['answer'] instanceof \Exception) {
				throw $call['answer'];
			}

			return $call['answer'];
		};
		$service->method('getAllViews')->willReturnCallback($answer);
		$service->method('getView')->willReturnCallback($answer);
		$logger = new ViewEndpointLogRecorder();

		$controller = new ViewController('stackiq', $request, $service, $logger, $userSession);
		$response = $call['endpoint'] === 'list' ? $controller->getAllViews() : $controller->getView($call['viewId']);

		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'service' => $received, 'log' => $logger->records];
	}//end record()

	/**
	 * Every case matches the golden.
	 *
	 * @return void
	 */
	public function testViewEndpointsMatchTheGolden(): void {
		$params = ['include_products' => 'yes', 'include_modules' => '0', 'include_gebruik' => true, 'other' => 'x'];
		$list = ['endpoint' => 'list', 'viewId' => '', 'params' => $params, 'user' => true];
		$one = ['endpoint' => 'one', 'viewId' => 'v1', 'params' => $params, 'user' => true];
		$cases = [
			'list, no user' => ['user' => false, 'answer' => []] + $list,
			'list, success' => ['answer' => ['success' => true, 'views' => [1], 'count' => 1, 'enrichments_applied' => ['p']]] + $list,
			'list, failed envelope' => ['answer' => ['success' => false]] + $list,
			'list, throws' => ['answer' => new \RuntimeException('db')] + $list,
			'list, no params' => ['params' => [], 'answer' => ['success' => true]] + $list,
			'list, deelnames on' => ['params' => ['include_deelnames_gebruik' => 'on', 'include_products' => 2], 'answer' => ['success' => true]] + $list,
			'view, no user' => ['user' => false, 'answer' => []] + $one,
			'view, empty id' => ['viewId' => '', 'answer' => []] + $one,
			'view, success' => ['answer' => ['success' => true, 'view' => ['id' => 'v1']]] + $one,
			'view, missing' => ['answer' => ['success' => false, 'view' => null, 'enrichments_applied' => ['m']]] + $one,
			'view, failed' => ['answer' => ['success' => false, 'view' => ['id' => 'v1']]] + $one,
			'view, throws' => ['answer' => new \RuntimeException('db')] + $one,
		];

		$out = [];
		foreach ($cases as $name => $call) {
			$out[$name] = $this->record(call: $call);
		}

		$path = dirname(__DIR__, 2) . '/fixtures/views/endpoints.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testViewEndpointsMatchTheGolden()
}//end class
