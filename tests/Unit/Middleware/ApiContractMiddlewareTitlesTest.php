<?php

/**
 * Unit tests for the problem titles and request detection of the API contract middleware.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Middleware
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Middleware;

use OCA\Stackiq\Middleware\ApiContractMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins the title of every known error status, and that a request without a
 * path, or outside the API, is never decorated.
 *
 * @covers \OCA\Stackiq\Middleware\ApiContractMiddleware
 * @uses   \OCA\Stackiq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Stackiq\Middleware\Support\StandardProtocolPaths
 */
final class ApiContractMiddlewareTitlesTest extends TestCase {

	/**
	 * Every status the middleware names, with its title.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function statusTitles(): array {
		return [
			'400' => [400, 'Bad Request'],
			'401' => [401, 'Unauthorized'],
			'402' => [402, 'Payment Required'],
			'403' => [403, 'Forbidden'],
			'404' => [404, 'Not Found'],
			'405' => [405, 'Method Not Allowed'],
			'406' => [406, 'Not Acceptable'],
			'409' => [409, 'Conflict'],
			'410' => [410, 'Gone'],
			'412' => [412, 'Precondition Failed'],
			'413' => [413, 'Content Too Large'],
			'415' => [415, 'Unsupported Media Type'],
			'422' => [422, 'Unprocessable Content'],
			'423' => [423, 'Locked'],
			'424' => [424, 'Failed Dependency'],
			'428' => [428, 'Precondition Required'],
			'429' => [429, 'Too Many Requests'],
			'500' => [500, 'Internal Server Error'],
			'501' => [501, 'Not Implemented'],
			'502' => [502, 'Bad Gateway'],
			'503' => [503, 'Service Unavailable'],
			'504' => [504, 'Gateway Timeout'],
			'407 has no own title' => [407, 'Error'],
			'599 has no own title' => [599, 'Error'],
		];
	}//end statusTitles()

	/**
	 * Each error status gets its own title and the exact problem shape.
	 *
	 * @param int    $status The response status.
	 * @param string $title  The expected title.
	 *
	 * @return void
	 *
	 * @dataProvider statusTitles
	 */
	public function testEachStatusGetsItsTitle(int $status, string $title): void {
		$response = $this->decorate(
			path: '/apps/stackiq/api/v1/things',
			response: new JSONResponse(data: ['error' => 'Went wrong'], statusCode: $status)
		);

		$this->assertSame(
			expected: ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => 'Went wrong', 'error' => 'Went wrong'],
			actual: $response->getData()
		);
		$this->assertSame(expected: ApiContractMiddleware::PROBLEM_CONTENT_TYPE, actual: $this->headersOf(response: $response)['Content-Type']);
	}//end testEachStatusGetsItsTitle()

	/**
	 * A request without a path info is not an API request: no header, no rewrite,
	 * and the app version is never read.
	 *
	 * @return void
	 */
	public function testARequestWithoutAPathIsLeftAlone(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn(false);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->expects($this->never())->method('getAppVersion');

		$original = new JSONResponse(data: ['error' => 'x'], statusCode: 500);
		$response = (new ApiContractMiddleware(request: $request, appManager: $appManager))
			->afterController(controller: null, methodName: 'any', response: $original);

		$this->assertSame(expected: $original, actual: $response);
		$this->assertSame(expected: ['error' => 'x'], actual: $response->getData());
		$this->assertArrayNotHasKey(key: 'API-Version', array: $this->headersOf(response: $response));
	}//end testARequestWithoutAPathIsLeftAlone()

	/**
	 * The marker is `/api/` with both slashes: a path that merely contains "api" is no API request.
	 *
	 * @return void
	 */
	public function testThePathMarkerNeedsBothSlashes(): void {
		$response = $this->decorate(path: '/apps/stackiq/apikeys', response: new JSONResponse(data: ['error' => 'x'], statusCode: 404));

		$this->assertArrayNotHasKey(key: 'API-Version', array: $this->headersOf(response: $response));
		$this->assertSame(expected: ['error' => 'x'], actual: $response->getData());
	}//end testThePathMarkerNeedsBothSlashes()

	/**
	 * The middleware decorates the controller's own response object and the app
	 * version is read for this app.
	 *
	 * @return void
	 */
	public function testTheSameResponseIsDecoratedWithThisAppsVersion(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/stackiq/api/v1/things');
		$request->method('getRequestUri')->willReturn('/index.php/apps/stackiq/api/v1/things');
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->expects($this->once())->method('getAppVersion')->with('stackiq')->willReturn('4.5.6');

		$original = new JSONResponse(data: ['id' => 1]);
		$response = (new ApiContractMiddleware(request: $request, appManager: $appManager))
			->afterController(controller: null, methodName: 'index', response: $original);

		$this->assertSame(expected: $original, actual: $response);
		$this->assertSame(expected: '4.5.6', actual: $this->headersOf(response: $response)['API-Version']);
	}//end testTheSameResponseIsDecoratedWithThisAppsVersion()

	/**
	 * Run the middleware for a request on the given path.
	 *
	 * @param string   $path     The request path info.
	 * @param Response $response The controller response.
	 *
	 * @return Response The decorated response.
	 */
	private function decorate(string $path, Response $response): Response {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn('/index.php'.$path);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.2.3');

		return (new ApiContractMiddleware(request: $request, appManager: $appManager))
			->afterController(controller: null, methodName: 'any', response: $response);
	}//end decorate()

	/**
	 * The headers a response set, read without the server container.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string,string> The headers by name.
	 */
	private function headersOf(Response $response): array {
		$property = new ReflectionProperty(Response::class, 'headers');

		return $property->getValue($response);
	}//end headersOf()
}//end class
