<?php

/**
 * Tests for CmdbRegisterSchemaFields.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbRegisterSchemaFields;
use PHPUnit\Framework\TestCase;

/**
 * The properties per schema of the register and its fragments, each with why no pack may write it.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
class CmdbRegisterSchemaFieldsTest extends TestCase {
	/**
	 * The settings directory a test wrote.
	 *
	 * @var string
	 */
	private string $directory = '';

	/**
	 * Write a register and its fragments into a fresh directory.
	 *
	 * @param array<string, mixed>       $register  The register document.
	 * @param array<string, array|string> $fragments Fragment file name => document, or raw content.
	 *
	 * @return string The directory.
	 */
	private function settings(array $register, array $fragments = []): string {
		$this->directory = sys_get_temp_dir() . '/stackiq-schema-fields-' . bin2hex(random_bytes(6));
		mkdir($this->directory . '/register.d', 0777, true);
		file_put_contents($this->directory . '/softwarecatalogus_register.json', (string)json_encode($register));
		foreach ($fragments as $name => $fragment) {
			$content = $fragment;
			if (is_array($fragment) === true) {
				$content = (string)json_encode($fragment);
			}

			file_put_contents($this->directory . '/register.d/' . $name, $content);
		}

		return $this->directory;
	}//end settings()

	/**
	 * Remove the directory a test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->directory === '') {
			return;
		}

		array_map('unlink', (array)glob($this->directory . '/register.d/*'));
		@rmdir($this->directory . '/register.d');
		@unlink($this->directory . '/softwarecatalogus_register.json');
		@rmdir($this->directory);
	}//end tearDown()

	/**
	 * Each property gets relation, system or ''; across documents a relation wins and a later reason replaces ''.
	 *
	 * @return void
	 */
	public function testReasonsAcrossTheRegisterAndItsFragments(): void {
		$register = [
			'components' => [
				'schemas' => [
					'module' => [
						'configuration' => ['x-openregister-calculations' => ['score' => []]],
						'properties' => [
							'name' => ['type' => 'string'],
							'supplier' => ['$ref' => '#/components/schemas/organization'],
							'internal' => ['type' => 'string', 'hideOnForm' => true],
							'score' => ['type' => 'number'],
							'later' => ['type' => 'string'],
							'7' => ['type' => 'string'],
						],
					],
					'empty' => [],
				],
			],
		];
		$fragment = [
			'components' => [
				'schemas' => [
					'module' => [
						'properties' => [
							'supplier' => ['type' => 'string', 'hideOnForm' => true],
							'later' => ['type' => 'string', 'hideOnForm' => true],
							'name' => ['type' => 'string'],
						],
					],
					'usage' => [
						'properties' => [
							'files' => ['type' => 'array', 'items' => ['type' => 'file']],
						],
					],
				],
			],
		];

		$fields = (new CmdbRegisterSchemaFields(settingsDirectory: $this->settings(register: $register, fragments: ['a.json' => $fragment])))->fields();

		$this->assertSame(
			[
				'module' => ['name' => '', 'supplier' => 'relation', 'internal' => 'system', 'score' => 'system', 'later' => 'system', '7' => ''],
				'usage' => ['files' => 'relation'],
			],
			$fields
		);
	}//end testReasonsAcrossTheRegisterAndItsFragments()

	/**
	 * The fields are read once.
	 *
	 * @return void
	 */
	public function testTheFieldsAreReadOnce(): void {
		$reader = new CmdbRegisterSchemaFields(
			settingsDirectory: $this->settings(register: ['components' => ['schemas' => ['module' => ['properties' => ['name' => []]]]]])
		);
		$first = $reader->fields();
		unlink($this->directory . '/softwarecatalogus_register.json');

		$this->assertSame($first, $reader->fields());
		$this->assertSame(['module' => ['name' => '']], $first);
	}//end testTheFieldsAreReadOnce()

	/**
	 * An unreadable fragment fails closed.
	 *
	 * @return void
	 */
	public function testAnUnreadableFragmentFailsClosed(): void {
		$reader = new CmdbRegisterSchemaFields(settingsDirectory: $this->settings(register: [], fragments: ['broken.json' => '{not json']));

		$this->expectException(CmdbImportException::class);
		$this->expectExceptionMessage('The stackiq register fragment broken.json cannot be read, so the CMDB import packs cannot be checked');
		$reader->fields();
	}//end testAnUnreadableFragmentFailsClosed()

	/**
	 * A missing register file fails closed before any fragment is read.
	 *
	 * @return void
	 */
	public function testAMissingRegisterFailsClosed(): void {
		$directory = $this->settings(register: [], fragments: ['a.json' => ['components' => []]]);
		unlink($directory . '/softwarecatalogus_register.json');
		$reader = new CmdbRegisterSchemaFields(settingsDirectory: $directory);

		try {
			$reader->fields();
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame(CmdbImportException::MAPPING_UNAVAILABLE, $e->getErrorCode());
			$this->assertSame('The stackiq register definition cannot be read, so the CMDB import packs cannot be checked', $e->getMessage());
		}
	}//end testAMissingRegisterFailsClosed()

	/**
	 * An object configuration that is not an object marks nothing; the other markers still count.
	 *
	 * @return void
	 */
	public function testANonObjectConfigurationIsIgnored(): void {
		$register = [
			'components' => [
				'schemas' => [
					'module' => [
						'properties' => [
							'plain' => ['type' => 'string', 'objectConfiguration' => 'related-object'],
							'linked' => ['type' => 'string', 'format' => 'uuid', 'objectConfiguration' => 'x'],
						],
					],
				],
			],
		];

		$fields = (new CmdbRegisterSchemaFields(settingsDirectory: $this->settings(register: $register)))->fields();

		$this->assertSame(['module' => ['plain' => '', 'linked' => 'relation']], $fields);
	}//end testANonObjectConfigurationIsIgnored()
}//end class
