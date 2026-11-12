<?php

/**
 * The Server-Sent Events response of a long operation's progress.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCP\AppFramework\Http\Response;

/**
 * Sends the event-stream headers and, on render, streams the operation's
 * progress straight to the client.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
final class ProgressStreamResponse extends Response {
	/**
	 * Constructor for the SSE response.
	 *
	 * @param string $operationId The operation ID to stream.
	 * @param ProgressEventStream $stream Writes the progress events.
	 */
	public function __construct(
		private readonly string $operationId,
		private readonly ProgressEventStream $stream,
	) {
		parent::__construct();
		$this->addHeader(name: 'Content-Type', value: 'text/event-stream');
		$this->addHeader(name: 'Cache-Control', value: 'no-cache');
		$this->addHeader(name: 'Connection', value: 'keep-alive');
		$this->addHeader(name: 'Access-Control-Allow-Headers', value: 'Cache-Control');
	}//end __construct()

	/**
	 * Render the SSE stream.
	 *
	 * @return string Empty string (output is streamed directly).
	 *
	 * @spec openspec/specs/settings-admin-controller/spec.md
	 */
	public function render(): string {
		// Enable output buffering and turn off compression.
		if (ob_get_level() !== 0) {
			ob_end_clean();
		}

		ob_implicit_flush(true);

		$this->stream->stream(operationId: $this->operationId);

		return '';
	}//end render()
}//end class
