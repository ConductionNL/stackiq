<?php

/**
 * Contract of PublicationService
 *
 * The public surface of PublicationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

/**
 * Contract of PublicationService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicationServiceInterface {

	/**
	 * Depublish a catalog entry: set `depublicatiedatum` to now and clear
	 * `publicatiedatum`, so the `$lte:$now` public predicate no longer matches
	 * and the entry leaves the anonymous + federation read surface.
	 *
	 * @param string $objectType The catalog object type.
	 * @param string $uuid The entry uuid.
	 *
	 * @return array{ok:bool, reason:string, publicationDate:?string} Result.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	public function depublish(string $objectType, string $uuid): array;

	/**
	 * Whether the given object type is publishable as open data.
	 *
	 * @param string $objectType The catalog object type.
	 *
	 * @return bool True when the type carries the publish gate.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	public function isPublishableType(string $objectType): bool;

	/**
	 * Publish a catalog entry as open data: set `publicatiedatum` to now (when
	 * not already set) and clear any `depublicatiedatum`. The schema's public
	 * read predicate then makes the entry anonymously visible.
	 *
	 * The caller MUST have authorized the mutation (ownership/manage check) —
	 * this service performs the write only; it is never reachable from an
	 * unauthenticated route.
	 *
	 * @param string $objectType The catalog object type.
	 * @param string $uuid The entry uuid.
	 * @param string|null $when Optional ISO-8601 publication moment
	 *                          (defaults to now); a future value keeps
	 *                          the entry hidden until that moment.
	 *
	 * @return array{ok:bool, reason:string, publicationDate:?string} Result.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	public function publish(string $objectType, string $uuid, ?string $when = null): array;

	/**
	 * Resolve the data bag of a catalog entry, with its register/schema, so the
	 * caller can run an ownership (IDOR) check before mutating it.
	 *
	 * @param string $objectType The catalog object type.
	 * @param string $uuid The entry uuid.
	 *
	 * @return array{register:int, schema:int, data:array<string,mixed>}|null
	 *                                                                        The resolved entry, or null when not resolvable/found.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	public function resolveEntry(string $objectType, string $uuid): ?array;
}//end interface
