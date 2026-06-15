<?php
/**
 * Registration moderation / approval queue.
 *
 * The admin-only counterpart to IntakeService. Lists the pending anonymous
 * registrations and decides each one:
 *  - APPROVE  → `registratiestatus = active` AND `publicatiedatum = now`, so the
 *               public RBAC read gate (`publicatiedatum<=$now`) makes the entry
 *               anonymously visible (the exact same gate as open-data publish).
 *  - REJECT   → `registratiestatus = rejected`, never given a `publicatiedatum`,
 *               so it stays invisible.
 *
 * All writes go through the OpenRegister ObjectService (ADR-022). The service
 * itself performs no auth — it is only ever reachable from the admin-gated
 * ModerationController.
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
 * Lists + decides the anonymous-registration moderation queue.
 */
class ModerationService
{
    /**
     * The moderated catalog object type.
     */
    public const MODERATED_TYPE = 'organisatie';

    /**
     * Pending (awaiting moderation) state.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Approved (active, publishable) state.
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Rejected state.
     */
    public const STATUS_REJECTED = 'rejected';

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
     * List the pending anonymous registrations awaiting moderation.
     *
     * @return array{ok:bool, reason:string, items:array<int,array<string,mixed>>}
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function listPending(): array
    {
        $target = $this->resolveTarget();
        if ($target === null) {
            return ['ok' => false, 'reason' => 'register/schema not configured', 'items' => []];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['ok' => false, 'reason' => 'ObjectService unavailable', 'items' => []];
        }

        try {
            $objects = $objectService->searchObjects(
                query: [
                    '@self'             => ['register' => $target['register'], 'schema' => $target['schema']],
                    'registratiestatus' => self::STATUS_PENDING,
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error('ModerationService: listPending failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'reason' => 'query failed', 'items' => []];
        }

        $items = [];
        foreach ((is_array($objects) ? $objects : []) as $object) {
            $items[] = $this->toDataBag($object);
        }

        return ['ok' => true, 'reason' => 'ok', 'items' => $items];
    }//end listPending()

    /**
     * Approve a pending registration: set it active AND publish it (set
     * `publicatiedatum`), making it anonymously visible via the RBAC gate.
     *
     * @param string $uuid The registration uuid.
     *
     * @return array{ok:bool, reason:string, status:?string} Result.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function approve(string $uuid): array
    {
        return $this->decide(
            $uuid,
            static function (array $data): array {
                $data['registratiestatus'] = self::STATUS_ACTIVE;
                $data['publicatiedatum']   = gmdate('Y-m-d\TH:i:sP');
                $data['depublicatiedatum'] = null;
                return $data;
            },
            self::STATUS_ACTIVE,
            'approved'
        );
    }//end approve()

    /**
     * Reject a pending registration: set it rejected and never give it a
     * `publicatiedatum` (it stays invisible to anonymous readers).
     *
     * @param string $uuid The registration uuid.
     *
     * @return array{ok:bool, reason:string, status:?string} Result.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function reject(string $uuid): array
    {
        return $this->decide(
            $uuid,
            static function (array $data): array {
                $data['registratiestatus'] = self::STATUS_REJECTED;
                $data['publicatiedatum']   = null;
                return $data;
            },
            self::STATUS_REJECTED,
            'rejected'
        );
    }//end reject()

    /**
     * Apply a moderation decision to a pending registration.
     *
     * Only a registration that is currently `pending` may be decided — this
     * keeps the action idempotent and prevents re-deciding an already-approved
     * entry (which would re-stamp its publicatiedatum).
     *
     * @param string                                   $uuid    The registration uuid.
     * @param callable(array<string,mixed>):array<string,mixed> $mutator The state mutation.
     * @param string                                   $status  The resulting status.
     * @param string                                   $action  Log label.
     *
     * @return array{ok:bool, reason:string, status:?string} Result.
     */
    private function decide(string $uuid, callable $mutator, string $status, string $action): array
    {
        $target = $this->resolveTarget();
        if ($target === null) {
            return ['ok' => false, 'reason' => 'register/schema not configured', 'status' => null];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['ok' => false, 'reason' => 'ObjectService unavailable', 'status' => null];
        }

        try {
            $entity = $objectService->find(
                id: $uuid,
                register: (string) $target['register'],
                schema: (string) $target['schema'],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'registration not found', 'status' => null];
        }

        if ($entity === null) {
            return ['ok' => false, 'reason' => 'registration not found', 'status' => null];
        }

        $data = $this->toDataBag($entity);

        // A peer-sourced (federated) mirror is never moderated locally.
        if (is_array($data['_source'] ?? null) === true && trim((string) ($data['_source']['instance'] ?? '')) !== '') {
            return ['ok' => false, 'reason' => 'peer-sourced entries cannot be moderated locally', 'status' => null];
        }

        if (($data['registratiestatus'] ?? null) !== self::STATUS_PENDING) {
            return ['ok' => false, 'reason' => 'registration is not pending moderation', 'status' => null];
        }

        $data = $mutator($data);
        unset($data['id']);

        try {
            $objectService->saveObject(
                object: $data,
                register: $target['register'],
                schema: $target['schema'],
                uuid: $uuid
            );
        } catch (\Throwable $e) {
            $this->logger->error('ModerationService: '.$action.' failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return ['ok' => false, 'reason' => 'could not update registration', 'status' => null];
        }

        $this->logger->info('ModerationService: registration '.$action, ['uuid' => $uuid, 'status' => $status]);
        return ['ok' => true, 'reason' => $action, 'status' => $status];
    }//end decide()

    /**
     * Resolve the moderated register/schema.
     *
     * @return array{register:int, schema:int}|null The target, or null.
     */
    private function resolveTarget(): ?array
    {
        $register = $this->settingsService->getRegisterIdForObjectType(self::MODERATED_TYPE);
        $schema   = $this->settingsService->getSchemaIdForObjectType(self::MODERATED_TYPE);
        if ($register === null || $schema === null) {
            return null;
        }
        return ['register' => (int) $register, 'schema' => (int) $schema];
    }//end resolveTarget()

    /**
     * Normalise an ObjectService result item to a data bag (with its uuid).
     *
     * @param mixed $object The result item (ObjectEntity or array).
     *
     * @return array<string,mixed> The data bag.
     */
    private function toDataBag(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }
        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (method_exists($object, 'getUuid') === true && empty($data['id']) === true) {
                $data['id'] = $object->getUuid();
            }
            return is_array($data) ? $data : [];
        }
        return [];
    }//end toDataBag()

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
            $this->logger->error('ModerationService: ObjectService unavailable', ['error' => $e->getMessage()]);
            return null;
        }
    }//end getObjectService()
}//end class
