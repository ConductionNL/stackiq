<?php

/**
 * Composition-root interface alias tests.
 *
 * Nextcloud autowires concrete classes but not interfaces. A service whose
 * constructor asks for one of this app's own interfaces only resolves when the
 * composition root binds that interface to a class, so a missing binding shows
 * up as a request that fails to build the service.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\AppInfo;

use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every app interface that lib/ imports is bound in the composition root.
 *
 * @coversNothing
 */
class CompositionRootInterfaceAliasTest extends TestCase {

	/**
	 * The aliases registered in Application.php, as interface short name => target short name.
	 *
	 * @return array<string, string>
	 */
	private function registeredAliases(): array {
		$source = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
		preg_match_all(
			'/registerServiceAlias\(\s*(\w+)::class,\s*(\w+)::class\s*\)/',
			$source,
			$matches,
			PREG_SET_ORDER
		);
		$aliases = [];
		foreach ($matches as $match) {
			$aliases[$match[1]] = $match[2];
		}

		return $aliases;
	}//end registeredAliases()

	/**
	 * The short names of the app interfaces that files under lib/ import.
	 *
	 * @return array<string, true>
	 */
	private function importedAppInterfaces(): array {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/lib'));
		$names = [];
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			preg_match_all('/^use OCA\\\\Stackiq\\\\[\w\\\\]*\\\\(\w+Interface);/m', (string) file_get_contents($file->getPathname()), $matches);
			foreach ($matches[1] as $name) {
				$names[$name] = true;
			}
		}

		return $names;
	}//end importedAppInterfaces()

	/**
	 * The object type locator resolves to the catalogue locator.
	 *
	 * @return void
	 */
	public function testObjectTypeLocatorIsBoundToTheCatalogueLocator(): void {
		self::assertSame('CatalogueLocator', $this->registeredAliases()['ObjectTypeLocatorInterface'] ?? null);
		self::assertContains(ObjectTypeLocatorInterface::class, class_implements(CatalogueLocator::class));
	}//end testObjectTypeLocatorIsBoundToTheCatalogueLocator()

	/**
	 * Every imported app interface has a binding.
	 *
	 * @return void
	 */
	public function testEveryImportedAppInterfaceIsBound(): void {
		$aliases = $this->registeredAliases();
		$imported = $this->importedAppInterfaces();
		self::assertNotEmpty($imported);
		foreach (array_keys($imported) as $name) {
			self::assertArrayHasKey($name, $aliases, "$name is imported in lib/ but not bound in Application::register()");
		}
	}//end testEveryImportedAppInterfaceIsBound()
}//end class
