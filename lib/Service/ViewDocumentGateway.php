<?php

/**
 * Stackiq ViewDocumentGateway.
 *
 * The one place stackiq talks to filinq. It asks filinq, through filinq's
 * published document contract, to render a Word document or a PowerPoint
 * slide that holds an architecture view, and to file it in the caller's
 * Files. Stackiq never makes the office file itself.
 *
 * The contract is filinq's `DocumentGenerationRequestedEvent`, a typed
 * command event dispatched in-process. It is resolved by its class name
 * (hydra ADR-075 Decision 2): no container lookup of filinq internals, no
 * loopback HTTP call and no guessed endpoint. When the class does not
 * resolve, the gateway is unavailable and nothing is sent.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Asks filinq for a document of a view through its published command event.
 *
 * @spec openspec/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */
class ViewDocumentGateway {

	/**
	 * Filinq's published document contract.
	 */
	public const CONTRACT_EVENT = '\\OCA\\Filinq\\Event\\DocumentGenerationRequestedEvent';

	/**
	 * The formats a view can be asked for in.
	 */
	public const FORMATS = ['docx', 'pptx'];

	/**
	 * The app name filinq echoes on the document it files.
	 */
	private const REQUESTING_APP = 'stackiq';

	/**
	 * The template filinq renders the content into. Every value is escaped by
	 * the template engine; the drawing travels as a data URI, so the document
	 * holds no reference outside itself.
	 */
	private const TEMPLATE = '<h1>{{ name }}</h1>'
		. '{% if description %}<p>{{ description }}</p>{% endif %}'
		. '<p><img alt="{{ name }}" src="data:image/svg+xml;base64,{{ svgBase64 }}"></p>'
		. '{% if viewpoint %}<p>{{ labels.viewpoint }}: {{ viewpoint }}</p>{% endif %}'
		. '{% if legend %}<ul>{% for item in legend %}<li>{{ item.type }}: {{ item.count }}</li>{% endfor %}</ul>{% endif %}'
		. '<p>{{ generatedAt }}{% if link %} &middot; {{ link }}{% endif %}</p>';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Dispatches the contract event in-process.
	 * @param LoggerInterface  $logger          Where a refused request is reported.
	 * @param string           $contractEvent   The contract's class name; tests name a stub.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly string $contractEvent = self::CONTRACT_EVENT,
	) {
	}//end __construct()

	/**
	 * Whether filinq's document contract resolves, which is the only probe:
	 * "filinq is installed" is not.
	 *
	 * @return bool True when a request can be sent.
	 *
	 * @spec openspec/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
	 */
	public function isAvailable(): bool {
		return class_exists($this->contractEvent) === true && is_subclass_of($this->contractEvent, Event::class) === true;
	}//end isAvailable()

	/**
	 * Ask filinq for a document of the view and return where it was filed.
	 *
	 * @param string               $format  `docx` or `pptx`.
	 * @param array<string, mixed> $content The view's name, description, viewpoint, legend, date, link and SVG.
	 * @param string               $userId  The user whose Files receive the document.
	 *
	 * @return array{path: string, fileId: int|string, name: string} The Files path, file id and file name.
	 *
	 * @throws InvalidArgumentException When the format is neither docx nor pptx.
	 * @throws RuntimeException When the contract does not resolve, or filinq refuses or does not answer.
	 *
	 * @spec openspec/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
	 */
	public function requestDocument(string $format, array $content, string $userId): array {
		if (in_array($format, self::FORMATS, true) === false) {
			throw new InvalidArgumentException('A view can be made into a docx or a pptx document only.');
		}

		if ($this->isAvailable() === false) {
			throw new RuntimeException('The document contract of filinq is not available.');
		}

		$eventClass = $this->contractEvent;
		$request = $this->requestFor(format: $format, content: $content);
		$request['userId'] = $userId;
		$event = new $eventClass($request, self::REQUESTING_APP);
		$this->eventDispatcher->dispatchTyped($event);

		$error = $event->getError();
		if ($error !== null) {
			$this->logger->warning('Stackiq: filinq refused the view document', ['format' => $format, 'error' => $error]);
			throw new RuntimeException('filinq could not make the document: '.$error);
		}

		$result = $event->getResult();
		if (is_array($result) === false || (string)($result['path'] ?? '') === '') {
			$this->logger->warning('Stackiq: filinq did not answer the view document request', ['format' => $format]);
			throw new RuntimeException('filinq did not answer the document request.');
		}

		return [
			'path' => (string)$result['path'],
			'fileId' => ($result['fileId'] ?? ''),
			'name' => (string)($result['name'] ?? basename((string)$result['path'])),
		];
	}//end requestDocument()

	/**
	 * The contract request: an inline template over the content, filed as a file;
	 * the caller adds the user whose Files receive it.
	 *
	 * @param string               $format  The format.
	 * @param array<string, mixed> $content The content fields.
	 *
	 * @return array<string, mixed> The request, without its userId.
	 */
	private function requestFor(string $format, array $content): array {
		$data = $content;
		$data['svgBase64'] = base64_encode((string)($content['svg'] ?? ''));
		unset($data['svg']);

		return [
			'template' => self::TEMPLATE,
			'templateName' => 'Architecture view',
			'data' => $data,
			'format' => $format,
			'storeFile' => true,
			'filename' => $this->fileName(name: (string)($content['name'] ?? ''), format: $format),
			'metadata' => ['source' => 'stackiq', 'kind' => 'architecture-view'],
		];
	}//end requestFor()

	/**
	 * The document's file name: the view name with only safe characters, `view` when nothing is left.
	 *
	 * @param string $name   The view name.
	 * @param string $format The extension.
	 *
	 * @return string The file name.
	 */
	private function fileName(string $name, string $format): string {
		$name = trim((string)preg_replace('/[^\p{L}\p{N} ._-]+/u', '-', $name), " .-");
		if ($name === '') {
			$name = 'view';
		}

		return mb_substr($name, 0, 120).'.'.$format;
	}//end fileName()
}//end class
