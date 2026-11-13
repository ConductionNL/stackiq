<?php

/**
 * Unit tests for how the API contract middleware builds the successor link on
 * an unversioned path: the front controller in the request URI is kept, a URI
 * without one gives a bare path, and a URI that does not carry the path info
 * verbatim falls back to the bare successor.
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
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Successor `Link` construction of ApiContractMiddleware.
 *
 * @covers \OCA\Stackiq\Middleware\ApiContractMiddleware
 * @uses   \OCA\Stackiq\Middleware\Support\ApiPathAliases
 */
final class ApiContractMiddlewareSuccessorLinkTest extends TestCase {

	/**
	 * With pretty URLs the URI starts at the path info, so the link has no prefix.
	 *
	 * @return void
	 */
	public function testPrettyUrlGivesABareSuccessor(): void {
		$headers = $this->headersFor(
			path: '/apps/stackiq/api/things/7',
			uri: '/apps/stackiq/api/things/7',
			response: new JSONResponse(data: ['id' => 7])
		);

		$this->assertSame(expected: '</apps/stackiq/api/v1/things/7>; rel="successor-version"', actual: $headers['Link']);
	}//end testPrettyUrlGivesABareSuccessor()

	/**
	 * A sub-directory install keeps everything in front of the path info.
	 *
	 * @return void
	 */
	public function testSubdirectoryFrontControllerIsKept(): void {
		$headers = $this->headersFor(
			path: '/apps/stackiq/api/things/7',
			uri: '/cloud/index.php/apps/stackiq/api/things/7?page=2',
			response: new JSONResponse(data: [])
		);

		$this->assertSame(expected: '</cloud/index.php/apps/stackiq/api/v1/things/7>; rel="successor-version"', actual: $headers['Link']);
	}//end testSubdirectoryFrontControllerIsKept()

	/**
	 * A URI that does not contain the path info verbatim (here percent-encoded)
	 * falls back to the bare successor instead of guessing a prefix.
	 *
	 * @return void
	 */
	public function testUriWithoutThePathFallsBackToABareSuccessor(): void {
		$headers = $this->headersFor(
			path: '/apps/stackiq/api/things/a b',
			uri: '/index.php/apps/stackiq/api/things/a%20b',
			response: new JSONResponse(data: [])
		);

		$this->assertSame(expected: '</apps/stackiq/api/v1/things/a b>; rel="successor-version"', actual: $headers['Link']);
	}//end testUriWithoutThePathFallsBackToABareSuccessor()

	/**
	 * A non-JSON response on an unversioned path is announced as deprecated too, body untouched.
	 *
	 * @return void
	 */
	public function testNonJsonResponseIsAnnouncedToo(): void {
		$response = new DataDisplayResponse(data: 'raw');
		$headers  = $this->headersFor(path: '/apps/stackiq/api/files/3', uri: '/index.php/apps/stackiq/api/files/3', response: $response);

		$this->assertSame(expected: '@1794441600', actual: $headers['Deprecation']);
		$this->assertSame(expected: '</index.php/apps/stackiq/api/v1/files/3>; rel="successor-version"', actual: $headers['Link']);
		$this->assertSame(expected: 'raw', actual: $response->getData());
	}//end testNonJsonResponseIsAnnouncedToo()

	/**
	 * Run the middleware and return the decorated response's headers.
	 *
	 * @param string   $path     The request path info.
	 * @param string   $uri      The full request URI.
	 * @param Response $response The controller response.
	 *
	 * @return array<string,string> The headers by name.
	 */
	private function headersFor(string $path, string $uri, Response $response): array {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn($uri);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->expects($this->once())->method('getAppVersion')->with('stackiq')->willReturn('1.2.3');

		$decorated = (new ApiContractMiddleware(request: $request, appManager: $appManager))
			->afterController(controller: null, methodName: 'any', response: $response);

		$this->assertSame(expected: $response, actual: $decorated);

		return (new ReflectionProperty(Response::class, 'headers'))->getValue($decorated);
	}//end headersFor()
}//end class
