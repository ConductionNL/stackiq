<?php

/**
 * Unit tests for the StackIQ curated MCP read and write tools.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#6.3
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Mcp;

require_once __DIR__.'/McpToolFixture.php';

use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\Kernel\Contracts\AanbodServiceInterface;
use OCA\Stackiq\Kernel\Contracts\AangebodenGebruikServiceInterface;
use OCA\Stackiq\Kernel\Contracts\EolSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\IntakeServiceInterface;
use OCA\Stackiq\Kernel\Contracts\MergeOrganisatieServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ModerationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\OrganisationReportAccessInterface;
use OCA\Stackiq\Kernel\Contracts\PortfolioReportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ReviewAggregateServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ReviewServiceInterface;
use OCA\Stackiq\Kernel\Contracts\SbomImportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ViewServiceInterface;
use OCA\Stackiq\Mcp\McpAdminGate;
use OCA\Stackiq\Mcp\McpArgumentValidator;
use OCA\Stackiq\Mcp\McpPublicationGate;
use OCA\Stackiq\Mcp\McpReadTools;
use OCA\Stackiq\Mcp\McpSbomGate;
use OCA\Stackiq\Mcp\McpWriteTools;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Arguments are checked first, then the caller, then the service is called.
 *
 * @covers \OCA\Stackiq\Mcp\McpReadTools
 * @covers \OCA\Stackiq\Mcp\McpWriteTools
 * @covers \OCA\Stackiq\Mcp\McpArgumentValidator
 * @uses   \OCA\Stackiq\Mcp\McpAdminGate
 * @uses   \OCA\Stackiq\Mcp\McpPublicationGate
 * @uses   \OCA\Stackiq\Mcp\McpSbomGate
 */
class McpCuratedToolsTest extends TestCase {
	use McpToolFixture;

	private const A = '123e4567-e89b-42d3-a456-426614174000';

	private const B = '223e4567-e89b-42d3-a456-426614174000';

	/**
	 * The service doubles by interface.
	 *
	 * @var array<string, MockObject>
	 */
	private array $services = [];

	/**
	 * Set up the service doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		foreach ([
			AanbodServiceInterface::class,
			AangebodenGebruikServiceInterface::class,
			PortfolioReportServiceInterface::class,
			OrganisationReportAccessInterface::class,
			ModerationServiceInterface::class,
			ReviewAggregateServiceInterface::class,
			SbomImportServiceInterface::class,
			ViewServiceInterface::class,
			MergeOrganisatieServiceInterface::class,
			EolSyncServiceInterface::class,
			PublicationServiceInterface::class,
			ReviewServiceInterface::class,
			IntakeServiceInterface::class,
			ContactpersonenController::class,
		] as $class) {
			$this->services[$class] = $this->createMock($class);
		}
	}//end setUp()

	/**
	 * A service double.
	 *
	 * @param class-string $class The interface.
	 *
	 * @return MockObject
	 */
	private function svc(string $class): MockObject {
		return $this->services[$class];
	}//end svc()

	/**
	 * The read tools over a caller.
	 *
	 * @param McpAdminGate $session The caller.
	 *
	 * @return McpReadTools
	 */
	private function reads(McpAdminGate $session): McpReadTools {
		return new McpReadTools(
			$session,
			new McpArgumentValidator(),
			new McpSbomGate($session, $this->svc(SbomImportServiceInterface::class), $this->groupManager),
			$this->svc(ContactpersonenController::class),
			$this->svc(AanbodServiceInterface::class),
			$this->svc(AangebodenGebruikServiceInterface::class),
			$this->svc(PortfolioReportServiceInterface::class),
			$this->svc(OrganisationReportAccessInterface::class),
			$this->svc(ModerationServiceInterface::class),
			$this->svc(ReviewAggregateServiceInterface::class),
			$this->svc(SbomImportServiceInterface::class),
			$this->svc(ViewServiceInterface::class),
			$this->svc(MergeOrganisatieServiceInterface::class),
			$this->svc(EolSyncServiceInterface::class)
		);
	}//end reads()

	/**
	 * The write tools over a caller.
	 *
	 * @param McpAdminGate $session The caller.
	 *
	 * @return McpWriteTools
	 */
	private function writes(McpAdminGate $session): McpWriteTools {
		$publications = $this->svc(PublicationServiceInterface::class);
		return new McpWriteTools(
			$session,
			new McpArgumentValidator(),
			new McpPublicationGate($session, $publications, $this->groupManager, $this->config),
			new McpSbomGate($session, $this->svc(SbomImportServiceInterface::class), $this->groupManager),
			$publications,
			$this->svc(ModerationServiceInterface::class),
			$this->svc(ReviewServiceInterface::class),
			$this->svc(IntakeServiceInterface::class),
			$this->svc(AanbodServiceInterface::class),
			$this->svc(AangebodenGebruikServiceInterface::class),
			$this->svc(MergeOrganisatieServiceInterface::class),
			$this->svc(SbomImportServiceInterface::class),
			$this->svc(EolSyncServiceInterface::class)
		);
	}//end writes()

	/**
	 * Expect no call on any service.
	 *
	 * @return void
	 */
	private function expectNoServiceCall(): void {
		foreach ($this->services as $service) {
			$service->expects($this->never())->method($this->anything());
		}
	}//end expectNoServiceCall()

	/**
	 * A missing or badly typed argument is a validation error and no service is called (task 6.3).
	 *
	 * @return void
	 */
	public function testABadArgumentReachesNoService(): void {
		$this->expectNoServiceCall();
		$reads = $this->reads($this->caller(uid: 'root', groups: ['admin'], admin: true));
		$writes = $this->writes($this->caller(uid: 'root', groups: ['admin'], admin: true));

		$cases = [
			[$reads, 'listOffers', ['limit' => 0]],
			[$reads, 'listOffers', ['page' => '2']],
			[$reads, 'listOfferedUsages', []],
			[$reads, 'listOfferedUsages', ['as' => 'supplier']],
			[$reads, 'getPortfolioReport', ['organisationUuid' => 'x']],
			[$reads, 'listPendingModerations', ['type' => 'module']],
			[$reads, 'getReviewAggregate', ['subjectType' => 'module']],
			[$reads, 'getSbomImportStatus', []],
			[$reads, 'getView', ['viewId' => '']],
			[$reads, 'previewOrganisationMerge', ['sourceUuid' => self::A]],
			[$writes, 'publishObject', ['objectType' => 'module']],
			[$writes, 'publishObject', ['objectType' => 'module', 'uuid' => self::A, 'when' => 5]],
			[$writes, 'depublishObject', ['uuid' => self::A]],
			[$writes, 'approveRegistration', ['uuid' => 'nope']],
			[$writes, 'rejectRegistration', ['uuid' => self::A, 'type' => 'contract']],
			[$writes, 'submitReview', ['subjectType' => 'module', 'subjectId' => self::A, 'review' => []]],
			[$writes, 'submitReview', ['subjectType' => 'module', 'subjectId' => self::A, 'review' => ['a', 'b']]],
			[$writes, 'registerOrganisation', ['organisation' => 'Acme']],
			[$writes, 'acceptOffer', []],
			[$writes, 'declineOffer', ['offerUuid' => 7]],
			[$writes, 'claimUsage', ['usageUuid' => 'x']],
			[$writes, 'declineUsage', []],
			[$writes, 'mergeOrganisations', ['targetUuid' => self::B]],
			[$writes, 'importSbom', ['moduleVersionUuid' => self::A]],
			[$writes, 'importSbom', ['moduleVersionUuid' => self::A, 'content' => 'not json']],
			[$writes, 'importSbom', ['moduleVersionUuid' => self::A, 'content' => '{}', 'format' => 'xml']],
		];
		foreach ($cases as [$tools, $method, $args]) {
			$result = $tools->$method($args);
			$this->assertSame('validation_error', ($result['error'] ?? null), $method.' '.json_encode($args));
			$this->assertTrue($result['isError']);
		}
	}//end testABadArgumentReachesNoService()

	/**
	 * Without a signed-in user every tool refuses and no service is called.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerReachesNoService(): void {
		$this->expectNoServiceCall();
		$reads = $this->reads($this->caller(uid: null));
		$writes = $this->writes($this->caller(uid: null));

		foreach ([
			[$reads, 'getMyContactProfile', []],
			[$reads, 'listOffers', []],
			[$reads, 'listOfferedUsages', ['as' => 'consumer']],
			[$reads, 'getPortfolioReport', ['organisationUuid' => self::A]],
			[$reads, 'listViews', []],
			[$reads, 'getSbomImportStatus', ['moduleVersionUuid' => self::A]],
			[$writes, 'publishObject', ['objectType' => 'module', 'uuid' => self::A]],
			[$writes, 'submitReview', ['subjectType' => 'module', 'subjectId' => self::A, 'review' => ['rating' => 8]]],
			[$writes, 'registerOrganisation', ['organisation' => ['name' => 'Acme']]],
			[$writes, 'acceptOffer', ['offerUuid' => self::A]],
			[$writes, 'importSbom', ['moduleVersionUuid' => self::A, 'content' => '{}']],
		] as [$tools, $method, $args]) {
			$this->assertSame('unauthenticated', $tools->$method($args)['error'], $method);
		}
	}//end testAnAnonymousCallerReachesNoService()

	/**
	 * The admin-only tools refuse a signed-in non-administrator before any service call.
	 *
	 * @return void
	 */
	public function testAdminToolsRefuseOtherUsers(): void {
		$this->expectNoServiceCall();
		$reads = $this->reads($this->caller(uid: 'bob', groups: ['aanbod-beheerder']));
		$writes = $this->writes($this->caller(uid: 'bob', groups: ['aanbod-beheerder']));

		foreach ([
			[$reads, 'listPendingModerations', []],
			[$reads, 'previewOrganisationMerge', ['sourceUuid' => self::A, 'targetUuid' => self::B]],
			[$reads, 'getEolSyncStatus', []],
			[$writes, 'approveRegistration', ['uuid' => self::A]],
			[$writes, 'rejectRegistration', ['uuid' => self::A]],
			[$writes, 'mergeOrganisations', ['sourceUuid' => self::A, 'targetUuid' => self::B]],
			[$writes, 'triggerEolSync', []],
		] as [$tools, $method, $args]) {
			$this->assertSame('forbidden', $tools->$method($args)['error'], $method);
		}
	}//end testAdminToolsRefuseOtherUsers()

	/**
	 * A non-owning caller cannot publish, and the publication service is never asked to (task 6.2).
	 *
	 * @return void
	 */
	public function testANonOwningCallerCannotPublish(): void {
		$publications = $this->svc(PublicationServiceInterface::class);
		$publications->method('isPublishableType')->willReturn(true);
		$publications->method('resolveEntry')->willReturn(['data' => ['_organisation' => 'org-a']]);
		$publications->expects($this->never())->method('publish');
		$publications->expects($this->never())->method('depublish');
		$writes = $this->writes($this->caller(uid: 'bob', groups: ['aanbod-beheerder'], org: 'org-b'));

		$this->assertSame('forbidden', $writes->publishObject(['objectType' => 'module', 'uuid' => self::A])['error']);
		$this->assertSame('forbidden', $writes->depublishObject(['objectType' => 'module', 'uuid' => self::A])['error']);
	}//end testANonOwningCallerCannotPublish()

	/**
	 * The owner publishes from a date and depublishes; a refused publish is an error.
	 *
	 * @return void
	 */
	public function testTheOwnerPublishesAndDepublishes(): void {
		$publications = $this->svc(PublicationServiceInterface::class);
		$publications->method('isPublishableType')->willReturn(true);
		$publications->method('resolveEntry')->willReturn(['data' => ['_organisation' => 'org-a']]);
		$publications->expects($this->exactly(2))->method('publish')
			->with('module', self::A, '2027-01-01T00:00:00Z')
			->willReturnOnConsecutiveCalls(['ok' => true, 'publicationDate' => '2027-01-01T00:00:00Z'], ['ok' => false, 'reason' => 'already published']);
		$publications->expects($this->once())->method('depublish')->with('module', self::A)->willReturn(['ok' => true]);
		$writes = $this->writes($this->caller(uid: 'alice', groups: ['aanbod-beheerder'], org: 'org-a'));
		$args = ['objectType' => 'module', 'uuid' => self::A, 'when' => '2027-01-01T00:00:00Z'];

		$this->assertSame(['ok' => true, 'publicationDate' => '2027-01-01T00:00:00Z'], $writes->publishObject($args));
		$this->assertSame(['isError' => true, 'error' => 'validation_error', 'message' => 'already published'], $writes->publishObject($args));
		$this->assertSame(['ok' => true], $writes->depublishObject(['objectType' => 'module', 'uuid' => self::A]));
	}//end testTheOwnerPublishesAndDepublishes()

	/**
	 * A review declaring itself approved comes back pending: the service decides (task 6.4).
	 *
	 * @return void
	 */
	public function testAReviewIsQueuedAsPending(): void {
		$payload = ['rating' => 9, 'name' => 'Good', 'status' => 'approved'];
		$this->svc(ReviewServiceInterface::class)->expects($this->once())->method('submit')
			->with($payload, 'module', self::A)
			->willReturn(['ok' => true, 'uuid' => 'review-1', 'status' => 'pending', 'reason' => null]);
		$writes = $this->writes($this->caller(uid: 'ann'));

		$this->assertSame(
			['uuid' => 'review-1', 'status' => 'pending', 'message' => 'Review received and queued for moderation'],
			$writes->submitReview(['subjectType' => 'module', 'subjectId' => self::A, 'review' => $payload])
		);
	}//end testAReviewIsQueuedAsPending()

	/**
	 * The merge preview calls dryRun once and never execute (task 6.4).
	 *
	 * @return void
	 */
	public function testTheMergePreviewNeverMerges(): void {
		$merges = $this->svc(MergeOrganisatieServiceInterface::class);
		$merges->expects($this->once())->method('dryRun')->with(self::A, self::B)->willReturn(['ok' => true, 'moved' => ['usage' => 3]]);
		$merges->expects($this->never())->method('execute');

		$this->assertSame(
			['ok' => true, 'moved' => ['usage' => 3]],
			$this->reads($this->caller(uid: 'root', admin: true))->previewOrganisationMerge(['sourceUuid' => self::A, 'targetUuid' => self::B])
		);
	}//end testTheMergePreviewNeverMerges()

	/**
	 * The merge passes the actor; a refused merge is an error.
	 *
	 * @return void
	 */
	public function testTheMergeRunsAsTheAdministrator(): void {
		$this->svc(MergeOrganisatieServiceInterface::class)->expects($this->exactly(2))->method('execute')
			->with(self::A, self::B, 'root')
			->willReturnOnConsecutiveCalls(['ok' => true, 'mergedInto' => self::B], ['ok' => false, 'reason' => 'source already merged']);
		$writes = $this->writes($this->caller(uid: 'root', admin: true));
		$args = ['sourceUuid' => self::A, 'targetUuid' => self::B];

		$this->assertSame(['ok' => true, 'mergedInto' => self::B], $writes->mergeOrganisations($args));
		$this->assertSame('source already merged', $writes->mergeOrganisations($args)['message']);
	}//end testTheMergeRunsAsTheAdministrator()

	/**
	 * The portfolio report of a foreign organisation is refused before the report is built.
	 *
	 * @return void
	 */
	public function testAForeignPortfolioIsRefused(): void {
		$this->svc(OrganisationReportAccessInterface::class)->method('mayRead')
			->willReturnCallback(static fn ($user, string $organisationUuid): bool => $organisationUuid === self::A);
		$this->svc(PortfolioReportServiceInterface::class)->expects($this->once())->method('buildReport')->with(self::A)->willReturn(['rows' => []]);
		$reads = $this->reads($this->caller(uid: 'ann'));

		$this->assertSame('forbidden', $reads->getPortfolioReport(['organisationUuid' => self::B])['error']);
		$this->assertSame(['rows' => []], $reads->getPortfolioReport(['organisationUuid' => self::A]));
	}//end testAForeignPortfolioIsRefused()

	/**
	 * Listings read from the database with the paging the REST twins forward; the role picks the usage listing.
	 *
	 * @return void
	 */
	public function testListingsForwardPaging(): void {
		$this->svc(AanbodServiceInterface::class)->expects($this->once())->method('getAanbod')
			->with(['_source' => 'database', '_limit' => 10, 'limit' => 10, '_page' => 2])->willReturn(['results' => ['offer']]);
		$usages = $this->svc(AangebodenGebruikServiceInterface::class);
		$usages->expects($this->once())->method('getGebruiksWhereAfnemer')->with(['_source' => 'database'])->willReturn(['results' => ['as-consumer']]);
		$usages->expects($this->once())->method('getGebruiksWhereDeelnemers')->with(['_source' => 'database'])->willReturn(['results' => ['as-participant']]);
		$reads = $this->reads($this->caller(uid: 'ann'));

		$this->assertSame(['results' => ['offer']], $reads->listOffers(['limit' => 10, 'page' => 2]));
		$this->assertSame(['results' => ['as-consumer']], $reads->listOfferedUsages(['as' => 'consumer']));
		$this->assertSame(['results' => ['as-participant']], $reads->listOfferedUsages(['as' => 'participant']));
	}//end testListingsForwardPaging()

	/**
	 * The offer and usage actions pass the uuid and map the service's refusals.
	 *
	 * @return void
	 */
	public function testOfferAndUsageActions(): void {
		$offers = $this->svc(AanbodServiceInterface::class);
		$offers->expects($this->once())->method('acceptAanbod')->with(self::A, [])->willReturn(['success' => true, 'aanbod' => self::A]);
		$offers->expects($this->once())->method('denyAanbod')->with(self::A, [])
			->willReturn(['success' => false, 'error' => 'Operation not allowed: active organization is not the afnemer or aanbieder']);
		$usages = $this->svc(AangebodenGebruikServiceInterface::class);
		$usages->expects($this->once())->method('setGebruikSelfToActiveOrg')->with(self::B, [])->willReturn(['success' => false, 'error' => 'Gebruik not found']);
		$usages->expects($this->once())->method('deleteGebruikAsAfnemer')->with(self::B, [])->willThrowException(new RuntimeException('register down'));
		$writes = $this->writes($this->caller(uid: 'ann'));

		$this->assertSame(['success' => true, 'aanbod' => self::A], $writes->acceptOffer(['offerUuid' => self::A]));
		$this->assertSame('forbidden', $writes->declineOffer(['offerUuid' => self::A])['error']);
		$this->assertSame(['isError' => true, 'error' => 'failed', 'message' => 'Gebruik not found'], $writes->claimUsage(['usageUuid' => self::B]));
		$this->assertSame(['isError' => true, 'error' => 'unavailable', 'message' => 'register down'], $writes->declineUsage(['usageUuid' => self::B]));
	}//end testOfferAndUsageActions()

	/**
	 * The moderation tools default to organisations and pass a review type through.
	 *
	 * @return void
	 */
	public function testModeration(): void {
		$moderation = $this->svc(ModerationServiceInterface::class);
		$moderation->expects($this->once())->method('approve')->with(self::A, 'organization')->willReturn(['ok' => true, 'status' => 'active']);
		$moderation->expects($this->once())->method('reject')->with(self::A, 'software-review')->willReturn(['ok' => false, 'reason' => 'not pending']);
		$moderation->expects($this->once())->method('listPending')->with('software-review')->willReturn(['ok' => true, 'items' => []]);
		$admin = $this->caller(uid: 'root', admin: true);

		$this->assertSame(['ok' => true, 'status' => 'active'], $this->writes($admin)->approveRegistration(['uuid' => self::A]));
		$this->assertSame('not pending', $this->writes($admin)->rejectRegistration(['uuid' => self::A, 'type' => 'software-review'])['message']);
		$this->assertSame(['ok' => true, 'items' => []], $this->reads($admin)->listPendingModerations(['type' => 'software-review']));
	}//end testModeration()

	/**
	 * An SBOM imports inline for a manager of the application; a refused manager reaches no import.
	 *
	 * @return void
	 */
	public function testSbomImportAndStatus(): void {
		$sbom = $this->svc(SbomImportServiceInterface::class);
		$sbom->method('resolveParentModuleUuid')->willReturn('module-1');
		$sbom->method('userCanReadModule')->willReturn(true);
		$sbom->expects($this->once())->method('importForModuleVersie')
			->with(self::A, '{"bomFormat":"CycloneDX"}', 'cyclonedx-json', 'sbom.json')
			->willReturn(['created' => 4]);
		$sbom->expects($this->once())->method('getStatus')->with(self::A, 'op-1')->willReturn(['state' => 'done']);

		$writes = $this->writes($this->caller(uid: 'fb', groups: ['functioneel-beheerder']));
		$this->assertSame(['created' => 4], $writes->importSbom(['moduleVersionUuid' => self::A, 'content' => '{"bomFormat":"CycloneDX"}']));
		$this->assertSame(['state' => 'done'], $this->reads($this->caller(uid: 'fb'))->getSbomImportStatus(['moduleVersionUuid' => self::A, 'operationId' => 'op-1']));

		$this->assertSame(
			'forbidden',
			$this->writes($this->caller(uid: 'ann', groups: ['software-catalog-users']))->importSbom(['moduleVersionUuid' => self::A, 'content' => '{}'])['error']
		);
	}//end testSbomImportAndStatus()

	/**
	 * The profile, views, aggregate, intake and EOL tools return what their services answer.
	 *
	 * @return void
	 */
	public function testThePlainDelegates(): void {
		$this->svc(ContactpersonenController::class)->expects($this->exactly(2))->method('getMe')
			->willReturnOnConsecutiveCalls(new JSONResponse(['email' => 'ann@example.org']), new JSONResponse(['message' => 'boom'], 500));
		$this->svc(ViewServiceInterface::class)->expects($this->once())->method('getAllViews')->with([])->willReturn(['success' => true, 'count' => 0]);
		$this->svc(ViewServiceInterface::class)->expects($this->once())->method('getView')->with('view-1', [])->willReturn(['success' => true, 'view' => []]);
		$this->svc(ReviewAggregateServiceInterface::class)->expects($this->once())->method('getAggregate')->with('catalogService', self::A)
			->willReturn(['ok' => true, 'average' => 7.5, 'count' => 2, 'items' => ['x']]);
		$this->svc(IntakeServiceInterface::class)->expects($this->once())->method('submit')->with(['name' => 'Acme'])
			->willReturn(['ok' => true, 'uuid' => 'org-new', 'status' => 'pending', 'reason' => null]);
		$eol = $this->svc(EolSyncServiceInterface::class);
		$eol->expects($this->once())->method('getStatus')->willReturn(['lastRun' => 'yesterday']);
		$eol->expects($this->once())->method('run')->willReturn(['lastRun' => 'now']);

		$reads = $this->reads($this->caller(uid: 'ann'));
		$this->assertSame(['email' => 'ann@example.org'], $reads->getMyContactProfile([]));
		$this->assertSame(['isError' => true, 'error' => 'unavailable', 'message' => 'boom'], $reads->getMyContactProfile([]));
		$this->assertSame(['success' => true, 'count' => 0], $reads->listViews([]));
		$this->assertSame(['success' => true, 'view' => []], $reads->getView(['viewId' => 'view-1']));
		$this->assertSame(['average' => 7.5, 'count' => 2, 'items' => ['x']], $reads->getReviewAggregate(['subjectType' => 'catalogService', 'subjectId' => self::A]));
		$this->assertSame(['uuid' => 'org-new', 'status' => 'pending'], $this->writes($this->caller(uid: 'ann'))->registerOrganisation(['organisation' => ['name' => 'Acme']]));

		$admin = $this->caller(uid: 'root', admin: true);
		$this->assertSame(['status' => ['lastRun' => 'yesterday']], $this->reads($admin)->getEolSyncStatus([]));
		$this->assertSame(['status' => ['lastRun' => 'now']], $this->writes($admin)->triggerEolSync([]));
	}//end testThePlainDelegates()
	/**
	 * A service that refuses comes back as a validation error with its reason, and one that fails as unavailable.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresAreStructuredErrors(): void {
		$this->svc(ModerationServiceInterface::class)->method('listPending')->willReturn(['ok' => false, 'reason' => 'unknown type']);
		$this->svc(ReviewAggregateServiceInterface::class)->method('getAggregate')->willReturnOnConsecutiveCalls(
			['ok' => false, 'reason' => 'no such module'],
			$this->throwException(new RuntimeException('index offline'))
		);
		$this->svc(ReviewServiceInterface::class)->method('submit')->willReturnOnConsecutiveCalls(
			['ok' => false, 'reason' => 'rating out of range'],
			$this->throwException(new RuntimeException('store offline'))
		);
		$this->svc(IntakeServiceInterface::class)->method('submit')->willReturn(['ok' => false, 'reason' => 'name taken']);
		$admin = $this->caller(uid: 'root', admin: true);
		$reads = $this->reads($admin);
		$writes = $this->writes($admin);
		$subject = ['subjectType' => 'module', 'subjectId' => self::A];
		$review = $subject + ['review' => ['rating' => 11]];

		$this->assertSame(['validation_error', 'unknown type'], self::errorOf($reads->listPendingModerations(['type' => 'software-review'])));
		$this->assertSame(['validation_error', 'no such module'], self::errorOf($reads->getReviewAggregate($subject)));
		$this->assertSame(['unavailable', 'index offline'], self::errorOf($reads->getReviewAggregate($subject)));
		$this->assertSame(['validation_error', 'rating out of range'], self::errorOf($writes->submitReview($review)));
		$this->assertSame(['unavailable', 'store offline'], self::errorOf($writes->submitReview($review)));
		$this->assertSame(['validation_error', 'name taken'], self::errorOf($writes->registerOrganisation(['organisation' => ['name' => 'Gemeente X']])));
	}//end testRefusalsAndFailuresAreStructuredErrors()

	/**
	 * A contact profile answer that is not an object reads as an empty profile.
	 *
	 * @return void
	 */
	public function testANonObjectProfileReadsAsEmpty(): void {
		$this->svc(ContactpersonenController::class)->method('getMe')->willReturn(new JSONResponse('not an object'));

		$this->assertSame([], $this->reads($this->caller(uid: 'ann'))->getMyContactProfile([]));
	}//end testANonObjectProfileReadsAsEmpty()

	/**
	 * The error code and message of a structured error.
	 *
	 * @param array<string, mixed> $result The tool result.
	 *
	 * @return array{0: mixed, 1: mixed}
	 */
	private static function errorOf(array $result): array {
		return [($result['error'] ?? null), ($result['message'] ?? null)];
	}//end errorOf()
}//end class
