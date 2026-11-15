<?php

/**
 * Unit tests for what the concept-organisations dashboard widget loads: the
 * webpack runtime, both shared chunks and its own entry, in that order, under
 * the app's id, plus the shared dashboard stylesheet.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Dashboard
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Dashboard;

require_once __DIR__ . '/Fixtures/ScriptRegistryStubs.php';

use OCA\Stackiq\Dashboard\ConceptOrganisatiesWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Util;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * Script and style registration of ConceptOrganisatiesWidget::load().
 *
 * @covers \OCA\Stackiq\Dashboard\ConceptOrganisatiesWidget
 */
final class ConceptOrganisatiesWidgetLoadTest extends TestCase {

	/**
	 * The server container in place before this test, restored afterwards.
	 *
	 * @var mixed
	 */
	private mixed $previousServer = null;

	/**
	 * Whether this test installed its own container.
	 *
	 * @var bool
	 */
	private bool $ownServer = false;

	/**
	 * Clears the static registries and, outside a booted server, installs a
	 * container whose language factory answers "nl".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetRegistries();

		$this->previousServer = \OC::$server;
		if ($this->previousServer === null) {
			$factory = $this->createMock(IFactory::class);
			$factory->method('findLanguage')->with('stackiq')->willReturn('nl');
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->with(IFactory::class)->willReturn($factory);
			\OC::$server = $container;
			$this->ownServer = true;
		}
	}//end setUp()

	/**
	 * Clears the registries again and puts the previous container back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->resetRegistries();
		if ($this->ownServer === true) {
			\OC::$server = $this->previousServer;
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The four scripts go in as runtime, shared vendor, shared nc-vue, then the widget entry.
	 *
	 * @return void
	 */
	public function testItRegistersTheChunksInLoadOrder(): void {
		$this->widget()->load();

		$scripts = $this->registry('scripts');
		self::assertArrayHasKey('stackiq', $scripts);
		$jsOnly = array_values(
			array_filter($scripts['stackiq'], static fn (string $path): bool => str_contains($path, '/js/'))
		);
		self::assertSame(
			[
				'stackiq/js/stackiq-runtime',
				'stackiq/js/stackiq-shared-vendor',
				'stackiq/js/stackiq-shared-nc-vue',
				'stackiq/js/stackiq-conceptOrganisatiesWidget',
			],
			$jsOnly
		);
	}//end testItRegistersTheChunksInLoadOrder()

	/**
	 * Every script is filed under the app id, loads after core, and pulls in the app's translations.
	 *
	 * @return void
	 */
	public function testTheScriptsBelongToTheAppAndBringTranslations(): void {
		$this->widget()->load();

		$scripts = $this->registry('scripts');
		self::assertSame(['stackiq'], array_keys($scripts));

		$translations = array_filter($scripts['stackiq'], static fn (string $path): bool => str_starts_with($path, 'stackiq/l10n/'));
		self::assertCount(4, $translations);
		self::assertCount(8, $scripts['stackiq']);

		$deps = $this->registry('scriptDeps');
		self::assertSame(['stackiq'], array_keys($deps));
		self::assertSame(['core'], $deps['stackiq']->getDeps());
	}//end testTheScriptsBelongToTheAppAndBringTranslations()

	/**
	 * The shared dashboard stylesheet is registered once, under the app id.
	 *
	 * @return void
	 */
	public function testItRegistersTheDashboardStylesheet(): void {
		$this->widget()->load();
		$this->widget()->load();

		$styles = array_values(
			array_filter(\OC_Util::$styles, static fn (string $path): bool => str_starts_with($path, 'stackiq/'))
		);
		self::assertSame(['stackiq/css/dashboardWidgets'], $styles);
	}//end testItRegistersTheDashboardStylesheet()

	/**
	 * Builds the widget with inert collaborators.
	 *
	 * @return ConceptOrganisatiesWidget
	 */
	private function widget(): ConceptOrganisatiesWidget {
		return new ConceptOrganisatiesWidget(
			$this->createMock(IL10N::class),
			$this->createMock(IURLGenerator::class),
		);
	}//end widget()

	/**
	 * Reads one of OCP\Util's private static registries.
	 *
	 * @param string $name The property name
	 *
	 * @return array<string, mixed>
	 */
	private function registry(string $name): array {
		return (new ReflectionProperty(Util::class, $name))->getValue();
	}//end registry()

	/**
	 * Empties the script and style registries this test writes to.
	 *
	 * @return void
	 */
	private function resetRegistries(): void {
		foreach (['scripts', 'scriptDeps'] as $name) {
			(new ReflectionProperty(Util::class, $name))->setValue(null, []);
		}

		\OC_Util::$styles = array_values(
			array_filter(\OC_Util::$styles, static fn (string $path): bool => str_starts_with($path, 'stackiq/') === false)
		);
	}//end resetRegistries()
}//end class
