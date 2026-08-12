<?php

/**
 * Fail-mode tests for the AMEF configuration resolvers.
 *
 * The defect: the legacy fallback read every register/schema id with `''` as
 * its default and handed the result on. Consumers guard with `=== null`
 * (`ViewService::getViews()`/`getView()`), and `'' === null` is false — so an
 * empty id would have passed the guard and been pinned into an OpenRegister
 * query as the register/schema. An unpinned query returns rows, which reads
 * exactly like a correct result.
 *
 * Nothing reached a query today only because the fallback wrote PLURAL key
 * names (`views_schema`) while the consumers read SINGULAR ones
 * (`view_schema`), so the lookups missed and fell back to null. These tests
 * pin the real property — an unset id must not be present as an empty string —
 * so that accident can never be "cleaned up" into a live fail-open.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ArchiMateImportService;
use PHPUnit\Framework\TestCase;

/**
 * The AMEF config resolvers must omit unresolved ids, never emit ''.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 */
class ArchiMateConfigFailModeTest extends TestCase {

	/**
	 * Build the service with only the two collaborators the resolver touches.
	 *
	 * @param array<string,string> $values Config key => stored value.
	 *
	 * @return ArchiMateImportService
	 */
	private function serviceWithConfig(array $values): ArchiMateImportService {
		// The legacy fallback is only reached when `amef_config` does not
		// decode to an array. Its DEFAULT is '{}', which decodes to [] — so on
		// an instance that never wrote the key, getAmefConfig() returns [] and
		// the fallback never runs at all. Malformed JSON is the branch under
		// test here, and callers must not be given empty ids on that path.
		$values['amef_config'] = $values['amef_config'] ?? 'not-json';

		$config = $this->createMock(\OCP\IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($values) {
				return $values[$key] ?? $default;
			}
		);

		$service = (new \ReflectionClass(ArchiMateImportService::class))->newInstanceWithoutConstructor();

		foreach (['config' => $config, 'logger' => $this->createMock(\Psr\Log\LoggerInterface::class)] as $prop => $value) {
			$property = new \ReflectionProperty(ArchiMateImportService::class, $prop);
			$property->setValue($service, $value);
		}

		return $service;
	}//end serviceWithConfig()

	/**
	 * An unconfigured instance yields no id keys at all — not empty strings.
	 *
	 * @return void
	 */
	public function testUnconfiguredInstanceOmitsEveryIdRatherThanEmittingEmptyStrings(): void {
		$config = $this->serviceWithConfig([])->getAmefConfig();

		// Guard against a vacuous pass: if the fallback had not run at all,
		// the loop below would iterate nothing and prove nothing.
		$this->assertSame([], $config, 'every id was unset, so none should survive');

		foreach ($config as $key => $value) {
			$this->assertNotSame('', $value, sprintf('"%s" was handed on as an empty string', $key));
		}

		// The property the consumers rely on: `?? null` must yield null.
		$this->assertNull($config['register_id'] ?? null);
		$this->assertNull($config['views_schema'] ?? null);

	}//end testUnconfiguredInstanceOmitsEveryIdRatherThanEmittingEmptyStrings()

	/**
	 * A configured id still comes through untouched.
	 *
	 * @return void
	 */
	public function testConfiguredIdsAreReturnedUnchanged(): void {
		$config = $this->serviceWithConfig(
			[
				'amef_register' => '11',
				'amef_views_schema' => '42',
			]
		)->getAmefConfig();

		$this->assertSame('11', $config['register_id']);
		$this->assertSame('42', $config['views_schema']);

	}//end testConfiguredIdsAreReturnedUnchanged()

	/**
	 * A partially configured instance keeps what is set and drops what is not.
	 *
	 * This is the shape that makes the difference real: with `''` retained, a
	 * `=== null` guard on the missing half would pass.
	 *
	 * @return void
	 */
	public function testPartialConfigurationKeepsSetIdsAndDropsUnsetOnes(): void {
		$config = $this->serviceWithConfig(['amef_register' => '11'])->getAmefConfig();

		$this->assertSame('11', $config['register_id']);
		$this->assertArrayNotHasKey('views_schema', $config);
		$this->assertArrayNotHasKey('elements_schema', $config);

	}//end testPartialConfigurationKeepsSetIdsAndDropsUnsetOnes()

	/**
	 * A whitespace-only id counts as unset, not as a usable value.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyIdIsTreatedAsUnset(): void {
		$config = $this->serviceWithConfig(['amef_register' => '   '])->getAmefConfig();

		$this->assertArrayNotHasKey('register_id', $config);

	}//end testWhitespaceOnlyIdIsTreatedAsUnset()

}//end class
