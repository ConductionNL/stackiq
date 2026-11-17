<?php

/**
 * Unit tests for the MCP argument validator's string and UUID checks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Mcp;

use OCA\Stackiq\Mcp\McpArgumentValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tool refuses malformed arguments with a structured validation error.
 *
 * @covers \OCA\Stackiq\Mcp\McpArgumentValidator
 */
class McpArgumentValidatorTest extends TestCase {

	/**
	 * String arguments and whether they are accepted.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int, 2: bool, 3: bool}>
	 */
	public static function stringCases(): array {
		return [
			'present and within length' => [['note' => 'abc'], 3, true, true],
			'multibyte counted as characters' => [['note' => 'ééé'], 3, true, true],
			'one character too long' => [['note' => 'abcd'], 3, true, false],
			'empty string' => [['note' => ''], 3, true, false],
			'not a string' => [['note' => 12], 3, true, false],
			'null value' => [['note' => null], 3, false, false],
			'absent but required' => [[], 3, true, false],
			'absent and optional' => [[], 3, false, true],
			'present and optional is still checked' => [['note' => 'abcd'], 3, false, false],
		];

	}//end stringCases()

	/**
	 * The string check accepts or refuses with the bounded-length message.
	 *
	 * @param array<string, mixed> $args     The arguments.
	 * @param int                  $max      The maximum length.
	 * @param bool                 $required Whether the argument is required.
	 * @param bool                 $valid    Whether it should be accepted.
	 *
	 * @return void
	 */
	#[DataProvider('stringCases')]
	public function testString(array $args, int $max, bool $required, bool $valid): void {
		$result = (new McpArgumentValidator())->string(args: $args, name: 'note', max: $max, required: $required);

		if ($valid === true) {
			$this->assertNull($result);
			return;
		}

		$this->assertSame(
			['isError' => true, 'error' => 'validation_error', 'message' => 'Argument note must be a non-empty string of at most 3 characters.'],
			$result
		);

	}//end testString()

	/**
	 * The UUID check accepts either case and refuses anything else.
	 *
	 * @return void
	 */
	public function testUuid(): void {
		$validator = new McpArgumentValidator();
		$refusal = ['isError' => true, 'error' => 'validation_error', 'message' => 'Argument id must be a UUID.'];

		$this->assertNull($validator->uuid(args: ['id' => '0a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d'], name: 'id'));
		$this->assertNull($validator->uuid(args: ['id' => '0A1B2C3D-4E5F-6A7B-8C9D-0E1F2A3B4C5D'], name: 'id'));
		$this->assertSame($refusal, $validator->uuid(args: ['id' => '0a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5'], name: 'id'));
		$this->assertSame($refusal, $validator->uuid(args: ['id' => ' 0a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d'], name: 'id'));
		$this->assertSame($refusal, $validator->uuid(args: ['id' => 42], name: 'id'));
		$this->assertSame($refusal, $validator->uuid(args: [], name: 'id'));

	}//end testUuid()
}//end class
