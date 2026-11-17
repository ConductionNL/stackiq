<?php

/**
 * Tests for the view image route, GET /api/views/{viewId}/image.svg.
 *
 * The view is read with OpenRegister's RBAC on through the view service; a
 * view the caller may not read answers 404 and nothing is drawn, and a view
 * the caller may read comes back as an SVG file named after the view.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ViewController;
use OCA\Stackiq\Kernel\Contracts\ViewServiceInterface;
use OCA\Stackiq\Service\ViewImageService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * GET /api/views/{viewId}/image.svg.
 */
#[CoversClass(ViewController::class)]
#[UsesClass(ViewImageService::class)]
class ViewControllerImageTest extends TestCase {

	/** @var ViewServiceInterface&MockObject */
	private ViewServiceInterface $viewService;

	/** @var ViewImageService&MockObject */
	private ViewImageService $imageService;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	private ViewController $controller;

	/**
	 * Wire the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->viewService  = $this->createMock(ViewServiceInterface::class);
		$this->imageService = $this->createMock(ViewImageService::class);
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->controller   = new ViewController(
			appName: 'stackiq',
			request: $this->createMock(IRequest::class),
			viewService: $this->viewService,
			logger: $this->createMock(LoggerInterface::class),
			userSession: $this->userSession,
			imageService: $this->imageService,
		);
	}//end setUp()

	/**
	 * Sign a user in.
	 *
	 * @return void
	 */
	private function signedIn(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end signedIn()

	/**
	 * The response's own headers (getHeaders() adds the server's CSP, which needs a running Nextcloud).
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function headersOf(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * A view the caller may read comes back as an SVG file named after the view.
	 *
	 * @return void
	 */
	public function testAReadableViewIsAnSvgFileNamedAfterTheView(): void {
		$this->signedIn();
		$view = ['name' => 'Applicatielandschap: zaken', 'nodes' => []];
		$this->viewService->expects($this->once())->method('findStoredView')->with('view-1')->willReturn($view);
		$this->imageService->expects($this->once())->method('renderSvg')->with($view)->willReturn('<svg/>');

		$response = $this->controller->getViewImage(viewId: 'view-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('<svg/>', $response->render());
		$this->assertSame('image/svg+xml', $this->headersOf(response: $response)['Content-Type']);
		$this->assertStringContainsString('filename="Applicatielandschap- zaken.svg"', $this->headersOf(response: $response)['Content-Disposition']);
	}//end testAReadableViewIsAnSvgFileNamedAfterTheView()

	/**
	 * A view OpenRegister does not return for the caller answers 404 and nothing is drawn.
	 *
	 * @return void
	 */
	public function testAViewTheCallerCannotReadIsNotFound(): void {
		$this->signedIn();
		$this->viewService->method('findStoredView')->willReturn(null);
		$this->imageService->expects($this->never())->method('renderSvg');

		$response = $this->controller->getViewImage(viewId: 'view-of-another-organisation');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAViewTheCallerCannotReadIsNotFound()

	/**
	 * Without a user: 401, and the view is not even read.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsRead(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->viewService->expects($this->never())->method('findStoredView');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->getViewImage(viewId: 'view-1')->getStatus());
	}//end testWithoutAUserNothingIsRead()

	/**
	 * The file name keeps letters, digits, spaces, dots, dashes and underscores.
	 *
	 * @return void
	 */
	public function testTheFileNameIsBuiltFromTheViewName(): void {
		$this->assertSame('Zaak- en documentstroom.svg', ViewController::imageFileName(view: ['name' => 'Zaak- en documentstroom']));
		$this->assertSame('a-b-c.svg', ViewController::imageFileName(view: ['name' => 'a/b\\c']));
		$this->assertSame('Één_view 2.svg', ViewController::imageFileName(view: ['name' => '  Één_view 2  ']));
		$this->assertSame('From xml.svg', ViewController::imageFileName(view: ['xml' => ['name' => 'From xml']]));
		$this->assertSame('view.svg', ViewController::imageFileName(view: ['name' => '<>']));
		$this->assertSame('view.svg', ViewController::imageFileName(view: []));
		$this->assertSame(124, mb_strlen(ViewController::imageFileName(view: ['name' => str_repeat('x', 300)])));
	}//end testTheFileNameIsBuiltFromTheViewName()

	/**
	 * Every signed-in user may call the route; it declares that itself.
	 *
	 * @return void
	 */
	public function testTheRouteDeclaresItsAuthPosture(): void {
		$method = new ReflectionMethod(ViewController::class, 'getViewImage');

		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
	}//end testTheRouteDeclaresItsAuthPosture()
}//end class
