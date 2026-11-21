<?php

/**
 * Unit tests for CatalogExportGate.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Export;

use OCA\Stackiq\Service\Export\CatalogExportGate;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Who may export a catalogue schema: a signed-in user, by the schema's `export` authorization.
 */
#[CoversClass(CatalogExportGate::class)]
class CatalogExportGateTest extends TestCase {

	/**
	 * The schema id lookup.
	 *
	 * @var CatalogueLocator&MockObject
	 */
	private CatalogueLocator&MockObject $catalogue;

	/**
	 * The container that hands out the schema mapper.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The group lookups.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalogue    = $this->createMock(CatalogueLocator::class);
		$this->container    = $this->createMock(ContainerInterface::class);
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger       = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The gate under test.
	 *
	 * @return CatalogExportGate
	 */
	private function gate(): CatalogExportGate {
		return new CatalogExportGate(
			catalogue: $this->catalogue,
			container: $this->container,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}//end gate()

	/**
	 * Sign in a user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A schema mapper whose find() answers a schema with this authorization, recording the call.
	 *
	 * @param array<string, mixed>|null $authorization The schema's authorization.
	 * @param array<int, mixed>         $calls         Receives the find() arguments.
	 *
	 * @return object
	 */
	private function mapperWith(?array $authorization, array &$calls): object {
		return new class($authorization, $calls) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null $authorization The authorization.
			 * @param array<int, mixed>         $calls         The recorded calls.
			 */
			public function __construct(private ?array $authorization, private array &$calls) {
			}

			/**
			 * The schema.
			 *
			 * @param int  $id            The id.
			 * @param bool $_rbac         RBAC.
			 * @param bool $_multitenancy Multitenancy.
			 *
			 * @return object
			 */
			public function find(int $id, bool $_rbac=true, bool $_multitenancy=true): object {
				$this->calls[] = [$id, $_rbac, $_multitenancy];
				$authorization = $this->authorization;
				return new class($authorization) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed>|null $authorization The authorization.
					 */
					public function __construct(private ?array $authorization) {
					}

					/**
					 * The authorization.
					 *
					 * @return array<string, mixed>|null
					 */
					public function getAuthorization(): ?array {
						return $this->authorization;
					}
				};
			}
		};
	}//end mapperWith()

	/**
	 * Without a signed-in user nothing is exported and no schema is read.
	 *
	 * @return void
	 */
	public function testAnonymousCallerMayNotExport(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->catalogue->expects($this->never())->method('getSchemaIdForObjectType');

		$this->assertFalse($this->gate()->mayExport(schema: 'gebruik'));
	}//end testAnonymousCallerMayNotExport()

	/**
	 * An unconfigured schema refuses the export.
	 *
	 * @return void
	 */
	public function testUnconfiguredSchemaRefuses(): void {
		$this->signIn(uid: 'anna');
		$this->catalogue->expects($this->once())->method('getSchemaIdForObjectType')->with('gebruik')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$this->assertFalse($this->gate()->mayExport(schema: 'gebruik'));
	}//end testUnconfiguredSchemaRefuses()

	/**
	 * A schema without an export rule lets every signed-in user export, read without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testSchemaWithoutExportRuleAllowsAnySignedInUser(): void {
		$this->signIn(uid: 'anna');
		$calls = [];
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(42);
		$this->container->expects($this->once())->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')
			->willReturn($this->mapperWith(authorization: ['read' => ['public']], calls: $calls));
		$this->groupManager->expects($this->never())->method('isInGroup');

		$this->assertTrue($this->gate()->mayExport(schema: 'gebruik'));
		$this->assertSame([[42, false, false]], $calls);
	}//end testSchemaWithoutExportRuleAllowsAnySignedInUser()

	/**
	 * A null authorization counts as no rule at all.
	 *
	 * @return void
	 */
	public function testNullAuthorizationAllowsExport(): void {
		$this->signIn(uid: 'anna');
		$calls = [];
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(7);
		$this->container->method('get')->willReturn($this->mapperWith(authorization: null, calls: $calls));

		$this->assertTrue($this->gate()->mayExport(schema: 'gebruik'));
	}//end testNullAuthorizationAllowsExport()

	/**
	 * A member of a listed export group may export.
	 *
	 * @return void
	 */
	public function testMemberOfAnExportGroupMayExport(): void {
		$this->signIn(uid: 'anna');
		$calls = [];
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(7);
		$this->container->method('get')->willReturn($this->mapperWith(authorization: ['export' => ['beheer', 'inkoop']], calls: $calls));
		$this->groupManager->method('isAdmin')->with('anna')->willReturn(false);
		$this->groupManager->expects($this->exactly(2))->method('isInGroup')
			->willReturnMap([['anna', 'beheer', false], ['anna', 'inkoop', true]]);

		$this->assertTrue($this->gate()->mayExport(schema: 'gebruik'));
	}//end testMemberOfAnExportGroupMayExport()

	/**
	 * A user in none of the export groups is refused; non-string entries are skipped.
	 *
	 * @return void
	 */
	public function testNonMemberIsRefusedAndNonStringGroupsAreSkipped(): void {
		$this->signIn(uid: 'bob');
		$calls = [];
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(7);
		$this->container->method('get')->willReturn($this->mapperWith(authorization: ['export' => [12, 'beheer', null]], calls: $calls));
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->expects($this->once())->method('isInGroup')->with('bob', 'beheer')->willReturn(false);

		$this->assertFalse($this->gate()->mayExport(schema: 'gebruik'));
	}//end testNonMemberIsRefusedAndNonStringGroupsAreSkipped()

	/**
	 * An empty export list refuses everyone but an admin.
	 *
	 * @return void
	 */
	public function testEmptyExportListRefusesNonAdmin(): void {
		$this->signIn(uid: 'bob');
		$calls = [];
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(7);
		$this->container->method('get')->willReturn($this->mapperWith(authorization: ['export' => []], calls: $calls));
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->gate()->mayExport(schema: 'gebruik'));
	}//end testEmptyExportListRefusesNonAdmin()

	/**
	 * An admin may export whatever the export groups say, without a group lookup.
	 *
	 * @return void
	 */
	public function testAdminMayAlwaysExport(): void {
		$this->signIn(uid: 'admin');
		$calls = [];
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(7);
		$this->container->method('get')->willReturn($this->mapperWith(authorization: ['export' => []], calls: $calls));
		$this->groupManager->expects($this->once())->method('isAdmin')->with('admin')->willReturn(true);
		$this->groupManager->expects($this->never())->method('isInGroup');

		$this->assertTrue($this->gate()->mayExport(schema: 'gebruik'));
	}//end testAdminMayAlwaysExport()

	/**
	 * A schema that cannot be read refuses the export (fails closed) and logs a warning.
	 *
	 * @return void
	 */
	public function testUnreadableSchemaFailsClosed(): void {
		$this->signIn(uid: 'admin');
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(7);
		$this->container->method('get')->willThrowException(new RuntimeException('OpenRegister is gone'));
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('The schema could not be read'), $this->callback(
				static fn (array $context): bool => $context['msg'] === 'OpenRegister is gone' && $context['schema'] === 'gebruik'
			));

		$this->assertFalse($this->gate()->mayExport(schema: 'gebruik'));
	}//end testUnreadableSchemaFailsClosed()
}//end class
