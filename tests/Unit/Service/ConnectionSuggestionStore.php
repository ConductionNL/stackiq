<?php

/**
 * An in-memory OpenRegister object store for the connection suggestion tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Objects by schema (10 usage, 20 connection), searched by exact property match.
 */
final class ConnectionSuggestionStore {
	/**
	 * Objects by uuid, each with its schema id.
	 *
	 * @var array<string, array{schema: int, data: array<string, mixed>}>
	 */
	public array $objects = [];

	/**
	 * The saveObject calls, in order.
	 *
	 * @var list<array{schema: int, uuid: string|null, data: array<string, mixed>}>
	 */
	public array $saves = [];

	/**
	 * Add an object.
	 *
	 * @param string               $uuid   Its uuid.
	 * @param int                  $schema 10 for a usage, 20 for a connection.
	 * @param array<string, mixed> $data   Its properties.
	 *
	 * @return void
	 */
	public function put(string $uuid, int $schema, array $data): void {
		$this->objects[$uuid] = ['schema' => $schema, 'data' => $data];
	}

	/**
	 * The register config reader over this store.
	 *
	 * @param TestCase $test The test.
	 *
	 * @return RegisterConfigReaderInterface
	 */
	public function config(TestCase $test): RegisterConfigReaderInterface {
		$mock = (new \ReflectionMethod($test, 'createMock'));
		$config = $mock->invoke($test, RegisterConfigReaderInterface::class);
		$config->method('getRegisterIdForObjectType')->willReturn(1);
		$config->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): ?int => ['usage' => 10, 'connection' => 20][$type] ?? null);
		$config->method('getObjectService')->willReturn($this->objectService(test: $test));
		return $config;
	}

	/**
	 * The object service double on the real contract.
	 *
	 * @param TestCase $test The test.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(TestCase $test): ObjectServiceInterface {
		$mock = (new \ReflectionMethod($test, 'createMock'));
		$service = $mock->invoke($test, ObjectServiceInterface::class);
		$service->method('find')->willReturnCallback(
			fn (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?ObjectEntityInterface
				=> (isset($this->objects[(string)$id]) && $this->objects[(string)$id]['schema'] === $schema) ? self::entity((string)$id, $this->objects[(string)$id]['data']) : null
		);
		$service->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query = []): array {
				$schema = $query['@self']['schema'];
				unset($query['@self'], $query['_limit']);
				$results = [];
				foreach ($this->objects as $uuid => $object) {
					if ($object['schema'] !== $schema) {
						continue;
					}

					foreach ($query as $field => $value) {
						if (($object['data'][$field] ?? null) !== $value) {
							continue 2;
						}
					}

					$results[] = self::entity($uuid, $object['data']);
				}

				return ['results' => $results];
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saves[] = ['schema' => (int)$schema, 'uuid' => $uuid, 'data' => $object];
				$uuid ??= 'created-' . count($this->saves);
				$this->put($uuid, (int)$schema, $object);
				return self::entity($uuid, $object);
			}
		);
		return $service;
	}

	/**
	 * An entity on the real contract.
	 *
	 * @param string               $uuid Its uuid.
	 * @param array<string, mixed> $data Its properties.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(string $uuid, array $data): ObjectEntityInterface {
		return new class($uuid, $data) implements ObjectEntityInterface {
			public function __construct(private string $uuid, private array $data) {
			}

			public function getUuid(): ?string {
				return $this->uuid;
			}

			public function getObject(): array {
				return $this->data;
			}

			public function getRegister(): ?string {
				return '1';
			}

			public function getSchema(): ?string {
				return null;
			}

			public function getOrganisation(): ?string {
				return null;
			}

			public function getOwner(): ?string {
				return null;
			}

			public function jsonSerialize(): array {
				return ['id' => $this->uuid] + $this->data;
			}
		};
	}
}
