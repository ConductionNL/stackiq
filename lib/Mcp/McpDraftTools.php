<?php

/**
 * StackIQ McpDraftTools
 *
 * The two tools an assistant drafts an architecture view with
 * (architecture-assistant-drafted-views D1): one read that finds the
 * elements a draft can reuse, one create that writes the draft. Each
 * checks its arguments, requires a signed-in user and hands the arguments
 * unchanged to ArchitectureViewDraftService, which runs with the caller's
 * rights.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mcp
 * @package  OCA\Stackiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/architecture-assistant-views/spec.md#requirement-req-aav-001-stackiq-shall-offer-a-read-tool-that-finds-architecture-elements-for-a-draft
 */

declare(strict_types=1);

namespace OCA\Stackiq\Mcp;

use InvalidArgumentException;
use OCA\Stackiq\Exception\DraftRefusedException;
use OCA\Stackiq\Service\ArchitectureViewDraftService;
use Throwable;

/**
 * Finds elements for a draft and writes a drafted view.
 *
 * @spec openspec/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
 *
 * @SuppressWarnings(PHPMD.StaticAccess) McpArgumentValidator is the pure argument reader every MCP tool class shares.
 */
class McpDraftTools {

	/**
	 * Constructor.
	 *
	 * @param McpAdminGate                 $session   Tells whether a user is signed in.
	 * @param McpArgumentValidator         $validator Checks the arguments.
	 * @param ArchitectureViewDraftService $drafts    Finds elements and writes drafts.
	 */
	public function __construct(
		private readonly McpAdminGate $session,
		private readonly McpArgumentValidator $validator,
		private readonly ArchitectureViewDraftService $drafts,
	) {
	}//end __construct()

	/**
	 * Elements a draft can reuse.
	 *
	 * @param array<string, mixed> $args `query`, optional `types` and `limit`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/architecture-assistant-views/spec.md#requirement-req-aav-001-stackiq-shall-offer-a-read-tool-that-finds-architecture-elements-for-a-draft
	 */
	public function searchArchitectureElements(array $args): array {
		$refusal = ($this->validator->string(args: $args, name: 'query', max: 200)
			?? $this->validator->optionalInt(args: $args, name: 'limit', min: 1, max: ArchitectureViewDraftService::MAX_SEARCH)
			?? $this->stringList(args: $args, name: 'types')
			?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->run(
			step: fn (): array => [
				'elements' => $this->drafts->searchElements(
					query: (string)$args['query'],
					types: array_values((array)($args['types'] ?? [])),
					limit: (int)($args['limit'] ?? 20)
				),
			]
		);
	}//end searchArchitectureElements()

	/**
	 * Write a drafted view.
	 *
	 * @param array<string, mixed> $args `name`, optional `description`, `elements`, optional `relations`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
	 */
	public function draftView(array $args): array {
		$refusal = ($this->validator->string(args: $args, name: 'name', max: 255)
			?? $this->validator->string(args: $args, name: 'description', max: 2000, required: false)
			?? $this->list(args: $args, name: 'elements', required: true)
			?? $this->list(args: $args, name: 'relations', required: false)
			?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->run(
			step: fn (): array => $this->drafts->draftView(
				name: (string)$args['name'],
				description: (string)($args['description'] ?? ''),
				elements: array_values((array)$args['elements']),
				relations: array_values((array)($args['relations'] ?? []))
			)
		);
	}//end draftView()

	/**
	 * An optional list of strings.
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @param string               $name The argument name.
	 *
	 * @return array<string, mixed>|null The error, or null.
	 */
	private function stringList(array $args, string $name): ?array {
		if (array_key_exists($name, $args) === false) {
			return null;
		}

		$value = $args[$name];
		if (is_array($value) === false || array_is_list($value) === false || count(array_filter($value, 'is_string')) !== count($value)) {
			return McpArgumentValidator::error(code: 'validation_error', message: "Argument {$name} must be a list of strings.");
		}

		return null;
	}//end stringList()

	/**
	 * A list of objects.
	 *
	 * @param array<string, mixed> $args     The arguments.
	 * @param string               $name     The argument name.
	 * @param bool                 $required Whether it must be present and non-empty.
	 *
	 * @return array<string, mixed>|null The error, or null.
	 */
	private function list(array $args, string $name, bool $required): ?array {
		if (array_key_exists($name, $args) === false && $required === false) {
			return null;
		}

		$value = ($args[$name] ?? null);
		if (is_array($value) === false || array_is_list($value) === false || ($required === true && $value === [])) {
			return McpArgumentValidator::error(code: 'validation_error', message: "Argument {$name} must be a list of objects.");
		}

		return null;
	}//end list()

	/**
	 * Run a step and answer a refusal the way every stackiq tool does.
	 *
	 * @param callable $step The step.
	 *
	 * @return array<string, mixed>
	 */
	private function run(callable $step): array {
		try {
			return $step();
		} catch (InvalidArgumentException $e) {
			return McpArgumentValidator::error(code: 'validation_error', message: $e->getMessage());
		} catch (DraftRefusedException $e) {
			return McpArgumentValidator::error(code: 'forbidden', message: $e->getMessage());
		} catch (Throwable $e) {
			return McpArgumentValidator::error(code: 'unavailable', message: $e->getMessage());
		}
	}//end run()
}//end class
