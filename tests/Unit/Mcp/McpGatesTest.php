<?php

/**
 * Unit tests for the StackIQ MCP publication and SBOM gates.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#6.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Mcp;

require_once __DIR__.'/McpToolFixture.php';

use OCA\Stackiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\SbomImportServiceInterface;
use OCA\Stackiq\Mcp\McpPublicationGate;
use OCA\Stackiq\Mcp\McpSbomGate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The gates refuse and admit exactly as PublicationController and SbomController do.
 *
 * @covers \OCA\Stackiq\Mcp\McpPublicationGate
 * @covers \OCA\Stackiq\Mcp\McpSbomGate
 * @uses   \OCA\Stackiq\Mcp\McpAdminGate
 * @uses   \OCA\Stackiq\Mcp\McpArgumentValidator
 */
class McpGatesTest extends TestCase {
	use McpToolFixture;

	private const ENTRY = '123e4567-e89b-42d3-a456-426614174000';

	/**
	 * @var PublicationServiceInterface&MockObject
	 */
	private PublicationServiceInterface&MockObject $publications;

	/**
	 * @var SbomImportServiceInterface&MockObject
	 */
	private SbomImportServiceInterface&MockObject $sbom;

	/**
	 * Set up the service doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->publications = $this->createMock(PublicationServiceInterface::class);
		$this->sbom = $this->createMock(SbomImportServiceInterface::class);
	}//end setUp()

	/**
	 * A publication gate over a caller and an entry owned by an organisation.
	 *
	 * @param string|null          $uid    The caller.
	 * @param list<string>         $groups The caller's groups.
	 * @param string               $org    The caller's active organisation.
	 * @param array<string, mixed> $data   The entry data.
	 *
	 * @return McpPublicationGate
	 */
	private function publicationGate(?string $uid, array $groups=[], string $org='', array $data=['_organisation' => 'org-a']): McpPublicationGate {
		$session = $this->caller(uid: $uid, groups: $groups, org: $org);
		$this->publications->method('isPublishableType')->willReturnCallback(static fn (string $type): bool => $type === 'module');
		$this->publications->method('resolveEntry')->willReturn(['data' => $data]);

		return new McpPublicationGate($session, $this->publications, $this->groupManager, $this->config);
	}//end publicationGate()

	/**
	 * An aanbod-beheerder of another organisation is refused.
	 *
	 * @return void
	 */
	public function testANonOwningBeheerderIsRefused(): void {
		$refusal = $this->publicationGate(uid: 'bob', groups: ['aanbod-beheerder'], org: 'org-b')->authorise(objectType: 'module', uuid: self::ENTRY);

		$this->assertSame('forbidden', $refusal['error']);
		$this->assertTrue($refusal['isError']);
	}//end testANonOwningBeheerderIsRefused()

	/**
	 * Owning the entry is not enough without the aanbod-beheerder group.
	 *
	 * @return void
	 */
	public function testAnOwnerWithoutTheBeheerderGroupIsRefused(): void {
		$this->assertSame('forbidden', $this->publicationGate(uid: 'carl', groups: [], org: 'org-a')->authorise(objectType: 'module', uuid: self::ENTRY)['error']);
	}//end testAnOwnerWithoutTheBeheerderGroupIsRefused()

	/**
	 * The owning aanbod-beheerder passes, also when only the provider names the owner.
	 *
	 * @return void
	 */
	public function testTheOwningBeheerderPasses(): void {
		$this->assertNull($this->publicationGate(uid: 'alice', groups: ['aanbod-beheerder'], org: 'org-a')->authorise(objectType: 'module', uuid: self::ENTRY));
		$this->setUp();
		$this->assertNull(
			$this->publicationGate(uid: 'alice', groups: ['aanbod-beheerder'], org: 'org-a', data: ['provider' => 'org-a'])->authorise(objectType: 'module', uuid: self::ENTRY)
		);
	}//end testTheOwningBeheerderPasses()

	/**
	 * An administrator passes whatever organisation owns the entry.
	 *
	 * @return void
	 */
	public function testAnAdministratorPasses(): void {
		$this->assertNull($this->publicationGate(uid: 'root', groups: ['admin'], org: '')->authorise(objectType: 'module', uuid: self::ENTRY));
	}//end testAnAdministratorPasses()

	/**
	 * An unpublishable type, an unknown entry, a peer-sourced entry and an anonymous caller are refused, in that order.
	 *
	 * @return void
	 */
	public function testTheEntryChecksRefuse(): void {
		$this->assertSame('validation_error', $this->publicationGate(uid: 'root', groups: ['admin'])->authorise(objectType: 'contract', uuid: self::ENTRY)['error']);

		$this->setUp();
		$this->publications->method('isPublishableType')->willReturn(true);
		$this->publications->method('resolveEntry')->willReturn(null);
		$gate = new McpPublicationGate($this->caller(uid: 'root', groups: ['admin']), $this->publications, $this->groupManager, $this->config);
		$this->assertSame('not_found', $gate->authorise(objectType: 'module', uuid: self::ENTRY)['error']);

		$this->setUp();
		$peer = $this->publicationGate(uid: 'root', groups: ['admin'], data: ['_source' => ['instance' => 'https://peer.example']]);
		$this->assertSame('forbidden', $peer->authorise(objectType: 'module', uuid: self::ENTRY)['error']);

		$this->setUp();
		$this->publications->expects($this->never())->method('resolveEntry');
		$anonymous = new McpPublicationGate($this->caller(uid: null), $this->publications, $this->groupManager, $this->config);
		$this->assertSame('unauthenticated', $anonymous->authorise(objectType: 'module', uuid: self::ENTRY)['error']);
	}//end testTheEntryChecksRefuse()

	/**
	 * Reading the SBOM status: an administrator, or a reader of the parent application; anyone else sees not_found.
	 *
	 * @return void
	 */
	public function testTheSbomReadGate(): void {
		$this->sbom->method('resolveParentModuleUuid')->with(self::ENTRY)->willReturn('module-1');
		$this->sbom->method('userCanReadModule')->willReturnCallback(static fn (string $module): bool => $module === 'module-1');
		$this->assertNull((new McpSbomGate($this->caller(uid: 'ann'), $this->sbom, $this->groupManager))->authoriseRead(moduleVersionUuid: self::ENTRY));

		$this->setUp();
		$this->sbom->method('resolveParentModuleUuid')->willReturn(null);
		$this->assertSame('not_found', (new McpSbomGate($this->caller(uid: 'ann'), $this->sbom, $this->groupManager))->authoriseRead(moduleVersionUuid: self::ENTRY)['error']);
		$this->assertNull((new McpSbomGate($this->caller(uid: 'root', admin: true), $this->sbom, $this->groupManager))->authoriseRead(moduleVersionUuid: self::ENTRY));
		$this->assertSame('unauthenticated', (new McpSbomGate($this->caller(uid: null), $this->sbom, $this->groupManager))->authoriseRead(moduleVersionUuid: self::ENTRY)['error']);
	}//end testTheSbomReadGate()

	/**
	 * Importing an SBOM: an administrator, or a manage group member who can read the parent application.
	 *
	 * @return void
	 */
	public function testTheSbomImportGate(): void {
		$this->sbom->method('resolveParentModuleUuid')->willReturn('module-1');
		$this->sbom->method('userCanReadModule')->willReturn(true);

		$this->assertSame(
			'forbidden',
			(new McpSbomGate($this->caller(uid: 'ann', groups: ['software-catalog-users']), $this->sbom, $this->groupManager))->authoriseImport(moduleVersionUuid: self::ENTRY)['error']
		);
		$this->assertNull((new McpSbomGate($this->caller(uid: 'ann', groups: ['functioneel-beheerder']), $this->sbom, $this->groupManager))->authoriseImport(moduleVersionUuid: self::ENTRY));
		$this->assertNull((new McpSbomGate($this->caller(uid: 'root', admin: true), $this->sbom, $this->groupManager))->authoriseImport(moduleVersionUuid: self::ENTRY));

		$this->setUp();
		$this->sbom->method('resolveParentModuleUuid')->willReturn('module-1');
		$this->sbom->method('userCanReadModule')->willReturn(false);
		$refusal = (new McpSbomGate($this->caller(uid: 'ann', groups: ['aanbod-beheerder']), $this->sbom, $this->groupManager))->authoriseImport(moduleVersionUuid: self::ENTRY);
		$this->assertSame('forbidden', $refusal['error']);
		$this->assertStringContainsString('manage access', $refusal['message']);
	}//end testTheSbomImportGate()
	/**
	 * An entry whose data is not an object has no owner: an administrator passes, an aanbod-beheerder is refused.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutObjectDataHasNoOwner(): void {
		$this->assertNull($this->publicationGate(uid: 'root', groups: ['admin'], data: [])->authorise(objectType: 'module', uuid: self::ENTRY));

		$this->setUp();
		$this->publications->method('isPublishableType')->willReturn(true);
		$this->publications->method('resolveEntry')->willReturn(['data' => 'not an object']);
		$gate = new McpPublicationGate($this->caller(uid: 'ann', groups: ['aanbod-beheerder'], org: 'org-a'), $this->publications, $this->groupManager, $this->config);
		$refusal = $gate->authorise(objectType: 'module', uuid: self::ENTRY);

		$this->assertSame(['forbidden', 'You do not have permission to publish this entry.'], [$refusal['error'], $refusal['message']]);
	}//end testAnEntryWithoutObjectDataHasNoOwner()

	/**
	 * An anonymous caller cannot import an SBOM, and the application is never looked up.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerCannotImportAnSbom(): void {
		$this->sbom->expects($this->never())->method('resolveParentModuleUuid');

		$refusal = (new McpSbomGate($this->caller(uid: null), $this->sbom, $this->groupManager))->authoriseImport(moduleVersionUuid: self::ENTRY);

		$this->assertSame(['unauthenticated', 'This tool needs a signed-in user.'], [$refusal['error'], $refusal['message']]);
	}//end testAnAnonymousCallerCannotImportAnSbom()
}//end class
