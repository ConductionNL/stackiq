<?php
/**
 * Contract Approval Delegation Service.
 *
 * Delegates the contract approval / sign-off / renewal DECISION (the
 * `In onderhandeling -> Actief` transition, and the re-approval of an
 * expiring/`Verlopen` contract) to decidesk — the canonical fleet decision
 * authority (cross-app interface contract #1) — through the ADR-019
 * integration registry. softwarecatalog keeps the contract RECORD locally
 * and PROJECTS the decidesk outcome onto two catalog-local fields
 * (`approvalDecisionId`, `approvalState`).
 *
 * FAIL-CLOSED CONTRACT (mirrors the hydra-gate-unsafe-auth-resolver pattern):
 * a "service unavailable" / "endpoint not resolved" condition NEVER results in
 * an auto-approval. The decidesk endpoint is resolved through the integration
 * registry (the decidesk app installed + enabled on this instance, addressed
 * via the instance's own IURLGenerator — never a hard-coded host). When no
 * endpoint resolves, or the call fails, this service throws and the contract
 * stays `In onderhandeling`; `status` is never set to `Actief` on local
 * authority.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Raises and projects contract approval decisions delegated to decidesk.
 *
 * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
 */
class ContractApprovalService
{
    /**
     * The decidesk app id used to resolve the decision-hub endpoint through
     * the integration registry (stage-1 existence filter, ADR-019).
     */
    public const DECIDESK_APP_ID = 'decidesk';

    /**
     * The decisionType raised for a first activation of an `In onderhandeling`
     * contract (matches the decidesk Decision `decisionType` enum value).
     */
    public const DECISION_TYPE_APPROVAL = 'contract';

    /**
     * The decisionType raised for re-approval of an expiring/`Verlopen`
     * contract (additive decidesk enum value, decidesk-contract-decision-hub).
     */
    public const DECISION_TYPE_RENEWAL = 'contract-renewal';

    /**
     * The catalog register slug that owns the contract record.
     */
    public const SUBJECT_REGISTER = 'voorzieningen';

    /**
     * The contract schema slug.
     */
    public const SUBJECT_SCHEMA = 'contract';

    /**
     * Catalog lifecycle status: in negotiation (the pre-approval state).
     */
    public const STATUS_NEGOTIATION = 'In onderhandeling';

    /**
     * Catalog lifecycle status: active (only ever reached via an `approved`
     * decidesk outcome — never set on local authority).
     */
    public const STATUS_ACTIVE = 'Actief';

    /**
     * Projection state: no decision raised.
     */
    public const APPROVAL_NONE = 'none';

    /**
     * Projection state: a decidesk Decision is open.
     */
    public const APPROVAL_PENDING = 'pending';

    /**
     * Projection state: decidesk reported an adopting outcome.
     */
    public const APPROVAL_APPROVED = 'approved';

    /**
     * Projection state: decidesk rejected or withdrew the decision.
     */
    public const APPROVAL_REJECTED = 'rejected';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container       The DI container (lazy OR lookup).
     * @param SettingsService    $settingsService Resolves register/schema ids.
     * @param IAppManager        $appManager      Resolves the decidesk endpoint (stage-1 filter).
     * @param IClientService     $clientService   HTTP client factory for the registry call.
     * @param IURLGenerator      $urlGenerator    Builds the instance-local decidesk URL (no hard-coded host).
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Whether the decidesk decision-hub endpoint resolves on this instance.
     *
     * Drives the ContractDetail Approval panel: when false, the panel hides its
     * submit action ("approval delegation not configured") so no fail-open path
     * exists. The host is taken from the instance's own URLGenerator — never a
     * hard-coded decidesk hostname.
     *
     * @return bool True when a decidesk decision endpoint resolves.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    public function isDelegationConfigured(): bool
    {
        return $this->resolveDecisionEndpoint() !== null;

    }//end isDelegationConfigured()

    /**
     * Stage-1 existence filter: is the decidesk app installed and enabled?
     *
     * @return bool True when the decidesk app is present.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function isDecideskAvailable(): bool
    {
        try {
            $installed = in_array(self::DECIDESK_APP_ID, $this->appManager->getInstalledApps(), true);
            return $installed === true && $this->appManager->isInstalled(appId: self::DECIDESK_APP_ID) === true;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'ContractApprovalService: decidesk availability check failed (treated as unavailable)',
                ['error' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end isDecideskAvailable()

    /**
     * Resolve the absolute decidesk decision-hub base URL via the integration
     * registry (the instance's own base URL — never a hard-coded host).
     *
     * @return string|null The absolute base URL, or null when not configured.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function resolveDecisionEndpoint(): ?string
    {
        if ($this->isDecideskAvailable() === false) {
            return null;
        }

        try {
            // The linkToRoute call resolves an instance-relative path;
            // getAbsoluteURL prefixes the instance's OWN base URL. No decidesk
            // hostname is hard-coded. When the decidesk hub route is not
            // registered (the hub change not yet deployed on this instance)
            // linkToRoute throws
            // and we fail closed. The route name matches the decidesk hub
            // IntegrationController (decidesk-contract-decision-hub REQ-DCDH-002,
            // POST /apps/decidesk/api/v1/decisions).
            $path = $this->urlGenerator->linkToRoute(routeName: 'decidesk.integration.create');
            return $this->urlGenerator->getAbsoluteURL(url: $path);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'ContractApprovalService: decidesk decision route unavailable (hub not deployed); failing closed',
                ['error' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end resolveDecisionEndpoint()

    /**
     * Raise a decidesk Decision for a contract approval or renewal.
     *
     * FAIL-CLOSED: when no decidesk endpoint resolves, or the call fails, this
     * throws a RuntimeException and the caller leaves the contract
     * `In onderhandeling` with `approvalState` unchanged. On success it
     * persists the returned decision id to `approvalDecisionId`, sets
     * `approvalState = pending`, subscribes to the outcome, and returns the
     * decision id. `status` is NEVER set to `Actief` here.
     *
     * @param string $contractUuid The contract OR object uuid.
     * @param bool   $isRenewal    When true, raise decisionType=contract-renewal.
     *
     * @return string The decidesk decision id.
     *
     * @throws \RuntimeException When delegation is not configured or the call fails.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    public function submitForApproval(string $contractUuid, bool $isRenewal=false): string
    {
        $endpoint = $this->resolveDecisionEndpoint();
        if ($endpoint === null) {
            // Fail closed — never auto-approve when the hub is unavailable.
            throw new \RuntimeException('Contract approval delegation is not configured (decidesk endpoint not resolvable).');
        }

        $contract = $this->loadContract(contractUuid: $contractUuid);
        if ($contract === null) {
            throw new \RuntimeException('Contract not found: '.$contractUuid);
        }

        $data = $contract->getObject();

        $decisionType = self::DECISION_TYPE_APPROVAL;
        if ($isRenewal === true) {
            $decisionType = self::DECISION_TYPE_RENEWAL;
        }

        $payload = [
            'decisionType'       => $decisionType,
            'sourceApp'          => 'softwarecatalog',
            'subjectRegister'    => self::SUBJECT_REGISTER,
            'subjectSchema'      => self::SUBJECT_SCHEMA,
            'subjectId'          => $contractUuid,
            'subjectLabel'       => $this->buildSubjectLabel(data: $data),
            'externalReference'  => (string) ($data['contractNummer'] ?? ''),
            'outcomeCallbackUrl' => $this->buildCallbackUrl(contractUuid: $contractUuid),
        ];

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $endpoint,
                [
                    'json'    => $payload,
                    'timeout' => 30,
                ]
            );
            $body     = json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            // Fail closed — a transport/decidesk error never advances the contract.
            $this->logger->error(
                'ContractApprovalService: raising decidesk decision failed — contract left in negotiation',
                ['contractUuid' => $contractUuid, 'error' => $e->getMessage()]
            );
            throw new \RuntimeException('Failed to raise the approval decision in decidesk.', 0, $e);
        }//end try

        $decisionId = '';
        if (is_array($body) === true) {
            $decisionId = (string) ($body['decisionId'] ?? ($body['id'] ?? ''));
        }

        if ($decisionId === '') {
            throw new \RuntimeException('decidesk did not return a decision id; contract left in negotiation.');
        }

        $data['approvalDecisionId'] = $decisionId;
        $data['approvalState']      = self::APPROVAL_PENDING;
        // NOTE: status is intentionally NOT touched here — it stays In onderhandeling.
        $this->persistContract(contract: $contract, data: $data);

        $this->subscribeToOutcome(endpoint: $endpoint, decisionId: $decisionId, contractUuid: $contractUuid);

        $this->logger->info(
            'ContractApprovalService: contract submitted for approval',
            ['contractUuid' => $contractUuid, 'decisionId' => $decisionId, 'decisionType' => $decisionType]
        );

        return $decisionId;

    }//end submitForApproval()

    /**
     * Subscribe to the decidesk outcome push for a decision (best-effort).
     *
     * A failure here is non-fatal: the daily reconcile poll
     * (reconcilePendingApprovals) covers any missed push.
     *
     * @param string $endpoint     The decidesk decision-hub base endpoint.
     * @param string $decisionId   The decision id to subscribe to.
     * @param string $contractUuid The contract uuid (for the callback).
     *
     * @return void
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function subscribeToOutcome(string $endpoint, string $decisionId, string $contractUuid): void
    {
        $subscribeUrl = rtrim($endpoint, '/').'/'.rawurlencode($decisionId).'/subscriptions';
        try {
            $client = $this->clientService->newClient();
            $client->post(
                $subscribeUrl,
                [
                    'json'    => ['outcomeCallbackUrl' => $this->buildCallbackUrl(contractUuid: $contractUuid)],
                    'timeout' => 30,
                ]
            );
        } catch (\Throwable $e) {
            // Non-fatal — the reconcile poll is the safety net for missed pushes.
            $this->logger->info(
                'ContractApprovalService: outcome subscription failed; relying on reconcile poll',
                ['decisionId' => $decisionId, 'error' => $e->getMessage()]
            );
        }//end try

    }//end subscribeToOutcome()

    /**
     * IDOR guard: whether the given decision id is the one this contract carries.
     *
     * Used by the outcome callback so a caller cannot project an arbitrary
     * outcome onto an arbitrary contract. A blank stored or supplied id never
     * matches (fail-closed).
     *
     * @param string $contractUuid The contract uuid.
     * @param string $decisionId   The decision id supplied by the caller.
     *
     * @return bool True when the decision id matches the contract's stored id.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    public function isDecisionForContract(string $contractUuid, string $decisionId): bool
    {
        if ($decisionId === '') {
            return false;
        }

        $contract = $this->loadContract(contractUuid: $contractUuid);
        if ($contract === null) {
            return false;
        }

        $stored = (string) ($contract->getObject()['approvalDecisionId'] ?? '');
        return $stored !== '' && hash_equals($stored, $decisionId);

    }//end isDecisionForContract()

    /**
     * Project a decidesk outcome status onto a contract, idempotently.
     *
     * `approved` -> approvalState=approved AND status=Actief (the ONLY path
     * that activates a contract). `rejected`/`withdrawn` ->
     * approvalState=rejected, status stays In onderhandeling. Any other
     * (`pending`) status is a no-op. Re-applying the same terminal outcome is
     * a no-op (idempotent).
     *
     * @param string $contractUuid  The contract uuid.
     * @param string $outcomeStatus The decidesk-derived status (approved|rejected|withdrawn|pending).
     *
     * @return bool True when the contract was changed.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    public function projectOutcome(string $contractUuid, string $outcomeStatus): bool
    {
        $contract = $this->loadContract(contractUuid: $contractUuid);
        if ($contract === null) {
            $this->logger->warning(
                'ContractApprovalService: outcome for unknown contract ignored',
                ['contractUuid' => $contractUuid]
            );
            return false;
        }

        $data    = $contract->getObject();
        $changed = false;

        switch ($outcomeStatus) {
            case 'approved':
                if (($data['approvalState'] ?? self::APPROVAL_NONE) !== self::APPROVAL_APPROVED) {
                    $data['approvalState'] = self::APPROVAL_APPROVED;
                    $changed = true;
                }

                // The In onderhandeling -> Actief transition happens ONLY here,
                // as a projection of an approved decidesk outcome.
                if (($data['status'] ?? '') !== self::STATUS_ACTIVE) {
                    $data['status'] = self::STATUS_ACTIVE;
                    $changed        = true;
                }
                break;

            case 'rejected':
            case 'withdrawn':
                if (($data['approvalState'] ?? self::APPROVAL_NONE) !== self::APPROVAL_REJECTED) {
                    $data['approvalState'] = self::APPROVAL_REJECTED;
                    $changed = true;
                }

                // Status stays In onderhandeling — never forced here.
                break;

            default:
                // Pending / unknown — no projection.
                return false;
        }//end switch

        if ($changed === false) {
            // Idempotent re-receipt — nothing to write.
            return false;
        }

        $this->persistContract(contract: $contract, data: $data);
        $this->logger->info(
            'ContractApprovalService: outcome projected onto contract',
            ['contractUuid' => $contractUuid, 'outcomeStatus' => $outcomeStatus]
        );
        return true;

    }//end projectOutcome()

    /**
     * Poll decidesk for the outcome of a single contract's decision and project it.
     *
     * @param string $contractUuid The contract uuid.
     *
     * @return string|null The derived outcome status, or null when unresolved.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    public function refreshOutcome(string $contractUuid): ?string
    {
        $endpoint = $this->resolveDecisionEndpoint();
        if ($endpoint === null) {
            return null;
        }

        $contract = $this->loadContract(contractUuid: $contractUuid);
        if ($contract === null) {
            return null;
        }

        $data       = $contract->getObject();
        $decisionId = (string) ($data['approvalDecisionId'] ?? '');
        if ($decisionId === '') {
            return null;
        }

        $status = $this->pollOutcomeStatus(endpoint: $endpoint, decisionId: $decisionId);
        if ($status !== null) {
            $this->projectOutcome(contractUuid: $contractUuid, outcomeStatus: $status);
        }

        return $status;

    }//end refreshOutcome()

    /**
     * Daily reconcile: poll decidesk for every contract still `pending` and
     * project any terminal outcome (covers missed pushes). Idempotent.
     *
     * @return int The number of contracts whose outcome was projected.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    public function reconcilePendingApprovals(): int
    {
        $endpoint = $this->resolveDecisionEndpoint();
        if ($endpoint === null) {
            $this->logger->info('ContractApprovalService: delegation not configured, skipping reconcile');
            return 0;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return 0;
        }

        $registerId = $this->settingsService->getRegisterIdForObjectType(objectType: 'contract');
        $schemaId   = $this->settingsService->getSchemaIdForObjectType(objectType: 'contract');
        if ($registerId === null || $schemaId === null) {
            $this->logger->info('ContractApprovalService: contract register/schema not configured, skipping reconcile');
            return 0;
        }

        $query = [
            'register'      => $registerId,
            'schema'        => $schemaId,
            'approvalState' => self::APPROVAL_PENDING,
        ];

        try {
            $contracts = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ContractApprovalService: failed to query pending contracts',
                ['error' => $e->getMessage()]
            );
            return 0;
        }//end try

        $projected = 0;
        foreach ($contracts as $contract) {
            $data       = $contract->getObject();
            $decisionId = (string) ($data['approvalDecisionId'] ?? '');
            if ($decisionId === '') {
                continue;
            }

            $status = $this->pollOutcomeStatus(endpoint: $endpoint, decisionId: $decisionId);
            if ($status === null) {
                continue;
            }

            if ($this->projectOutcome(contractUuid: $contract->getUuid(), outcomeStatus: $status) === true) {
                $projected++;
            }
        }//end foreach

        return $projected;

    }//end reconcilePendingApprovals()

    /**
     * Poll the decidesk outcome envelope and return its derived status.
     *
     * @param string $endpoint   The decidesk decision-hub base endpoint.
     * @param string $decisionId The decision id.
     *
     * @return string|null The derived status (approved|rejected|withdrawn|pending), or null on error.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function pollOutcomeStatus(string $endpoint, string $decisionId): ?string
    {
        $outcomeUrl = rtrim($endpoint, '/').'/'.rawurlencode($decisionId).'/outcome';
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get($outcomeUrl, ['timeout' => 30]);
            $body     = json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            $this->logger->info(
                'ContractApprovalService: outcome poll failed',
                ['decisionId' => $decisionId, 'error' => $e->getMessage()]
            );
            return null;
        }//end try

        if (is_array($body) === false || isset($body['status']) === false) {
            return null;
        }

        return (string) $body['status'];

    }//end pollOutcomeStatus()

    /**
     * Build the human-readable subject label for the decision provenance.
     *
     * @param array $data The contract object data.
     *
     * @return string The subject label.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function buildSubjectLabel(array $data): string
    {
        $number = (string) ($data['contractNummer'] ?? '');
        $type   = (string) ($data['contractType'] ?? '');
        if ($number !== '' && $type !== '') {
            return $number.' — '.$type;
        }

        if ($number !== '') {
            return $number;
        }

        return 'Contract';

    }//end buildSubjectLabel()

    /**
     * Build the absolute SC outcome-callback URL for a contract (instance-local).
     *
     * @param string $contractUuid The contract uuid.
     *
     * @return string The absolute callback URL.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function buildCallbackUrl(string $contractUuid): string
    {
        $path = $this->urlGenerator->linkToRoute(
            routeName: 'softwarecatalog.contractApproval.outcomeCallback',
            arguments: ['contractUuid' => $contractUuid]
        );
        return $this->urlGenerator->getAbsoluteURL(url: $path);

    }//end buildCallbackUrl()

    /**
     * Load a contract OR object by uuid.
     *
     * @param string $contractUuid The contract uuid.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null The object, or null.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function loadContract(string $contractUuid)
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $registerId = $this->settingsService->getRegisterIdForObjectType(objectType: 'contract');
        $schemaId   = $this->settingsService->getSchemaIdForObjectType(objectType: 'contract');
        if ($registerId === null || $schemaId === null) {
            return null;
        }

        try {
            return $objectService->find(
                id: $contractUuid,
                _extend: [],
                files: false,
                register: $registerId,
                schema: $schemaId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'ContractApprovalService: contract lookup failed',
                ['contractUuid' => $contractUuid, 'error' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end loadContract()

    /**
     * Persist a mutated contract object back to the OR store.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $contract The contract entity.
     * @param array                             $data     The mutated object data.
     *
     * @return void
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function persistContract($contract, array $data): void
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        $objectService->saveObject(
            object: $data,
            extend: [],
            register: $contract->getRegister(),
            schema: $contract->getSchema(),
            uuid: $contract->getUuid()
        );

    }//end persistContract()

    /**
     * Lazily resolve the OpenRegister ObjectService.
     *
     * @return ObjectService|null The service, or null when OpenRegister is absent.
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    private function getObjectService(): ?ObjectService
    {
        try {
            $service = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            if ($service instanceof ObjectService) {
                return $service;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('ContractApprovalService: ObjectService not resolvable', ['error' => $e->getMessage()]);
        }//end try

        return null;

    }//end getObjectService()
}//end class
