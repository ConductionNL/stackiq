<?php

/**
 * StackIQ McpArgumentValidator
 *
 * Typed argument checks for the MCP tools. Each returns a structured error,
 * or null when the argument is valid, so a tool refuses before it authorises
 * or calls a service (mcp-full-action-surface task 2.3).
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
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Mcp;

/**
 * Argument checks returning structured MCP errors.
 *
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */
class McpArgumentValidator {

	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The structured error every tool answers a refusal with.
	 *
	 * @param string $code    validation_error, unauthenticated, forbidden, not_found, unavailable or unknown_tool.
	 * @param string $message A sentence for the agent.
	 *
	 * @return array{isError: true, error: string, message: string}
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public static function error(string $code, string $message): array {
		return ['isError' => true, 'error' => $code, 'message' => $message];
	}//end error()

	/**
	 * A required UUID argument.
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @param string               $name The argument name.
	 *
	 * @return array<string, mixed>|null The error, or null.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function uuid(array $args, string $name): ?array {
		$value = ($args[$name] ?? null);
		if (is_string($value) === false || preg_match(self::UUID_PATTERN, $value) !== 1) {
			return self::error(code: 'validation_error', message: "Argument {$name} must be a UUID.");
		}

		return null;
	}//end uuid()

	/**
	 * A string argument of a bounded length; absent is allowed when not required.
	 *
	 * @param array<string, mixed> $args     The arguments.
	 * @param string               $name     The argument name.
	 * @param int                  $max      The maximum length.
	 * @param bool                 $required Whether it must be present.
	 *
	 * @return array<string, mixed>|null The error, or null.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function string(array $args, string $name, int $max, bool $required=true): ?array {
		if (array_key_exists($name, $args) === false && $required === false) {
			return null;
		}

		$value = ($args[$name] ?? null);
		if (is_string($value) === false || $value === '' || mb_strlen($value) > $max) {
			return self::error(code: 'validation_error', message: "Argument {$name} must be a non-empty string of at most {$max} characters.");
		}

		return null;
	}//end string()
}//end class
