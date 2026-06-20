<?php
/**
 * Anonymous self-service registration intake.
 *
 * Hardened entry point for anonymous (self-service) organisation registrations.
 * A submission lands in a MODERATION state — `registratiestatus = pending` —
 * and is deliberately NOT given a `publicatiedatum`, so the public RBAC read
 * gate (`{group:public, match:{publicatiedatum:{$lte:$now}}}`) keeps it invisible
 * to anonymous open-data / federation readers until an admin approves it.
 *
 * The service is write-only to the moderation queue: it validates + sanitises
 * the anonymous payload (required fields, size caps, no caller-controlled
 * status/publicatiedatum/provenance), refuses obvious duplicate pending
 * submissions, and persists via the OpenRegister ObjectService (ADR-022). It
 * never provisions an account or grants any access — approval is a separate,
 * admin-gated step (ModerationService).
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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates + queues anonymous organisation registrations as pending.
 */
class IntakeService
{
    /**
     * The catalog object type anonymous registrations create.
     */
    public const INTAKE_TYPE = 'organisatie';

    /**
     * Moderation state of a freshly-submitted anonymous registration.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Required fields on an anonymous intake payload.
     *
     * @var array<int,string>
     */
    public const REQUIRED_FIELDS = ['naam'];

    /**
     * Maximum number of fields accepted on an anonymous payload (anti-abuse).
     */
    public const MAX_FIELDS = 60;

    /**
     * Maximum length of any single string value (anti-abuse).
     */
    public const MAX_FIELD_LENGTH = 5000;

    /**
     * Caller-controlled keys that MUST be stripped from an anonymous payload —
     * a self-service submitter may never set its own moderation state,
     * publication date, identifiers, or federation provenance.
     *
     * @var array<int,string>
     */
    public const FORBIDDEN_KEYS = [
        'registratiestatus',
        'publicatiedatum',
        'depublicatiedatum',
        '_source',
        'id',
        'uuid',
        'beoordeling',
        'owner',
        '_organisation',
    ];

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
     * Submit an anonymous organisation registration into the moderation queue.
     *
     * Validates + sanitises the payload, refuses a duplicate pending submission
     * for the same organisation name, then persists it with
     * `registratiestatus = pending` and NO `publicatiedatum` (so the public RBAC
     * gate keeps it invisible until approval).
     *
     * @param array<string,mixed> $payload The anonymous registration payload.
     *
     * @return array{ok:bool, reason:string, uuid:?string, status:?string} Result.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function submit(array $payload): array
    {
        $validation = $this->validate($payload);
        if ($validation !== null) {
            return ['ok' => false, 'reason' => $validation, 'uuid' => null, 'status' => null];
        }

        $clean = $this->sanitise($payload);

        $target = $this->resolveTarget();
        if ($target === null) {
            return ['ok' => false, 'reason' => 'intake register/schema not configured', 'uuid' => null, 'status' => null];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['ok' => false, 'reason' => 'ObjectService unavailable', 'uuid' => null, 'status' => null];
        }

        if ($this->hasPendingDuplicate($objectService, $target, (string) $clean['naam']) === true) {
            return [
                'ok'     => false,
                'reason' => 'a pending registration for this organisation already exists',
                'uuid'   => null,
                'status' => null,
            ];
        }

        // Enforce the moderation defaults server-side (never from the caller):
        // pending + no publicatiedatum → invisible to the public RBAC gate.
        $clean['registratiestatus'] = self::STATUS_PENDING;
        $clean['publicatiedatum']   = null;

        try {
            $entity = $objectService->saveObject(
                object: $clean,
                register: $target['register'],
                schema: $target['schema']
            );
        } catch (\Throwable $e) {
            $this->logger->error('IntakeService: submit failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'reason' => 'could not store registration', 'uuid' => null, 'status' => null];
        }

        $uuid = $this->entityUuid($entity);
        $this->logger->info(
            'IntakeService: anonymous registration queued (pending)',
            ['uuid' => $uuid, 'naam' => $clean['naam']]
        );

        return ['ok' => true, 'reason' => 'queued for moderation', 'uuid' => $uuid, 'status' => self::STATUS_PENDING];
    }//end submit()

    /**
     * Validate an anonymous payload (anti-spam): required fields present, field
     * count + value sizes capped. Returns a reason string on failure, or null
     * when the payload is acceptable.
     *
     * @param array<string,mixed> $payload The payload.
     *
     * @return string|null The rejection reason, or null when valid.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function validate(array $payload): ?string
    {
        if ($payload === []) {
            return 'empty payload';
        }

        if (count($payload) > self::MAX_FIELDS) {
            return 'too many fields';
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $payload[$field] ?? null;
            if (is_string($value) === false || trim($value) === '') {
                return 'missing required field: '.$field;
            }
        }

        foreach ($payload as $value) {
            if (is_string($value) === true && strlen($value) > self::MAX_FIELD_LENGTH) {
                return 'field value exceeds the maximum length';
            }
        }

        return null;
    }//end validate()

    /**
     * Strip the caller-controlled / privileged keys from an anonymous payload.
     *
     * @param array<string,mixed> $payload The raw payload.
     *
     * @return array<string,mixed> The sanitised payload.
     */
    private function sanitise(array $payload): array
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }//end sanitise()

    /**
     * Whether a pending registration already exists for the given org name.
     *
     * @param object                         $objectService The OR ObjectService.
     * @param array{register:int,schema:int} $target        The intake register/schema.
     * @param string                         $naam          The organisation name.
     *
     * @return bool True when a pending duplicate exists.
     */
    private function hasPendingDuplicate(object $objectService, array $target, string $naam): bool
    {
        try {
            $matches = $objectService->searchObjects(
                query: [
                    '@self'             => ['register' => $target['register'], 'schema' => $target['schema']],
                    'naam'              => $naam,
                    'registratiestatus' => self::STATUS_PENDING,
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->warning('IntakeService: duplicate check failed (allowing)', ['error' => $e->getMessage()]);
            return false;
        }

        return is_array($matches) === true && $matches !== [];
    }//end hasPendingDuplicate()

    /**
     * Resolve the register/schema anonymous registrations land in.
     *
     * @return array{register:int, schema:int}|null The target, or null.
     */
    private function resolveTarget(): ?array
    {
        $register = $this->settingsService->getRegisterIdForObjectType(self::INTAKE_TYPE);
        $schema   = $this->settingsService->getSchemaIdForObjectType(self::INTAKE_TYPE);
        if ($register === null || $schema === null) {
            return null;
        }

        return ['register' => (int) $register, 'schema' => (int) $schema];
    }//end resolveTarget()

    /**
     * The uuid of a saved entity (handles entity or array result shapes).
     *
     * @param mixed $entity The saveObject result.
     *
     * @return string|null The uuid, or null.
     */
    private function entityUuid(mixed $entity): ?string
    {
        if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {
            $uuid = $entity->getUuid();
            if (is_string($uuid) === true) {
                return $uuid;
            }

            return null;
        }

        if (is_array($entity) === true) {
            $uuid = $entity['id'] ?? $entity['uuid'] ?? null;
            if (is_string($uuid) === true) {
                return $uuid;
            }

            return null;
        }

        return null;
    }//end entityUuid()

    /**
     * Get the OpenRegister ObjectService from the DI container.
     *
     * @return object|null The object service, or null when OR is absent.
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error('IntakeService: ObjectService unavailable', ['error' => $e->getMessage()]);
            return null;
        }
    }//end getObjectService()
}//end class
