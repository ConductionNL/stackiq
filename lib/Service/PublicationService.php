<?php
/**
 * Publication Service.
 *
 * Open-data publish / depublish of catalog entries using the LIVE OpenRegister
 * RBAC publication model: "published" = a `publicatiedatum` (publication date)
 * in the past/present on the object, surfaced anonymously by the schema's
 * `authorization.read` rule `{group:public, match:{publicatiedatum:{$lte:$now}}}`.
 *
 * Publishing sets `publicatiedatum` (and clears any `depublicatiedatum`) via the
 * OpenRegister `ObjectService::saveObject()` abstraction (ADR-022) — there is no
 * app-local `published` flag and the deprecated/removed `@self.published`
 * predicate and `ObjectService::publish()` are NOT used.
 *
 * Depublishing sets `depublicatiedatum` and clears `publicatiedatum`, so the
 * `$lte:$now` predicate no longer matches and the entry leaves the anonymous
 * (public + federation) read surface.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets/clears the publicatiedatum publish gate on catalog entries.
 */
class PublicationService
{
    /**
     * The object types that carry the open-data publish gate
     * (publicatiedatum/depublicatiedatum + the public read predicate).
     *
     * @var array<int,string>
     */
    public const PUBLISHABLE_TYPES = ['dienst', 'module', 'koppeling', 'organisatie'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container       The DI container (lazy OR lookup).
     * @param SettingsService    $settingsService Resolves register/schema ids.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Whether the given object type is publishable as open data.
     *
     * @param string $objectType The catalog object type.
     *
     * @return bool True when the type carries the publish gate.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function isPublishableType(string $objectType): bool
    {
        return in_array($objectType, self::PUBLISHABLE_TYPES, true);
    }//end isPublishableType()

    /**
     * Resolve the data bag of a catalog entry, with its register/schema, so the
     * caller can run an ownership (IDOR) check before mutating it.
     *
     * @param string $objectType The catalog object type.
     * @param string $uuid       The entry uuid.
     *
     * @return array{register:int, schema:int, data:array<string,mixed>}|null
     *               The resolved entry, or null when not resolvable/found.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function resolveEntry(string $objectType, string $uuid): ?array
    {
        if ($this->isPublishableType(objectType: $objectType) === false) {
            return null;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $schemaId   = $this->settingsService->getSchemaIdForObjectType($objectType);
        $registerId = $this->settingsService->getRegisterIdForObjectType($objectType);
        if ($schemaId === null || $registerId === null) {
            $this->logger->warning(
                'PublicationService: register/schema not configured',
                ['objectType' => $objectType]
            );
            return null;
        }

        try {
            $entity = $objectService->find(
                id: $uuid,
                register: (string) $registerId,
                schema: (string) $schemaId
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'PublicationService: entry not found',
                ['objectType' => $objectType, 'uuid' => $uuid]
            );
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return [
            'register' => (int) $registerId,
            'schema'   => (int) $schemaId,
            'data'     => $entity->getObject(),
        ];
    }//end resolveEntry()

    /**
     * Publish a catalog entry as open data: set `publicatiedatum` to now (when
     * not already set) and clear any `depublicatiedatum`. The schema's public
     * read predicate then makes the entry anonymously visible.
     *
     * The caller MUST have authorized the mutation (ownership/manage check) —
     * this service performs the write only; it is never reachable from an
     * unauthenticated route.
     *
     * @param string      $objectType The catalog object type.
     * @param string      $uuid       The entry uuid.
     * @param string|null $when       Optional ISO-8601 publication moment
     *                                (defaults to now); a future value keeps
     *                                the entry hidden until that moment.
     *
     * @return array{ok:bool, reason:string, publicatiedatum:?string} Result.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function publish(string $objectType, string $uuid, ?string $when=null): array
    {
        $entry = $this->resolveEntry(objectType: $objectType, uuid: $uuid);
        if ($entry === null) {
            return ['ok' => false, 'reason' => 'entry not resolvable', 'publicatiedatum' => null];
        }

        $publicatiedatum = $this->normaliseDate(when: $when) ?? $this->now();

        $data = $entry['data'];
        $data['publicatiedatum']   = $publicatiedatum;
        $data['depublicatiedatum'] = null;

        return $this->save(
            objectType: $objectType,
            uuid: $uuid,
            entry: $entry,
            data: $data,
            publicatiedatum: $publicatiedatum,
            action: 'published'
        );
    }//end publish()

    /**
     * Depublish a catalog entry: set `depublicatiedatum` to now and clear
     * `publicatiedatum`, so the `$lte:$now` public predicate no longer matches
     * and the entry leaves the anonymous + federation read surface.
     *
     * @param string $objectType The catalog object type.
     * @param string $uuid       The entry uuid.
     *
     * @return array{ok:bool, reason:string, publicatiedatum:?string} Result.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function depublish(string $objectType, string $uuid): array
    {
        $entry = $this->resolveEntry(objectType: $objectType, uuid: $uuid);
        if ($entry === null) {
            return ['ok' => false, 'reason' => 'entry not resolvable', 'publicatiedatum' => null];
        }

        $data = $entry['data'];
        $data['publicatiedatum']   = null;
        $data['depublicatiedatum'] = $this->now();

        return $this->save(
            objectType: $objectType,
            uuid: $uuid,
            entry: $entry,
            data: $data,
            publicatiedatum: null,
            action: 'depublished'
        );
    }//end depublish()

    /**
     * Persist the mutated data bag via the OpenRegister ObjectService.
     *
     * @param string                                                  $objectType      The object type (logging).
     * @param string                                                  $uuid            The entry uuid.
     * @param array{register:int,schema:int,data:array<string,mixed>} $entry           The resolved entry.
     * @param array<string,mixed>                                     $data            The mutated data bag.
     * @param string|null                                             $publicatiedatum The resulting publicatiedatum.
     * @param string                                                  $action          'published'|'depublished' (logging).
     *
     * @return array{ok:bool, reason:string, publicatiedatum:?string} Result.
     */
    private function save(
        string $objectType,
        string $uuid,
        array $entry,
        array $data,
        ?string $publicatiedatum,
        string $action
    ): array {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['ok' => false, 'reason' => 'ObjectService unavailable', 'publicatiedatum' => null];
        }

        try {
            $objectService->saveObject(
                object: $data,
                register: $entry['register'],
                schema: $entry['schema'],
                uuid: $uuid
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'PublicationService: save failed',
                ['objectType' => $objectType, 'uuid' => $uuid, 'error' => $e->getMessage()]
            );
            return ['ok' => false, 'reason' => $e->getMessage(), 'publicatiedatum' => null];
        }

        $this->logger->info(
            'PublicationService: '.$action,
            ['objectType' => $objectType, 'uuid' => $uuid, 'publicatiedatum' => $publicatiedatum]
        );

        return ['ok' => true, 'reason' => $action, 'publicatiedatum' => $publicatiedatum];
    }//end save()

    /**
     * Normalise a caller-supplied date to a comparable ISO-8601 string, or null
     * when absent/invalid.
     *
     * @param string|null $when The candidate value.
     *
     * @return string|null The normalised date, or null.
     */
    private function normaliseDate(?string $when): ?string
    {
        if ($when === null || trim($when) === '') {
            return null;
        }

        $ts = strtotime($when);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:sP', $ts);
    }//end normaliseDate()

    /**
     * The current moment as a comparable ISO-8601 string.
     *
     * @return string Now.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:sP');
    }//end now()

    /**
     * Get the OpenRegister ObjectService from the DI container.
     *
     * @return ObjectService|null The object service, or null when OR is absent.
     */
    private function getObjectService(): ?ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'PublicationService: Failed to get ObjectService',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()
}//end class
