<?php

/**
 * Unit tests for the anonymous-registration intake + moderation lifecycle.
 *
 * Covers the full pending → approved/rejected flow: an anonymous submission
 * lands as `registratiestatus=pending` WITHOUT a `publicatiedatum` (so the
 * public RBAC gate keeps it invisible); approval sets `active` + a
 * `publicatiedatum` (anonymously visible); rejection sets `rejected` and stays
 * unpublished. Also covers anti-spam validation, privileged-key stripping,
 * duplicate-pending refusal, and the not-pending / peer-sourced guards.
 *
 * Also covers (catalog-ratings, softwarecatalog#375) that `ModerationService`
 * generalised to a second type (`beoordeeling`) without changing ANY of the
 * above default-organisatie assertions — every test above calls
 * `approve()`/`reject()`/`listPending()` with no `$type` argument, so they
 * keep exercising the exact pre-existing organisatie/registratiestatus path.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\SoftwareCatalog\Service\IntakeService;
use OCA\SoftwareCatalog\Service\ModerationService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for the intake → moderation lifecycle.
 */
class IntakeModerationTest extends TestCase {
	/**
	 * Captured object data handed to saveObject() across the test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Set up the saved-capture between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
	}//end setUp()

	/**
	 * An anonymous submission lands pending + UNPUBLISHED (no publicatiedatum).
	 *
	 * @return void
	 */
	public function testAnonymousSubmissionLandsPendingAndUnpublished(): void {
		$objectService = $this->objectService([]); // no existing pending duplicate
		$intake = new IntakeService($this->container($objectService), $this->settings(), $this->logger());

		$result = $intake->submit(['name' => 'Gemeente Test', 'description' => 'hi']);

		$this->assertTrue($result['ok']);
		$this->assertSame('pending', $result['status']);
		$this->assertCount(1, $this->saved);

		$stored = $this->saved[0];
		$this->assertSame('pending', $stored['registrationStatus']);
		$this->assertNull($stored['publicationDate']); // invisible to the public RBAC gate
	}//end testAnonymousSubmissionLandsPendingAndUnpublished()

	/**
	 * A self-service submitter cannot set its own status / publication / id /
	 * provenance — those privileged keys are stripped server-side.
	 *
	 * @return void
	 */
	public function testPrivilegedKeysAreStripped(): void {
		$objectService = $this->objectService([]);
		$intake = new IntakeService($this->container($objectService), $this->settings(), $this->logger());

		$intake->submit([
			'name' => 'Sneaky BV',
			'registrationStatus' => 'active',
			'publicationDate' => '2020-01-01T00:00:00+00:00',
			'id' => 'forced-id',
			'_source' => ['instance' => 'https://evil.example'],
			'beoordeling' => 'actief',
		]);

		$stored = $this->saved[0];
		$this->assertSame('pending', $stored['registrationStatus']); // forced server-side
		$this->assertNull($stored['publicationDate']);
		$this->assertArrayNotHasKey('_source', $stored);
		$this->assertArrayNotHasKey('beoordeling', $stored);
	}//end testPrivilegedKeysAreStripped()

	/**
	 * Anti-spam validation: missing required field is rejected.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldRejected(): void {
		$intake = new IntakeService($this->container($this->objectService([])), $this->settings(), $this->logger());
		$result = $intake->submit(['description' => 'no name']);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('name', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testMissingRequiredFieldRejected()

	/**
	 * `entityUuid()` must read the uuid off a saved entity whose `getUuid()`
	 * is reached through `Entity::__call()` — which is what every real
	 * OpenRegister `ObjectEntity` returned by `saveObject()` does.
	 *
	 * With the old `method_exists()` probe this returned `null` for EVERY real
	 * save (the `is_array()` arm cannot rescue an object), so `submit()`
	 * answered `uuid: null` to the client and wrote `['uuid' => null]` to the
	 * audit log — softwarecatalog#490. See the twin test in ReviewServiceTest;
	 * the two services carry byte-identical copies of this helper.
	 *
	 * @return void
	 */
	public function testEntityUuidReadsAMagicAccessorUuid(): void {
		$entity = new class extends Entity {

			/**
			 * The uuid — a property reached via __call, as on ObjectEntity.
			 *
			 * @var string|null
			 */
			protected ?string $uuid = null;
		};
		$entity->setUuid('intake-uuid-1');

		$this->assertFalse(
			method_exists($entity, 'getUuid'),
			'the double must reach getUuid() through __call, like the real ObjectEntity'
		);

		$intake = new IntakeService($this->container($this->objectService([])), $this->settings(), $this->logger());

		$method = new \ReflectionMethod($intake, 'entityUuid');
		$method->setAccessible(true);

		$this->assertSame('intake-uuid-1', $method->invoke($intake, $entity));
	}//end testEntityUuidReadsAMagicAccessorUuid()

	/**
	 * Anti-spam validation: oversized value is rejected.
	 *
	 * @return void
	 */
	public function testOversizedValueRejected(): void {
		$intake = new IntakeService($this->container($this->objectService([])), $this->settings(), $this->logger());
		$result = $intake->submit(['name' => str_repeat('x', IntakeService::MAX_FIELD_LENGTH + 1)]);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('maximum length', $result['reason']);
	}//end testOversizedValueRejected()

	/**
	 * Duplicate pending submissions for the same org name are refused.
	 *
	 * @return void
	 */
	public function testDuplicatePendingRefused(): void {
		$existing = $this->entity(['id' => 'p-1', 'name' => 'Dup BV', 'registrationStatus' => 'pending']);
		$objectService = $this->objectService([$existing]); // duplicate exists
		$intake = new IntakeService($this->container($objectService), $this->settings(), $this->logger());

		$result = $intake->submit(['name' => 'Dup BV']);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('already exists', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testDuplicatePendingRefused()

	/**
	 * Approval sets active + publicatiedatum (anonymously visible via RBAC).
	 *
	 * @return void
	 */
	public function testApprovalActivatesAndPublishes(): void {
		$pending = $this->entity(['id' => 'uuid-1', 'name' => 'Approve BV', 'registrationStatus' => 'pending']);
		$objectService = $this->objectServiceWithFind($pending);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->approve('uuid-1');

		$this->assertTrue($result['ok']);
		$this->assertSame('active', $result['status']);
		$stored = $this->saved[0];
		$this->assertSame('active', $stored['registrationStatus']);
		$this->assertNotNull($stored['publicationDate']); // now anonymously visible
	}//end testApprovalActivatesAndPublishes()

	/**
	 * Rejection sets rejected and leaves it unpublished (still invisible).
	 *
	 * @return void
	 */
	public function testRejectionStaysUnpublished(): void {
		$pending = $this->entity(['id' => 'uuid-2', 'name' => 'Reject BV', 'registrationStatus' => 'pending']);
		$objectService = $this->objectServiceWithFind($pending);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->reject('uuid-2');

		$this->assertTrue($result['ok']);
		$this->assertSame('rejected', $result['status']);
		$stored = $this->saved[0];
		$this->assertSame('rejected', $stored['registrationStatus']);
		$this->assertNull($stored['publicationDate']);
	}//end testRejectionStaysUnpublished()

	/**
	 * A registration that is not pending cannot be (re-)decided — idempotency
	 * guard preventing re-publishing an already-approved entry.
	 *
	 * @return void
	 */
	public function testNonPendingCannotBeApproved(): void {
		$active = $this->entity(['id' => 'uuid-3', 'name' => 'Already', 'registrationStatus' => 'active']);
		$objectService = $this->objectServiceWithFind($active);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->approve('uuid-3');
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('not pending', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testNonPendingCannotBeApproved()

	/**
	 * A peer-sourced (federated) mirror is never moderated locally.
	 *
	 * @return void
	 */
	public function testPeerSourcedCannotBeModerated(): void {
		$peer = $this->entity([
			'id' => 'uuid-4',
			'name' => 'Peer',
			'registrationStatus' => 'pending',
			'_source' => ['instance' => 'https://peer.example'],
		]);
		$objectService = $this->objectServiceWithFind($peer);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->approve('uuid-4');
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('peer-sourced', $result['reason']);
	}//end testPeerSourcedCannotBeModerated()

	/**
	 * Pending list returns the queued submissions.
	 *
	 * @return void
	 */
	public function testListPending(): void {
		$one = $this->entity(['id' => 'uuid-5', 'name' => 'Q1', 'registrationStatus' => 'pending']);
		$objectService = $this->objectService([$one]);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->listPending();
		$this->assertTrue($result['ok']);
		$this->assertCount(1, $result['items']);
		$this->assertSame('Q1', $result['items'][0]['name']);
	}//end testListPending()

	/**
	 * beoordeeling (catalog-ratings, softwarecatalog#375): approval sets
	 * `status = approved` — no publicatiedatum stamping involved (the
	 * schema's own status-conditioned public RBAC rule does that job).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	public function testBeoordeelingApprovalSetsStatusApproved(): void {
		$pending = $this->entity(['id' => 'rev-1', 'name' => 'Great tool', 'status' => 'pending']);
		$objectService = $this->objectServiceWithFind($pending);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->approve('rev-1', type: ModerationService::MODERATED_TYPE_REVIEW);

		$this->assertTrue($result['ok']);
		$this->assertSame('approved', $result['status']);
		$stored = $this->saved[0];
		$this->assertSame('approved', $stored['status']);
		$this->assertArrayNotHasKey('publicationDate', $stored);
	}//end testBeoordeelingApprovalSetsStatusApproved()

	/**
	 * beoordeeling: rejection sets `status = rejected` and stays hidden.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	public function testBeoordeelingRejectionSetsStatusRejected(): void {
		$pending = $this->entity(['id' => 'rev-2', 'name' => 'Meh tool', 'status' => 'pending']);
		$objectService = $this->objectServiceWithFind($pending);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->reject('rev-2', type: ModerationService::MODERATED_TYPE_REVIEW);

		$this->assertTrue($result['ok']);
		$this->assertSame('rejected', $result['status']);
		$this->assertSame('rejected', $this->saved[0]['status']);
	}//end testBeoordeelingRejectionSetsStatusRejected()

	/**
	 * beoordeeling: pending list uses the `status` field, not `registratiestatus`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
	 */
	public function testBeoordeelingListPending(): void {
		$one = $this->entity(['id' => 'rev-3', 'name' => 'Q1 review', 'status' => 'pending']);
		$objectService = $this->objectService([$one]);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->listPending(type: ModerationService::MODERATED_TYPE_REVIEW);
		$this->assertTrue($result['ok']);
		$this->assertCount(1, $result['items']);
		$this->assertSame('Q1 review', $result['items'][0]['name']);
	}//end testBeoordeelingListPending()

	/**
	 * beoordeeling: a non-pending review cannot be (re-)decided — same
	 * idempotency guard as organisatie, generalised across the status field.
	 *
	 * @return void
	 */
	public function testBeoordeelingNonPendingCannotBeApproved(): void {
		$active = $this->entity(['id' => 'rev-4', 'name' => 'Already approved', 'status' => 'approved']);
		$objectService = $this->objectServiceWithFind($active);
		$moderation = new ModerationService($this->container($objectService), $this->settings(), $this->logger());

		$result = $moderation->approve('rev-4', type: ModerationService::MODERATED_TYPE_REVIEW);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('not pending', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testBeoordeelingNonPendingCannotBeApproved()

	/**
	 * Build an ObjectService mock whose searchObjects returns $found and whose
	 * saveObject captures the data bag.
	 *
	 * @param array<int,mixed> $found The search result.
	 *
	 * @return ObjectService The mock.
	 */
	private function objectService(array $found): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturn($found);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return $this->createStub(ObjectEntity::class);
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * Build an ObjectService mock whose find() returns $entity (and saveObject
	 * captures), for the moderation decide() path.
	 *
	 * @param ObjectEntity $entity The entity find() returns.
	 *
	 * @return ObjectService The mock.
	 */
	private function objectServiceWithFind(ObjectEntity $entity): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return $this->createStub(ObjectEntity::class);
			}
		);
		return $objectService;
	}//end objectServiceWithFind()

	/**
	 * Build an ObjectEntity stub returning the given data bag + uuid.
	 *
	 * @param array<string,mixed> $data The data bag (with 'id').
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn((string)($data['id'] ?? ''));
		return $entity;
	}//end entity()

	/**
	 * Build a container resolving the OR ObjectService.
	 *
	 * @param ObjectService $objectService The OR ObjectService.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(ObjectService $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}
				throw new \RuntimeException('not bound: ' . $id);
			}
		);
		return $container;
	}//end container()

	/**
	 * Build a SettingsService mock resolving the organisatie register/schema.
	 *
	 * @return SettingsService The mock.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterIdForObjectType')->willReturn(1);
		$settings->method('getSchemaIdForObjectType')->willReturn(2);
		return $settings;
	}//end settings()

	/**
	 * Build a logger mock.
	 *
	 * @return LoggerInterface The mock.
	 */
	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}//end logger()
}//end class
