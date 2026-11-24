<?php

/**
 * Legacy catalogue writer
 *
 * Writes one previewed row of an export from the previous catalogue: the supplier, product and version it has to
 * create as a concept, and the usage of the importing organisation, created or updated on its `legacyId`.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\LegacyCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\LegacyCatalogue;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Service\SearchBounds;
use RuntimeException;
use Throwable;

/**
 * Writes with the caller's own rights (RBAC and multitenancy on): the import never lets a member create what the
 * member could not create by hand. A save that fails skips the row with the step that failed.
 *
 * A supplier, product or version the preview decided to create is created once per run, as a concept: a supplier
 * organisation in status Draft registered by the municipality, a module and a version without a publication date.
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
 */
final class LegacyCatalogueWriter {

	/**
	 * Ids created in this run, by object and key, so two rows naming the same new supplier create it once.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $created = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param array<string, int>     $coordinates   `register` and the schema ids `organization`, `module`, `moduleVersion` and `usage`.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly array $coordinates,
	) {
	}//end __construct()

	/**
	 * Write one previewed row for the organisation.
	 *
	 * @param array<string, mixed> $row The previewed row, as LegacyCatalogueMigrationService::previewRows() answers it.
	 * @param string $consumer The uuid of the importing organisation.
	 *
	 * @return array{outcome: string, reason?: string} `created` or `updated`, or `skipped` with the step that failed.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	public function write(array $row, string $consumer): array {
		$step = 'supplier';
		try {
			$supplier = $this->supplier(row: $row);
			$step = 'product';
			$product = $this->product(row: $row, supplier: $supplier);
			$step = 'version';
			$version = $this->version(row: $row, product: $product);
			$step = 'usage';
			$usage = array_merge(
				$row['usage'],
				['consumer' => $consumer, 'provider' => $supplier, 'module' => $product, 'moduleVersion' => $version]
			);
			return $this->usage(legacyId: (string)$row['legacyId'], usage: $usage);
		} catch (Throwable $e) {
			return ['outcome' => 'skipped', 'reason' => $step . '-not-saved'];
		}
	}//end write()

	/**
	 * The supplier's id: the matched one, or a concept organisation created once per run.
	 *
	 * @param array<string, mixed> $row The previewed row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	private function supplier(array $row): string {
		$supplier = $row['supplier'];
		if ($supplier['id'] !== null) {
			return (string)$supplier['id'];
		}

		$data = ['name' => $supplier['name'], 'type' => 'Supplier', 'status' => 'Draft', 'registeredBy' => 'Municipality'];
		if ($supplier['kvk'] !== '') {
			$data['registrationNumber'] = $supplier['kvk'];
		}

		return $this->createOnce(schemaKey: 'organization', key: mb_strtolower((string)$supplier['name']), data: $data);
	}//end supplier()

	/**
	 * The product's id: the matched module, or a concept module of the supplier created once per run.
	 *
	 * @param array<string, mixed> $row The previewed row.
	 * @param string $supplier The supplier's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	private function product(array $row, string $supplier): string {
		if ($row['product']['id'] !== null) {
			return (string)$row['product']['id'];
		}

		$data = ['name' => $row['product']['name'], 'provider' => $supplier, 'registeredBy' => 'Municipality'];
		return $this->createOnce(schemaKey: 'module', key: $supplier . '/' . $row['product']['name'], data: $data);
	}//end product()

	/**
	 * The version's id: none, the matched one, or a version of the product created once per run.
	 *
	 * @param array<string, mixed> $row The previewed row.
	 * @param string $product The product's id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	private function version(array $row, string $product): ?string {
		$version = $row['version'];
		if ($version['number'] === '') {
			return null;
		}

		if ($version['id'] !== null) {
			return (string)$version['id'];
		}

		$data = ['version' => $version['number'], 'module' => $product, 'registeredBy' => 'Municipality'];
		return $this->createOnce(schemaKey: 'moduleVersion', key: $product . '/' . $version['number'], data: $data);
	}//end version()

	/**
	 * Create or update the organisation's usage with this previous catalogue id.
	 *
	 * @param string $legacyId The previous catalogue's id of the usage.
	 * @param array<string, mixed> $usage The usage fields, with `consumer`.
	 *
	 * @return array{outcome: string}
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	private function usage(string $legacyId, array $usage): array {
		$existing = $this->existingUsage(legacyId: $legacyId, consumer: (string)$usage['consumer']);
		if ($existing !== null) {
			// The legacyId stays as it is: only an admin may change it, and it does not change.
			$this->save(schemaKey: 'usage', data: array_filter($usage, static fn (mixed $value): bool => $value !== null), uuid: $existing);
			return ['outcome' => 'updated'];
		}

		$fields = array_filter($usage, static fn (mixed $value): bool => $value !== null);
		$this->save(schemaKey: 'usage', data: array_merge($fields, ['legacyId' => $legacyId]));
		return ['outcome' => 'created'];
	}//end usage()

	/**
	 * The uuid of the organisation's usage with this previous catalogue id, or null.
	 *
	 * @param string $legacyId The previous catalogue's id.
	 * @param string $consumer The organisation's uuid.
	 *
	 * @return string|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchBounds::bounded() is the pure query guard every search in lib/ shares;
	 * LegacyCatalogueCandidates::reference() is the pure reference reader the migration shares.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	private function existingUsage(string $legacyId, string $consumer): ?string {
		$results = $this->objectService->searchObjects(
			query: SearchBounds::bounded(
				query: [
					'@self' => ['register' => $this->coordinates['register'], 'schema' => $this->coordinates['usage']],
					'legacyId' => $legacyId,
					'_limit' => 10,
				]
			)
		);
		foreach ((array)$results as $entity) {
			$data = $entity->getObject();
			if (($data['legacyId'] ?? null) === $legacyId && LegacyCatalogueCandidates::reference(value: $data['consumer'] ?? null) === $consumer) {
				return $entity->getUuid();
			}
		}

		return null;
	}//end existingUsage()

	/**
	 * Create an object once per run for its key, and answer the id.
	 *
	 * @param string $schemaKey The schema key.
	 * @param string $key What makes it the same object within the run.
	 * @param array<string, mixed> $data The object.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	private function createOnce(string $schemaKey, string $key, array $data): string {
		$this->created[$schemaKey][$key] ??= $this->save(schemaKey: $schemaKey, data: $data);
		return $this->created[$schemaKey][$key];
	}//end createOnce()

	/**
	 * Save an object with the caller's rights and answer its uuid.
	 *
	 * @param string $schemaKey The schema key.
	 * @param array<string, mixed> $data The object.
	 * @param string|null $uuid The object to update, or null to create.
	 *
	 * @return string The uuid.
	 *
	 * @throws RuntimeException When OpenRegister answers no uuid.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	private function save(string $schemaKey, array $data, ?string $uuid = null): string {
		$entity = $this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $this->coordinates['register'], schema: $this->coordinates[$schemaKey]),
				uuid: $uuid
			)
		);
		$saved = $entity->getUuid();
		if ($saved === null || $saved === '') {
			throw new RuntimeException('OpenRegister saved an object without a uuid');
		}

		return $saved;
	}//end save()
}//end class
