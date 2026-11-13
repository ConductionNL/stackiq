<?php

/**
 * Minimal stand-ins for the server classes OCP\Util reaches when a widget
 * registers its scripts and styles. They load only when the real server
 * classes are absent (the plain unit bootstrap), and keep the same public
 * storage shape as the server so assertions read the same in both contexts.
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

namespace OC {
	if (class_exists(AppScriptDependency::class) === false) {
		/**
		 * Dependency record OCP\Util keeps per app.
		 */
		class AppScriptDependency {
			/**
			 * @param string        $id   The app id
			 * @param array<string> $deps The apps it loads after
			 */
			public function __construct(
				private string $id,
				private array $deps = [],
			) {
			}

			/**
			 * Records one more app to load after.
			 *
			 * @param string $dep The app id
			 *
			 * @return void
			 */
			public function addDep(string $dep): void {
				if (in_array($dep, $this->deps, true) === false) {
					$this->deps[] = $dep;
				}
			}

			/**
			 * @return string The app id
			 */
			public function getId(): string {
				return $this->id;
			}

			/**
			 * @return array<string> The apps it loads after
			 */
			public function getDeps(): array {
				return $this->deps;
			}
		}
	}
}

namespace {
	if (class_exists('OC_Util') === false) {
		/**
		 * Style registry with the server's public `$styles` list.
		 */
		class OC_Util {
			/**
			 * Registered style paths, in order.
			 *
			 * @var array<string>
			 */
			public static $styles = [];

			/**
			 * Registers "<app>/css/<file>" once, like the server does.
			 *
			 * @param string      $application The app id
			 * @param string|null $file        The stylesheet name
			 * @param bool        $prepend     Whether to put it first
			 *
			 * @return void
			 */
			public static function addStyle($application, $file = null, $prepend = false): void {
				$path = $file === null ? $application : $application . '/css/' . $file;
				if (in_array($path, self::$styles, true) === true) {
					return;
				}

				if ($prepend === true) {
					array_unshift(self::$styles, $path);
					return;
				}

				self::$styles[] = $path;
			}
		}
	}

	if (class_exists('OC') === false) {
		/**
		 * Holder for the service container OCP\Server reads.
		 */
		class OC {
			/**
			 * The container tests install.
			 *
			 * @var \Psr\Container\ContainerInterface|null
			 */
			public static $server = null;
		}
	}
}
