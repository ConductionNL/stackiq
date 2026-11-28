<?php

/**
 * The published aanbod entries, for a caller without a session.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/archive/2026-11-24-anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use JsonSerializable;
use OCA\Stackiq\Kernel\Contracts\PublishedAanbodInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the published modules, diensten and koppelingen.
 *
 * Published means a `publicationDate` at or before now and no
 * `depublicationDate` that has passed. The read keeps OpenRegister's RBAC on,
 * so the schemas' public read rules and property rules still apply, and this
 * class checks the publication dates again itself: a public rule that admits
 * more than the published entries never widens this answer. No organisation
 * is resolved, and `@self` is reduced to the entry's id, so no
 * organisation-scoped field leaves.
 *
 * @spec openspec/changes/archive/2026-11-24-anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
 */
class PublishedAanbod implements PublishedAanbodInterface {
	use ParsesStoredDates;

	/**
	 * The most entries one schema read returns.
	 */
	public const LIMIT = 500;

	/**
	 * The default page size, as AanbodService::getAanbod() uses it.
	 */
	private const DEFAULT_PAGE_SIZE = 20;

	/**
	 * The aanbod schemas an anonymous caller may see, by voorzieningen config key.
	 *
	 * @var array<string,string>
	 */
	private const SCHEMAS = [
		'koppeling_schema' => 'connection',
		'module_schema' => 'module',
		'dienst_schema' => 'catalogService',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $settingsService Resolves the register and schema ids.
	 * @param ContainerInterface            $container       Lazy OpenRegister lookup.
	 * @param LoggerInterface               $logger          Logger.
	 */
	public function __construct(
		private readonly RegisterConfigReaderInterface $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One page of the published aanbod entries in the open-data projection.
	 *
	 * @param array<string,mixed> $options Query options (_limit/limit, _page, _offset/offset).
	 *
	 * @return array{results: array, total: int, page: int, pages: int, limit: int, offset: int}
	 *
	 * @spec openspec/changes/archive/2026-11-24-anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
	 */
	public function page(array $options = []): array {
		$config = $this->settingsService->getVoorzieningenConfig();
		$registerId = $config['register'] ?? null;
		if ($registerId === null || $registerId === '') {
			return (new EmptyListing())->page();
		}

		$now = new DateTimeImmutable();
		$entries = [];
		foreach (self::SCHEMAS as $configKey => $type) {
			$schemaId = $config[$configKey] ?? null;
			if ($schemaId === null || $schemaId === '') {
				continue;
			}

			foreach ($this->search(registerId: $registerId, schemaId: $schemaId) as $entry) {
				if ($this->isPublished(entry: $entry, now: $now) === true) {
					$entries[] = $this->project(entry: $entry, type: $type);
				}
			}
		}

		return $this->paginate(entries: $entries, options: $options);
	}//end page()

	/**
	 * Whether an entry is published now.
	 *
	 * @param array<string,mixed> $entry The entry.
	 * @param DateTimeImmutable   $now   The moment.
	 *
	 * @return bool True when the publication date has come and no depublication date has.
	 *
	 * @spec openspec/changes/archive/2026-11-24-anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
	 */
	public function isPublished(array $entry, DateTimeImmutable $now): bool {
		$published = $this->storedDate(value: $entry['publicationDate'] ?? null);
		if ($published === null || $published > $now) {
			return false;
		}

		$withdrawn = $this->storedDate(value: $entry['depublicationDate'] ?? null);

		return $withdrawn === null || $withdrawn > $now;
	}//end isPublished()

	/**
	 * The open-data projection of an entry: its own fields, `@self` reduced to the id.
	 *
	 * @param array<string,mixed> $entry The entry.
	 * @param string              $type  The aanbod type.
	 *
	 * @return array<string,mixed> The projected entry.
	 *
	 * @spec openspec/changes/archive/2026-11-24-anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
	 */
	public function project(array $entry, string $type): array {
		$id = $entry['id'] ?? ($entry['@self']['id'] ?? null);
		$projected = [];
		foreach ($entry as $key => $value) {
			// @self and underscore keys carry the organisation, owner and
			// other internal metadata.
			if (is_string($key) === true && ($key === '@self' || str_starts_with($key, '_') === true)) {
				continue;
			}

			$projected[$key] = $value;
		}

		if ($id !== null) {
			$projected['id'] = $id;
		}

		$projected['_aanbod_type'] = $type;

		return $projected;
	}//end project()

	/**
	 * The entries of one schema, RBAC on, bounded.
	 *
	 * @param int|string $registerId The register id.
	 * @param int|string $schemaId   The schema id.
	 *
	 * @return list<array<string,mixed>> The entries, empty when the read fails.
	 */
	private function search(int|string $registerId, int|string $schemaId): array {
		try {
			$result = $this->container->get('OCA\OpenRegister\Service\ObjectService')->searchObjectsPaginated(
				query: ['@self' => ['register' => $registerId, 'schema' => $schemaId], '_limit' => self::LIMIT],
				_rbac: true,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('PublishedAanbod: search failed', ['schema' => $schemaId, 'error' => $e->getMessage()]);
			return [];
		}

		$entries = [];
		foreach (($result['results'] ?? []) as $row) {
			if ($row instanceof JsonSerializable) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$entries[] = $row;
			}
		}

		return $entries;
	}//end search()

	/**
	 * One page of the combined entries.
	 *
	 * @param list<array<string,mixed>> $entries The entries.
	 * @param array<string,mixed>       $options The query options.
	 *
	 * @return array{results: array, total: int, page: int, pages: int, limit: int, offset: int}
	 */
	private function paginate(array $entries, array $options): array {
		$limit = (int)($options['_limit'] ?? ($options['limit'] ?? self::DEFAULT_PAGE_SIZE));
		if ($limit < 1) {
			$limit = self::DEFAULT_PAGE_SIZE;
		}

		$page = max(1, (int)($options['_page'] ?? 1));
		$offset = (($page - 1) * $limit);
		if (isset($options['_offset']) === true || isset($options['offset']) === true) {
			$offset = max(0, (int)($options['_offset'] ?? $options['offset']));
		}

		$total = count($entries);

		return [
			'results' => array_slice($entries, $offset, $limit),
			'total' => $total,
			'page' => $page,
			'pages' => (int)ceil($total / $limit),
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end paginate()
}//end class
