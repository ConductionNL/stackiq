<?php

/**
 * An in-memory stand-in for OpenRegister's MigrationPackService.
 *
 * It has the four methods CmdbImportPackStore calls, with OpenRegister's
 * parameter names (the store calls them by name), and answers an unknown
 * slug or id with DoesNotExistException as OpenRegister's mapper does.
 * Definitions are stored as JSON, as OpenRegister stores them.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Support
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

namespace OCA\Stackiq\Tests\Unit\Support;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Migration packs in memory.
 */
final class FakeMigrationPackService {
	/**
	 * Stored rows by id: slug, definition JSON and builtin flag.
	 *
	 * @var array<int, array{slug: string, definition: string, builtin: bool}>
	 */
	public array $rows = [];

	/**
	 * Every write, as "create <slug>" or "update <id>".
	 *
	 * @var array<int, string>
	 */
	public array $writes = [];

	/**
	 * When set, every lookup throws it.
	 *
	 * @var Throwable|null
	 */
	public ?Throwable $failure = null;

	/**
	 * The next row id.
	 *
	 * @var int
	 */
	private int $nextId = 40;

	/**
	 * Store a row directly, as an administrator's edit or an older seed would have.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param int|null $id The row id; null takes the next one.
	 *
	 * @return int The row id.
	 */
	public function put(array $definition, ?int $id = null): int {
		$id = ($id ?? $this->nextId++);
		$this->rows[$id] = ['slug' => (string)$definition['id'], 'definition' => (string)json_encode($definition), 'builtin' => false];
		return $id;
	}//end put()

	/**
	 * The stored definition of a row.
	 *
	 * @param int $id The row id.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(int $id): array {
		return json_decode($this->rows[$id]['definition'], true);
	}//end definition()

	/**
	 * Find a pack by slug.
	 *
	 * @param string $packSlug The slug.
	 *
	 * @return object
	 *
	 * @throws DoesNotExistException When no row has the slug.
	 */
	public function findByPackSlug(string $packSlug): object {
		$this->failIfAsked();
		foreach ($this->rows as $id => $row) {
			if ($row['slug'] === $packSlug) {
				return $this->entity(id: $id);
			}
		}

		throw new DoesNotExistException('No migration pack ' . $packSlug);
	}//end findByPackSlug()

	/**
	 * Find a pack by id.
	 *
	 * @param int $id The row id.
	 *
	 * @return object
	 *
	 * @throws DoesNotExistException When no row has the id.
	 */
	public function find(int $id): object {
		$this->failIfAsked();
		if (isset($this->rows[$id]) === false) {
			throw new DoesNotExistException('No migration pack ' . $id);
		}

		return $this->entity(id: $id);
	}//end find()

	/**
	 * Create a pack.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param string|null $ownerUid The owner.
	 * @param bool $builtin Whether it is built in.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When the slug exists.
	 */
	public function create(array $definition, ?string $ownerUid = null, bool $builtin = false): object {
		foreach ($this->rows as $row) {
			if ($row['slug'] === $definition['id']) {
				throw new RuntimeException('A migration pack with id "' . $definition['id'] . '" already exists');
			}
		}

		$id = $this->put(definition: $definition);
		$this->rows[$id]['builtin'] = $builtin;
		$this->writes[] = 'create ' . $definition['id'];
		return $this->entity(id: $id);
	}//end create()

	/**
	 * Replace a pack's definition.
	 *
	 * @param int $id The row id.
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return object
	 */
	public function update(int $id, array $definition): object {
		$this->rows[$id]['slug'] = (string)$definition['id'];
		$this->rows[$id]['definition'] = (string)json_encode($definition);
		$this->writes[] = 'update ' . $id;
		return $this->entity(id: $id);
	}//end update()

	/**
	 * A container that hands out this service and, when given, an app config.
	 *
	 * @param TestCase $test The test, to build the mock with.
	 * @param IAppConfig|null $appConfig The app config, or null for none.
	 *
	 * @return ContainerInterface
	 */
	public function container(TestCase $test, ?IAppConfig $appConfig = null): ContainerInterface {
		$builder = new \ReflectionMethod(TestCase::class, 'createMock');
		$container = $builder->invoke($test, ContainerInterface::class);
		$known = ['OCA\OpenRegister\Service\MigrationPackService' => $this];
		if ($appConfig !== null) {
			$known[IAppConfig::class] = $appConfig;
		}

		$container->method('has')->willReturnCallback(fn (string $id): bool => isset($known[$id]));
		$container->method('get')->willReturnCallback(
			function (string $id) use ($known) {
				if (isset($known[$id]) === true) {
					return $known[$id];
				}

				throw new RuntimeException('not in this container: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * An app config backed by an array, for the store's row ids.
	 *
	 * @param TestCase $test The test, to build the mock with.
	 * @param array<string, string> $values Initial values by key.
	 *
	 * @return IAppConfig
	 */
	public static function appConfig(TestCase $test, array &$values): IAppConfig {
		$builder = new \ReflectionMethod(TestCase::class, 'createMock');
		$config = $builder->invoke($test, IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$values): string {
				return ($values[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$values): bool {
				$values[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end appConfig()

	/**
	 * Throw the configured failure.
	 *
	 * @return void
	 *
	 * @throws Throwable The configured failure.
	 */
	private function failIfAsked(): void {
		if ($this->failure !== null) {
			throw $this->failure;
		}
	}//end failIfAsked()

	/**
	 * The entity of a row, with the getters the store calls.
	 *
	 * @param int $id The row id.
	 *
	 * @return object
	 */
	private function entity(int $id): object {
		$row = $this->rows[$id];
		return new class($id, $row['slug'], $row['definition']) {
			/**
			 * Constructor.
			 *
			 * @param int $id The row id.
			 * @param string $slug The slug.
			 * @param string $definition The definition JSON.
			 */
			public function __construct(private int $id, private string $slug, private string $definition) {
			}

			/**
			 * The row id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * The slug.
			 *
			 * @return string
			 */
			public function getPackSlug(): string {
				return $this->slug;
			}

			/**
			 * The decoded definition.
			 *
			 * @return array<string, mixed>
			 */
			public function getDefinitionArray(): array {
				return json_decode($this->definition, true);
			}
		};
	}//end entity()
}//end class
