<?php

/**
 * Golden tests for GebruikController role resolution and its refusal bodies.
 *
 * Pins the full role-flag array per group (allowed and refused), the
 * deelnemer endpoint's missing-organisation refusal and the empty-result
 * envelope before the endpoints are split into named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\GebruikController;
use OCA\Stackiq\Service\GebruikService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for GebruikController.
 */
class GebruikControllerRolesGoldenTest extends TestCase {

	/**
	 * Build a controller whose user is in $groups and belongs to $orgUuid.
	 *
	 * @param array<int,string> $groups  Group ids of the user
	 * @param string            $orgUuid Organisation on the account
	 *
	 * @return GebruikController
	 */
	private function controller(array $groups, string $orgUuid): GebruikController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['participants' => ['spoofed']]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupMocks = [];
		foreach ($groups as $gid) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$groupMocks[] = $group;
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn($groupMocks);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with('alice', 'core', 'organisation')->willReturn($orgUuid);

		return new GebruikController('stackiq', $request, $session, $groupManager, $config, $this->createMock(GebruikService::class));

	}//end controller()

	/**
	 * Group sets and the exact role array each resolves to.
	 *
	 * @return array<string, array{0: array<int,string>, 1: array}>
	 */
	public static function roleCases(): array {
		$none = ['isAdmin' => false, 'isBeheerder' => false, 'isAanbod' => false, 'isAmbtenaar' => false];
		return [
			'admin' => [['admin'], ['isAdmin' => true] + $none + ['hasAccess' => true, 'orgUuid' => 'org-1']],
			'gebruik-beheerder' => [['gebruik-beheerder'], array_replace($none, ['isBeheerder' => true]) + ['hasAccess' => true, 'orgUuid' => 'org-1']],
			'aanbod-beheerder' => [['aanbod-beheerder'], array_replace($none, ['isAanbod' => true]) + ['hasAccess' => true, 'orgUuid' => 'org-1']],
			'ambtenaar' => [['ambtenaar'], array_replace($none, ['isAmbtenaar' => true]) + ['hasAccess' => true, 'orgUuid' => 'org-1']],
			'all' => [['ambtenaar', 'aanbod-beheerder', 'gebruik-beheerder', 'admin'], ['isAdmin' => true, 'isBeheerder' => true, 'isAanbod' => true, 'isAmbtenaar' => true, 'hasAccess' => true, 'orgUuid' => 'org-1']],
			'unrelated (refused)' => [['users', 'Admin'], $none + ['hasAccess' => false, 'orgUuid' => 'org-1']],
			'no groups (refused)' => [[], $none + ['hasAccess' => false, 'orgUuid' => 'org-1']],
		];

	}//end roleCases()

	/**
	 * Each group set resolves to exactly this array, in this key order.
	 *
	 * @param array<int,string> $groups   The groups
	 * @param array             $expected The role array
	 *
	 * @return void
	 *
	 * @dataProvider roleCases
	 */
	public function testResolveUserRolesGolden(array $groups, array $expected): void {
		$controller = $this->controller($groups, 'org-1');
		$method     = new \ReflectionMethod($controller, 'resolveUserRoles');
		$method->setAccessible(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->assertSame($expected, $method->invoke($controller, $user));

	}//end testResolveUserRolesGolden()

	/**
	 * A deelnemer without an organisation is refused with 403.
	 *
	 * @return void
	 */
	public function testDeelnemerWithoutOrganisationIsRefused(): void {
		$response = $this->controller(['admin'], '')->getGebruikenForDeelnemer();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'No organisation is set for this account'], $response->getData());

	}//end testDeelnemerWithoutOrganisationIsRefused()

	/**
	 * A refused getGebruiken answers with exactly this empty envelope.
	 *
	 * @return void
	 */
	public function testRefusedGetGebruikenEnvelopeGolden(): void {
		$response = $this->controller(['users'], 'org-1')->getGebruiken();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'results' => [],
				'total' => 0,
				'page' => 1,
				'pages' => 0,
				'limit' => 1000,
				'offset' => 0,
				'facets' => [],
				'@self' => [
					'source' => 'database',
					'query' => [],
					'rbac' => false,
					'multi' => false,
					'published' => false,
					'deleted' => false,
				],
			],
			$response->getData()
		);

	}//end testRefusedGetGebruikenEnvelopeGolden()
}//end class
