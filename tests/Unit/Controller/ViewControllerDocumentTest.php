<?php

/**
 * Tests for the view document route, POST /api/views/{viewId}/document.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ViewController;
use OCA\Stackiq\Kernel\Contracts\ViewServiceInterface;
use OCA\Stackiq\Service\ViewDocumentContent;
use OCA\Stackiq\Service\ViewDocumentGateway;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ViewController::class)]
class ViewControllerDocumentTest extends TestCase {

	private ViewServiceInterface&MockObject $viewService;

	private IUserSession&MockObject $userSession;

	private ViewDocumentGateway&MockObject $documents;

	private ViewDocumentContent&MockObject $documentContent;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->viewService     = $this->createMock(ViewServiceInterface::class);
		$this->userSession     = $this->createMock(IUserSession::class);
		$this->documents       = $this->createMock(ViewDocumentGateway::class);
		$this->documentContent = $this->createMock(ViewDocumentContent::class);
		$this->logger          = $this->createMock(LoggerInterface::class);
	}

	private function controller(string $format, bool $withGateway = true): ViewController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('format', '')->willReturn($format);

		return new ViewController(
			appName: 'stackiq',
			request: $request,
			viewService: $this->viewService,
			logger: $this->logger,
			userSession: $this->userSession,
			documents: ($withGateway === true ? $this->documents : null),
			documentContent: $this->documentContent,
		);
	}

	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('eigenaar');
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testAWordDocumentIsMadeAndItsFilesPathAnswered(): void {
		$this->signedIn();
		$view = ['name' => 'Zaakgericht werken'];
		$this->documents->method('isAvailable')->willReturn(true);
		$this->viewService->expects($this->once())->method('findStoredView')->with('v-1')->willReturn($view);
		$this->documentContent->expects($this->once())->method('contentFor')
			->with($view, 'v-1', $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn(['name' => 'Zaakgericht werken']);
		$this->documents->expects($this->once())->method('requestDocument')
			->with('docx', ['name' => 'Zaakgericht werken'], 'eigenaar')
			->willReturn(['path' => '/Documents/Zaakgericht werken.docx', 'fileId' => 7, 'name' => 'Zaakgericht werken.docx']);

		$response = $this->controller('docx')->createViewDocument(viewId: 'v-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(
			['success' => true, 'path' => '/Documents/Zaakgericht werken.docx', 'fileId' => 7, 'name' => 'Zaakgericht werken.docx'],
			$response->getData()
		);
	}

	public function testWithoutTheContractTheAnswerIs503AndNothingIsReadOrSent(): void {
		$this->signedIn();
		$this->documents->method('isAvailable')->willReturn(false);
		$this->documents->expects($this->never())->method('requestDocument');
		$this->viewService->expects($this->never())->method('findStoredView');

		$response = $this->controller('pptx')->createViewDocument(viewId: 'v-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Word and PowerPoint need the document app filinq.'], $response->getData());

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller('pptx', false)->createViewDocument(viewId: 'v-1')->getStatus());
	}

	public function testAnotherFormatIs400(): void {
		$this->signedIn();
		$this->documents->expects($this->never())->method('requestDocument');

		$response = $this->controller('pdf')->createViewDocument(viewId: 'v-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testAViewTheCallerMayNotReadIs404(): void {
		$this->signedIn();
		$this->documents->method('isAvailable')->willReturn(true);
		$this->viewService->method('findStoredView')->willReturn(null);
		$this->documents->expects($this->never())->method('requestDocument');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('docx')->createViewDocument(viewId: 'v-9')->getStatus());
	}

	public function testARefusalByFilinqIs502AndLogged(): void {
		$this->signedIn();
		$this->documents->method('isAvailable')->willReturn(true);
		$this->viewService->method('findStoredView')->willReturn(['name' => 'X']);
		$this->documentContent->method('contentFor')->willReturn([]);
		$this->documents->method('requestDocument')->willThrowException(new RuntimeException('filinq could not make the document: down'));
		$this->logger->expects($this->once())->method('warning');

		$response = $this->controller('docx')->createViewDocument(viewId: 'v-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'filinq could not make the document.'], $response->getData());
	}

	public function testWithoutAUserTheAnswerIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->documents->expects($this->never())->method('requestDocument');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller('docx')->createViewDocument(viewId: 'v-1')->getStatus());
	}

	public function testTheRouteIsOpenToUsersAndKeepsCsrf(): void {
		$method = new ReflectionMethod(ViewController::class, 'createViewDocument');

		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		$this->assertSame([], $method->getAttributes(NoCSRFRequired::class));
	}
}
