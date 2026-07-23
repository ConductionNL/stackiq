<?php
/**
 * Unit tests for MergeOrganisatieService (organisation-merge).
 *
 * Covers dry-run/execute parity, PUT-semantics field preservation (scalar +
 * array relation fields, and the `@self.organisation` system-level field),
 * idempotent/resumable execute, tombstone-only-after-completion, self-merge
 * and already-tombstoned validation, and NC group membership migration.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/organisation-merge/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\SoftwareCatalog\Service\MergeOrganisatieService;
use OCA\SoftwareCatalog\Service\OrganisatieService;
use OCA\SoftwareCatalog\Service\ProgressTracker;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for MergeOrganisatieService.
 */
class MergeOrganisatieServiceTest extends TestCase
{
    private const REGISTER_ID = 100;

    /**
     * Object-type => schema id map used by the fixture SettingsService.
     *
     * @var array<string, int>
     */
    private const SCHEMA_IDS = [
        'organisatie'    => 1,
        'gebruik'        => 2,
        'contract'       => 3,
        'contactpersoon' => 4,
        'koppeling'      => 5,
        'compliancy'     => 6,
    ];

    /**
     * Captured saveObject() calls: [{object, register, schema, uuid}].
     *
     * @var array<int, array{object: array, register: mixed, schema: mixed, uuid: mixed}>
     */
    private array $savedCalls = [];

    /**
     * Reset captured saves between tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->savedCalls = [];
    }//end setUp()

    /**
     * Dry-run reports per-relation-type counts without writing anything.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
     */
    public function testDryRunReportsCountsWithoutWriting(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief', 'group' => 'group-a']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief', 'group' => 'group-b']),
            ],
            typedFixtures: $this->fullFixtureSet(),
            groupMembers: ['group-a' => ['alice'], 'group-b' => ['carol']]
        );

        $result = $service->dryRun(sourceUuid: 'org-a', targetUuid: 'org-b');

        $this->assertSame([], $result['blockers']);
        $this->assertSame(
            [
                'groupMembers'   => 1,
                'gebruik'        => 2,
                'contactpersoon' => 1,
                'aanbod'         => 1,
                'contract'       => 1,
                'compliancy'     => 1,
            ],
            $result['counts']
        );
        $this->assertSame([], $this->savedCalls, 'dry-run MUST NOT write any object');
    }//end testDryRunReportsCountsWithoutWriting()

    /**
     * Dry-run on an organisation with no relations reports all zeros and no blockers.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
     */
    public function testDryRunWithNoRelationsReportsAllZeros(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief']),
            ],
            typedFixtures: [],
            groupMembers: []
        );

        $result = $service->dryRun(sourceUuid: 'org-a', targetUuid: 'org-b');

        $this->assertSame([], $result['blockers']);
        foreach ($result['counts'] as $count) {
            $this->assertSame(0, $count);
        }
    }//end testDryRunWithNoRelationsReportsAllZeros()

    /**
     * Execute re-points exactly what dry-run counted for the same unchanged input (parity).
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-dry-run-and-execute-must-report-structurally-identical-counts-for-the-same-unchanged-input
     */
    public function testExecuteRepointsExactlyWhatDryRunCounted(): void
    {
        $organisations = [
            'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief', 'group' => 'group-a']),
            'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief', 'group' => 'group-b']),
        ];

        $dryRunService = $this->makeService(
            organisations: $organisations,
            typedFixtures: $this->fullFixtureSet(),
            groupMembers: ['group-a' => ['alice'], 'group-b' => ['carol']]
        );
        $dryRunResult  = $dryRunService->dryRun(sourceUuid: 'org-a', targetUuid: 'org-b');

        $this->savedCalls = [];
        $executeService   = $this->makeService(
            organisations: $organisations,
            typedFixtures: $this->fullFixtureSet(),
            groupMembers: ['group-a' => ['alice'], 'group-b' => ['carol']]
        );
        $executeResult    = $executeService->execute(sourceUuid: 'org-a', targetUuid: 'org-b', actorUid: 'admin1');

        $this->assertTrue($executeResult['ok']);
        $this->assertSame($dryRunResult['counts'], $executeResult['counts']);

        // 2 gebruik + 1 contract + 1 contactpersoon + 1 koppeling + 1 compliancy + 1 tombstone = 7 saves.
        $this->assertCount(7, $this->savedCalls);
    }//end testExecuteRepointsExactlyWhatDryRunCounted()

    /**
     * An untouched field survives re-pointing (PUT-semantics) — contract via `@self.organisation`.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
     */
    public function testUntouchedContractFieldsSurviveRepointing(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief']),
            ],
            typedFixtures: [
                'contract' => [
                    $this->entity(
                        ['id' => 'c1', 'contractNummer' => 'C-100', 'kosten' => 5000, 'documentReferentie' => 'doc-ref'],
                        uuid: 'c1',
                        organisation: 'org-a'
                    ),
                ],
            ],
            groupMembers: []
        );

        $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

        $contractSave = $this->findSave(schemaId: self::SCHEMA_IDS['contract'], uuid: 'c1');
        $this->assertNotNull($contractSave);
        $this->assertSame('org-b', $contractSave['object']['@self']['organisation']);
        $this->assertSame('C-100', $contractSave['object']['contractNummer']);
        $this->assertSame(5000, $contractSave['object']['kosten']);
        $this->assertSame('doc-ref', $contractSave['object']['documentReferentie']);
    }//end testUntouchedContractFieldsSurviveRepointing()

    /**
     * A gebruik object with the source as one of several deelnemers only
     * replaces the matching entry.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
     */
    public function testGebruikDeelnemersOnlyReplacesMatchingEntry(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief']),
            ],
            typedFixtures: [
                'gebruik' => [
                    $this->entity(
                        ['id' => 'g1', 'afnemer' => 'org-x', 'deelnemers' => ['org-a', 'org-c', 'org-d']],
                        uuid: 'g1'
                    ),
                ],
            ],
            groupMembers: []
        );

        $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

        $save = $this->findSave(schemaId: self::SCHEMA_IDS['gebruik'], uuid: 'g1');
        $this->assertNotNull($save);
        $this->assertSame(['org-b', 'org-c', 'org-d'], $save['object']['deelnemers']);
        $this->assertSame('org-x', $save['object']['afnemer'], 'afnemer was already not the source — must stay untouched');
    }//end testGebruikDeelnemersOnlyReplacesMatchingEntry()

    /**
     * Re-running execute after gebruik/contract already completed does not
     * re-point them a second time, and processes the remaining types.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-be-idempotent-and-resumable-per-relation-type
     */
    public function testReRunningExecuteAfterPartialCompletionOnlyFinishesRemainingTypes(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief']),
            ],
            typedFixtures: [
                // gebruik/contract already point at the target — nothing left to do.
                'gebruik'        => [$this->entity(['id' => 'g1', 'afnemer' => 'org-b'], uuid: 'g1')],
                'contract'       => [$this->entity(['id' => 'c1'], uuid: 'c1', organisation: 'org-b')],
                // contactpersoon/koppeling/compliancy still reference the source.
                'contactpersoon' => [$this->entity(['id' => 'p1', 'organisatie' => 'org-a'], uuid: 'p1')],
                'koppeling'      => [$this->entity(['id' => 'k1', 'aanbieder' => 'org-a'], uuid: 'k1')],
                'compliancy'     => [$this->entity(['id' => 'cp1'], uuid: 'cp1', organisation: 'org-a')],
            ],
            groupMembers: []
        );

        $result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

        $this->assertSame(0, $result['counts']['gebruik']);
        $this->assertSame(0, $result['counts']['contract']);
        $this->assertSame(1, $result['counts']['contactpersoon']);
        $this->assertSame(1, $result['counts']['aanbod']);
        $this->assertSame(1, $result['counts']['compliancy']);

        $this->assertNull($this->findSave(schemaId: self::SCHEMA_IDS['gebruik'], uuid: 'g1'));
        $this->assertNull($this->findSave(schemaId: self::SCHEMA_IDS['contract'], uuid: 'c1'));
        $this->assertNotNull($this->findSave(schemaId: self::SCHEMA_IDS['contactpersoon'], uuid: 'p1'));
    }//end testReRunningExecuteAfterPartialCompletionOnlyFinishesRemainingTypes()

    /**
     * Re-running a fully completed merge (source already tombstoned into the
     * SAME target) is a safe no-op: no relation object is modified and the
     * response reports `already_completed`, not an error.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-be-idempotent-and-resumable-per-relation-type
     */
    public function testReRunningAFullyCompletedMergeIsASafeNoOp(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'samengevoegd', 'mergedInto' => 'org-b']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief']),
            ],
            typedFixtures: [
                // Everything already re-pointed to the target.
                'gebruik'    => [$this->entity(['id' => 'g1', 'afnemer' => 'org-b'], uuid: 'g1')],
                'contract'   => [$this->entity(['id' => 'c1'], uuid: 'c1', organisation: 'org-b')],
            ],
            groupMembers: []
        );

        $result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

        $this->assertTrue($result['ok']);
        $this->assertSame('already_completed', $result['status']);
        $this->assertNull($this->findSave(schemaId: self::SCHEMA_IDS['gebruik'], uuid: 'g1'));
        $this->assertNull($this->findSave(schemaId: self::SCHEMA_IDS['contract'], uuid: 'c1'));
    }//end testReRunningAFullyCompletedMergeIsASafeNoOp()

    /**
     * The source organisation is tombstoned (status + mergedInto) only once
     * execute completes, is never deleted, and every other pre-existing
     * field on it is unchanged (PUT-semantics on the organisatie object itself).
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted
     */
    public function testSourceOrganisationIsTombstonedAfterSuccessfulMerge(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief', 'naam' => 'Gemeente A', 'type' => 'Gemeente']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief']),
            ],
            typedFixtures: [],
            groupMembers: []
        );

        $result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

        $this->assertSame('completed', $result['status']);

        $tombstoneSave = $this->findSave(schemaId: self::SCHEMA_IDS['organisatie'], uuid: 'org-a');
        $this->assertNotNull($tombstoneSave);
        $this->assertSame('samengevoegd', $tombstoneSave['object']['status']);
        $this->assertSame('org-b', $tombstoneSave['object']['mergedInto']);
        // Pre-existing fields survive (PUT-semantic full re-save).
        $this->assertSame('Gemeente A', $tombstoneSave['object']['naam']);
        $this->assertSame('Gemeente', $tombstoneSave['object']['type']);
    }//end testSourceOrganisationIsTombstonedAfterSuccessfulMerge()

    /**
     * NC group membership is migrated from source to target; pre-existing
     * target membership does not error.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-nc-group-membership-must-be-migrated-from-source-to-target
     */
    public function testGroupMembershipIsMigratedFromSourceToTarget(): void
    {
        $addedUsers = [];

        $sourceGroup = $this->createMock(IGroup::class);
        $sourceGroup->method('getUsers')->willReturn([$this->user('alice'), $this->user('bob')]);

        $targetGroup = $this->createMock(IGroup::class);
        $targetGroup->method('inGroup')->willReturnCallback(
            static fn (IUser $user) => $user->getUID() === 'carol'
        );
        $targetGroup->method('addUser')->willReturnCallback(
            function (IUser $user) use (&$addedUsers) {
                $addedUsers[] = $user->getUID();
            }
        );

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->willReturnCallback(
            static function (string $gid) use ($sourceGroup, $targetGroup) {
                return match ($gid) {
                    'group-a' => $sourceGroup,
                    'group-b' => $targetGroup,
                    default => null,
                };
            }
        );

        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief', 'group' => 'group-a']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Actief', 'group' => 'group-b']),
            ],
            typedFixtures: [],
            groupMembers: [],
            groupManagerOverride: $groupManager
        );

        $result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

        $this->assertSame(2, $result['counts']['groupMembers']);
        $this->assertSame(['alice', 'bob'], $addedUsers, 'both source members are added — carol overlap causes no error');
    }//end testGroupMembershipIsMigratedFromSourceToTarget()

    /**
     * Self-merge is rejected with no writes.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
     */
    public function testSelfMergeIsRejected(): void
    {
        $service = $this->makeService(
            organisations: ['org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief'])],
            typedFixtures: [],
            groupMembers: []
        );

        $result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-a');

        $this->assertFalse($result['ok']);
        $this->assertSame('self-merge', $result['blockers'][0]['type']);
        $this->assertSame([], $this->savedCalls);
    }//end testSelfMergeIsRejected()

    /**
     * Merging into an already-tombstoned target is rejected.
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
     */
    public function testMergingIntoAnAlreadyTombstonedTargetIsRejected(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Actief']),
                'org-b' => $this->entity(['id' => 'org-b', 'status' => 'samengevoegd', 'mergedInto' => 'org-z']),
            ],
            typedFixtures: [],
            groupMembers: []
        );

        $result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

        $this->assertFalse($result['ok']);
        $this->assertSame('target-already-merged', $result['blockers'][0]['type']);
        $this->assertSame([], $this->savedCalls);
    }//end testMergingIntoAnAlreadyTombstonedTargetIsRejected()

    /**
     * Re-merging an already-tombstoned source into a DIFFERENT target is
     * rejected as a validation error (not a silent success); the source's
     * existing `mergedInto` is left untouched (no write occurs at all).
     *
     * @return void
     *
     * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
     */
    public function testReMergingAnAlreadyTombstonedSourceIntoADifferentTargetIsRejected(): void
    {
        $service = $this->makeService(
            organisations: [
                'org-a' => $this->entity(['id' => 'org-a', 'status' => 'samengevoegd', 'mergedInto' => 'org-b']),
                'org-c' => $this->entity(['id' => 'org-c', 'status' => 'Actief']),
            ],
            typedFixtures: [],
            groupMembers: []
        );

        $result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-c');

        $this->assertFalse($result['ok']);
        $this->assertSame('source-already-merged', $result['blockers'][0]['type']);
        $this->assertSame([], $this->savedCalls);
    }//end testReMergingAnAlreadyTombstonedSourceIntoADifferentTargetIsRejected()

    /**
     * Build the full 5-type + group-member fixture set used by the parity tests.
     *
     * @return array<string, array<int, ObjectEntity>>
     */
    private function fullFixtureSet(): array
    {
        return [
            'gebruik'        => [
                $this->entity(['id' => 'g1', 'afnemer' => 'org-a', 'deelnemers' => ['org-c']], uuid: 'g1'),
                $this->entity(['id' => 'g2', 'afnemer' => 'org-x', 'deelnemers' => ['org-a', 'org-c', 'org-d']], uuid: 'g2'),
                $this->entity(['id' => 'g3', 'afnemer' => 'org-y', 'deelnemers' => ['org-z']], uuid: 'g3'),
            ],
            'contract'       => [
                $this->entity(['id' => 'c1', 'contractNummer' => 'C-100'], uuid: 'c1', organisation: 'org-a'),
                $this->entity(['id' => 'c2'], uuid: 'c2', organisation: 'org-b'),
            ],
            'contactpersoon' => [
                $this->entity(['id' => 'p1', 'organisatie' => 'org-a'], uuid: 'p1'),
                $this->entity(['id' => 'p2', 'organisatie' => 'org-b'], uuid: 'p2'),
            ],
            'koppeling'      => [
                $this->entity(['id' => 'k1', 'aanbieder' => 'org-a'], uuid: 'k1'),
                $this->entity(['id' => 'k2', 'aanbieder' => 'org-b'], uuid: 'k2'),
            ],
            'compliancy'     => [
                $this->entity(['id' => 'cp1'], uuid: 'cp1', organisation: 'org-a'),
            ],
        ];
    }//end fullFixtureSet()

    /**
     * Find a captured saveObject() call for a given schema id + uuid.
     *
     * @param int    $schemaId The schema id.
     * @param string $uuid     The object uuid.
     *
     * @return array{object: array, register: mixed, schema: mixed, uuid: mixed}|null
     */
    private function findSave(int $schemaId, string $uuid): ?array
    {
        foreach ($this->savedCalls as $call) {
            if ((int) $call['schema'] === $schemaId && $call['uuid'] === $uuid) {
                return $call;
            }
        }

        return null;
    }//end findSave()

    /**
     * Build a fully-wired MergeOrganisatieService with fixture-backed collaborators.
     *
     * @param array<string, ObjectEntity>            $organisations        Organisatie fixtures keyed by uuid (find()).
     * @param array<string, array<int, ObjectEntity>> $typedFixtures        Non-organisatie fixtures keyed by object type (findAll()).
     * @param array<string, array<int, string>>       $groupMembers         Group id => member usernames (used unless groupManagerOverride is given).
     * @param IGroupManager|null                       $groupManagerOverride Explicit IGroupManager mock (overrides $groupMembers).
     *
     * @return MergeOrganisatieService
     */
    private function makeService(
        array $organisations,
        array $typedFixtures,
        array $groupMembers,
        ?IGroupManager $groupManagerOverride=null
    ): MergeOrganisatieService {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string|int $id) use ($organisations) {
                return $organisations[$id] ?? null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($typedFixtures) {
                $schemaId = (int) ($config['_schema'] ?? 0);
                foreach (self::SCHEMA_IDS as $type => $id) {
                    if ($id === $schemaId && isset($typedFixtures[$type]) === true) {
                        return $typedFixtures[$type];
                    }
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (array|ObjectEntity $object, $register=null, $schema=null, $uuid=null) {
                $data = ($object instanceof ObjectEntity) === true ? $object->getObject() : $object;
                $this->savedCalls[] = [
                    'object'   => $data,
                    'register' => $register,
                    'schema'   => $schema,
                    'uuid'     => $uuid,
                ];
                return $this->createStub(ObjectEntity::class);
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($objectService) {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $objectService;
                }

                throw new \RuntimeException('not bound: '.$id);
            }
        );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['openregister']);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getVoorzieningenRegisterId')->willReturn(self::REGISTER_ID);
        $settingsService->method('getSchemaIdForObjectType')->willReturnCallback(
            static function (string $objectType) {
                return self::SCHEMA_IDS[$objectType] ?? null;
            }
        );

        $groupManager = $groupManagerOverride;
        if ($groupManager === null) {
            $groupManager = $this->createMock(IGroupManager::class);
            $groupManager->method('get')->willReturnCallback(
                function (string $gid) use ($groupMembers) {
                    if (isset($groupMembers[$gid]) === false) {
                        return null;
                    }

                    $group = $this->createMock(IGroup::class);
                    $members = array_map(fn (string $uid) => $this->user($uid), $groupMembers[$gid]);
                    $group->method('getUsers')->willReturn($members);
                    $group->method('inGroup')->willReturn(false);

                    return $group;
                }
            );
        }

        $organisatieService  = $this->createMock(OrganisatieService::class);
        $progressTracker     = $this->createMock(ProgressTracker::class);
        $progressTracker->method('startOperation')->willReturn('op-1');
        $organizationHandler = $this->createMock(OrganizationHandler::class);

        return new MergeOrganisatieService(
            container: $container,
            appManager: $appManager,
            groupManager: $groupManager,
            logger: $this->createMock(LoggerInterface::class),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            settingsService: $settingsService,
            organisatieService: $organisatieService,
            progressTracker: $progressTracker,
            organizationHandler: $organizationHandler
        );
    }//end makeService()

    /**
     * Build an ObjectEntity mock returning $data / $uuid / $organisation.
     *
     * @param array<string, mixed> $data         The object payload (getObject()).
     * @param string|null          $uuid         The uuid (defaults to $data['id']).
     * @param string|null          $organisation The system-level `@self.organisation` owner.
     *
     * @return ObjectEntity
     */
    private function entity(array $data, ?string $uuid=null, ?string $organisation=null): ObjectEntity
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn($data);
        $entity->method('getUuid')->willReturn($uuid ?? (string) ($data['id'] ?? ''));
        $entity->method('getOrganisation')->willReturn($organisation);

        return $entity;
    }//end entity()

    /**
     * Build an IUser mock with the given uid.
     *
     * @param string $uid The user id.
     *
     * @return IUser
     */
    private function user(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }//end user()
}//end class
