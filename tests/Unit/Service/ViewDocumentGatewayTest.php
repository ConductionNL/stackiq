<?php

/**
 * Tests for ViewDocumentGateway: the one place stackiq asks filinq for a document of a view.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
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

namespace OCA\Stackiq\Tests\Unit\Service;

if (class_exists('\\OCA\\Filinq\\Event\\DocumentGenerationRequestedEvent') === false) {
	require_once __DIR__ . '/../../Stubs/Filinq/Event/DocumentGenerationRequestedEvent.php';
}

use InvalidArgumentException;
use OCA\Filinq\Event\DocumentGenerationRequestedEvent;
use OCA\Stackiq\Service\ViewDocumentGateway;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

#[CoversClass(ViewDocumentGateway::class)]
class ViewDocumentGatewayTest extends TestCase {

	private IEventDispatcher&MockObject $dispatcher;

	private LoggerInterface&MockObject $logger;

	/**
	 * What the dispatcher was handed.
	 *
	 * @var DocumentGenerationRequestedEvent|null
	 */
	private ?DocumentGenerationRequestedEvent $sent = null;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}

	/**
	 * A gateway over the contract stub whose handler answers as given.
	 *
	 * @param callable|null $handler What filinq does with the event, or null for nobody listening.
	 *
	 * @return ViewDocumentGateway
	 */
	private function gateway(?callable $handler = null): ViewDocumentGateway {
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($handler): void {
				$this->sent = $event;
				if ($handler !== null) {
					$handler($event);
				}
			}
		);

		return new ViewDocumentGateway(
			eventDispatcher: $this->dispatcher,
			logger: $this->logger,
			contractEvent: DocumentGenerationRequestedEvent::class,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return [
			'name' => 'Gemma <kaart>/1',
			'description' => 'De applicaties van het zaakgericht werken.',
			'viewpoint' => 'Application Cooperation',
			'legend' => [['type' => 'ApplicationComponent', 'count' => 2]],
			'generatedAt' => '2026-11-15',
			'link' => 'https://cloud.example/index.php/apps/stackiq/views/v-1',
			'svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
			'labels' => ['viewpoint' => 'Viewpoint'],
		];
	}

	public function testTheContractIsAvailableOnlyWhenItsClassResolves(): void {
		$this->assertTrue($this->gateway()->isAvailable());

		$missing = new ViewDocumentGateway($this->dispatcher, $this->logger, '\\OCA\\Filinq\\Event\\NoSuchEvent');
		$this->assertFalse($missing->isAvailable());

		$notAnEvent = new ViewDocumentGateway($this->dispatcher, $this->logger, \stdClass::class);
		$this->assertFalse($notAnEvent->isAvailable());
	}

	public function testAWordDocumentIsRequestedWithTheContentAndItsFilesPathReturned(): void {
		$gateway = $this->gateway(
			static fn (DocumentGenerationRequestedEvent $event) => $event->setResult(
				['fileId' => 812, 'path' => '/Documents/Gemma -kaart-1.docx', 'name' => 'Gemma -kaart-1.docx', 'format' => 'docx']
			)
		);

		$document = $gateway->requestDocument(format: 'docx', content: $this->content(), userId: 'eigenaar');

		$this->assertSame(['path' => '/Documents/Gemma -kaart-1.docx', 'fileId' => 812, 'name' => 'Gemma -kaart-1.docx'], $document);
		$this->assertSame('stackiq', $this->sent->getRequestingApp());

		$request = $this->sent->getRequest();
		$this->assertSame('docx', $request['format']);
		$this->assertTrue($request['storeFile']);
		$this->assertSame('eigenaar', $request['userId']);
		$this->assertSame('Gemma -kaart-1.docx', $request['filename']);
		$this->assertSame('Architecture view', $request['templateName']);
		$this->assertStringContainsString('{{ name }}', $request['template']);
		$this->assertStringContainsString('data:image/svg+xml;base64,{{ svgBase64 }}', $request['template']);
		$this->assertStringNotContainsString('|raw', $request['template']);

		$expected = $this->content();
		unset($expected['svg']);
		$expected['svgBase64'] = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>');
		$this->assertSame($expected, $request['data']);
	}

	public function testAPowerPointSlideIsRequestedAsPptxAndAMissingNameFallsBackToThePath(): void {
		$gateway = $this->gateway(
			static fn (DocumentGenerationRequestedEvent $event) => $event->setResult(['fileId' => '9', 'path' => '/Slides/view.pptx'])
		);

		$content         = $this->content();
		$content['name'] = '***';

		$document = $gateway->requestDocument(format: 'pptx', content: $content, userId: 'eigenaar');

		$this->assertSame('pptx', $this->sent->getRequest()['format']);
		$this->assertSame('view.pptx', $this->sent->getRequest()['filename']);
		$this->assertSame(['path' => '/Slides/view.pptx', 'fileId' => '9', 'name' => 'view.pptx'], $document);
	}

	public function testARefusalByFilinqIsRaisedAndLogged(): void {
		$gateway = $this->gateway(static fn (DocumentGenerationRequestedEvent $event) => $event->setError('template engine down'));
		$this->logger->expects($this->once())->method('warning')
			->with('Stackiq: filinq refused the view document', ['format' => 'docx', 'error' => 'template engine down']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('filinq could not make the document: template engine down');

		$gateway->requestDocument(format: 'docx', content: $this->content(), userId: 'eigenaar');
	}

	public function testAnUnansweredRequestIsRaised(): void {
		$gateway = $this->gateway();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('filinq did not answer the document request.');

		$gateway->requestDocument(format: 'docx', content: $this->content(), userId: 'eigenaar');
	}

	public function testAnotherFormatIsRefusedBeforeAnythingIsSent(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$gateway = new ViewDocumentGateway($this->dispatcher, $this->logger, DocumentGenerationRequestedEvent::class);

		$this->expectException(InvalidArgumentException::class);

		$gateway->requestDocument(format: 'pdf', content: $this->content(), userId: 'eigenaar');
	}

	public function testNothingIsSentWithoutTheContract(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$gateway = new ViewDocumentGateway($this->dispatcher, $this->logger, '\\OCA\\Filinq\\Event\\NoSuchEvent');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The document contract of filinq is not available.');

		$gateway->requestDocument(format: 'docx', content: $this->content(), userId: 'eigenaar');
	}

	public function testOnlyTheGatewayReferencesFilinqAndNothingCallsItsRoutes(): void {
		$lib       = dirname(__DIR__, 3) . '/lib';
		$offenders = [];
		$files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (preg_match('#apps/filinq|/filinq/api#i', $source) === 1) {
				$offenders[] = 'route call: ' . substr($file->getPathname(), strlen($lib));
			}

			if ($file->getPathname() === $lib . '/Service/ViewDocumentGateway.php') {
				continue;
			}

			if (preg_match('#OCA\\\\{1,2}Filinq#', $source) === 1) {
				$offenders[] = substr($file->getPathname(), strlen($lib));
			}
		}

		$this->assertSame([], $offenders);
	}
}
