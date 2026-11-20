<?php

/**
 * Test stub of filinq's published document contract.
 *
 * Mirrors OCA\Filinq\Event\DocumentGenerationRequestedEvent: an immutable
 * request and the requesting app, and result and error slots the handler
 * writes and the dispatcher reads after a synchronous dispatch. Loaded by the
 * gateway's tests only, and only when the real class is absent; it sits two
 * directories below tests/Stubs so the bootstrap glob does not load it.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Filinq\Event;

use OCP\EventDispatcher\Event;

/**
 * An app asks filinq to render a template over data and file the result.
 */
class DocumentGenerationRequestedEvent extends Event {

	/**
	 * The result filinq wrote, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $result = null;

	/**
	 * The error filinq wrote, or null.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $request       The request.
	 * @param string               $requestingApp The app asking.
	 */
	public function __construct(
		private readonly array $request,
		private readonly string $requestingApp,
	) {
		parent::__construct();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getRequest(): array {
		return $this->request;
	}

	/**
	 * @return string
	 */
	public function getRequestingApp(): string {
		return $this->requestingApp;
	}

	/**
	 * @param array<string, mixed> $result The result.
	 *
	 * @return void
	 */
	public function setResult(array $result): void {
		$this->result = $result;
		$this->error  = null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getResult(): ?array {
		return $this->result;
	}

	/**
	 * @return bool
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}

	/**
	 * @param string $error The error.
	 *
	 * @return void
	 */
	public function setError(string $error): void {
		$this->error  = $error;
		$this->result = null;
	}

	/**
	 * @return string|null
	 */
	public function getError(): ?string {
		return $this->error;
	}
}
