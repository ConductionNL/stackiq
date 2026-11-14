<?php

/**
 * Unit tests for who may export an organisation as ArchiMate
 * (insight-exports-and-custom-reports REQ-CER-003): a Nextcloud admin, a
 * user in `admin` or `ambtenaar`, or a member exporting their own active
 * organisation; anyone else is refused before the export runs.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
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

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Kernel\Contracts\OrganisationReportAccessInterface;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganisationReportAccess;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The organisation ArchiMate export's scope guard.
 *
 * @covers \OCA\Stackiq\Controller\SettingsController
 * @uses   \OCA\Stackiq\Service\OrganisationReportAccess
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-003-a-member-of-an-organisation-must-be-able-to-export-that-organisation-as-archimate-from-its-detail-page-and-only-that-organisation
 */
final class SettingsControllerOrgExportTest extends TestCase {

	/**
	 * The export service.
	 *
	 * @var ArchiMateService&MockObject
	 */
	private ArchiMateService&MockObject $archiMate;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->archiMate = $this->createMock(ArchiMateService::class);
	}//end setUp()

	/**
	 * The controller for a caller with groups and an active organisation, over the real access rule.
	 *
	 * @param list<string>                              $groups       The caller's groups.
	 * @param string                                    $organisation The caller's active organisation.
	 * @param bool                                      $ncAdmin      Whether Nextcloud reports the caller as admin.
	 * @param OrganisationReportAccessInterface|null|false $access    The rule to inject; false to build the real one.
	 * @param ContainerInterface|null                   $container    The container.
	 *
	 * @return SettingsController
	 */
	private function controller(
		array $groups,
		string $organisation,
		bool $ncAdmin = false,
		OrganisationReportAccessInterface|null|false $access = false,
		?ContainerInterface $container = null,
	): SettingsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($ncAdmin);
		$groupManager->method('getUserGroups')->willReturn(array_map(function (string $gid): IGroup {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			return $group;
		}, $groups));

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $appName, string $key): string => ($userId === 'anna' && $appName === 'core' && $key === 'organisation') ? $organisation : ''
		);

		if ($access === false) {
			$access = new OrganisationReportAccess($groupManager, $config);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $default);

		return new SettingsController(
			'stackiq',
			$request,
			$this->createMock(IAppConfig::class),
			$container ?? $this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$groupManager,
			$userSession,
			$this->createMock(SettingsService::class),
			$this->createMock(CronjobSettings::class),
			$this->createMock(OrganizationSyncService::class),
			$this->archiMate,
			$this->createMock(RoleGroupSettings::class),
			$this->createMock(ProgressTracker::class),
			$this->createMock(ArchiMateImportControl::class),
			$this->createMock(EolSyncService::class),
			$this->createMock(EmailSettings::class),
			$this->createMock(LoggerInterface::class),
			null,
			null,
			null,
			null,
			$access
		);
	}//end controller()

	/**
	 * The export succeeds for the organisation given.
	 *
	 * @param string $organisation The expected organisation.
	 *
	 * @return void
	 */
	private function expectExportOf(string $organisation): void {
		$this->archiMate->expects($this->once())->method('exportOrgArchiMate')
			->with($organisation, $this->anything())
			->willReturn(['success' => true, 'xml' => '<model/>', 'file_name' => 'org.xml']);
	}//end expectExportOf()

	/**
	 * A member exports their own active organisation.
	 *
	 * @return void
	 */
	public function testAMemberExportsTheirOwnOrganisation(): void {
		$this->expectExportOf('org-a');

		$response = $this->controller(groups: ['medewerkers'], organisation: 'org-a')->exportOrgArchiMate('org-a');

		$this->assertNotInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
	}//end testAMemberExportsTheirOwnOrganisation()

	/**
	 * A member of organisation A is refused organisation B, before the export runs.
	 *
	 * @return void
	 */
	public function testAMemberIsRefusedAnotherOrganisationBeforeTheExportRuns(): void {
		$this->archiMate->expects($this->never())->method('exportOrgArchiMate');

		$response = $this->controller(groups: ['medewerkers'], organisation: 'org-a')->exportOrgArchiMate('org-b');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'Not authorised for this organisation'], $response->getData());
	}//end testAMemberIsRefusedAnotherOrganisationBeforeTheExportRuns()

	/**
	 * A user without an active organisation is refused.
	 *
	 * @return void
	 */
	public function testAUserWithoutAnOrganisationIsRefused(): void {
		$this->archiMate->expects($this->never())->method('exportOrgArchiMate');

		$this->assertSame(403, $this->controller(groups: [], organisation: '')->exportOrgArchiMate('org-a')->getStatus());
	}//end testAUserWithoutAnOrganisationIsRefused()

	/**
	 * `admin` and `ambtenaar` may export any organisation, as for the portfolio report.
	 *
	 * @return void
	 */
	public function testAdminAndAmbtenaarExportAnyOrganisation(): void {
		$this->archiMate->expects($this->exactly(2))->method('exportOrgArchiMate')
			->with('org-b', $this->anything())
			->willReturn(['success' => true, 'xml' => '<model/>', 'file_name' => 'org.xml']);

		$this->assertSame(200, $this->controller(groups: ['ambtenaar'], organisation: 'org-a')->exportOrgArchiMate('org-b')->getStatus());
		$this->assertSame(200, $this->controller(groups: ['admin'], organisation: '')->exportOrgArchiMate('org-b')->getStatus());
	}//end testAdminAndAmbtenaarExportAnyOrganisation()

	/**
	 * A Nextcloud admin passes without consulting the rule.
	 *
	 * @return void
	 */
	public function testANextcloudAdminPassesWithoutTheRule(): void {
		$this->expectExportOf('org-b');
		$access = $this->createMock(OrganisationReportAccessInterface::class);
		$access->expects($this->never())->method('mayRead');

		$this->assertSame(200, $this->controller(groups: [], organisation: '', ncAdmin: true, access: $access)->exportOrgArchiMate('org-b')->getStatus());
	}//end testANextcloudAdminPassesWithoutTheRule()

	/**
	 * Without an injected rule the container's is used; when it cannot be had, the export is refused.
	 *
	 * @return void
	 */
	public function testTheRuleComesFromTheContainerAndFailsClosed(): void {
		$rule = $this->createMock(OrganisationReportAccessInterface::class);
		$rule->expects($this->once())->method('mayRead')->with($this->isInstanceOf(IUser::class), 'org-a')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(OrganisationReportAccessInterface::class)->willReturn($rule);
		$this->expectExportOf('org-a');
		$this->assertSame(200, $this->controller(groups: [], organisation: '', access: null, container: $container)->exportOrgArchiMate('org-a')->getStatus());

		$broken = $this->createMock(ContainerInterface::class);
		$broken->method('get')->willThrowException(new RuntimeException('not registered'));
		$this->assertSame(403, $this->controller(groups: [], organisation: '', access: null, container: $broken)->exportOrgArchiMate('org-a')->getStatus());
	}//end testTheRuleComesFromTheContainerAndFailsClosed()

	/**
	 * An empty organisation is refused even for a member, and the full export stays admin-only.
	 *
	 * @return void
	 */
	public function testAnEmptyOrganisationAndTheFullExport(): void {
		$this->archiMate->expects($this->never())->method('exportOrgArchiMate');
		$this->archiMate->expects($this->never())->method('exportToArchiMate');

		$controller = $this->controller(groups: ['ambtenaar'], organisation: 'org-a');
		$this->assertSame(403, $controller->exportOrgArchiMate('')->getStatus());
		$full = $controller->exportArchiMate();
		$this->assertSame(403, $full->getStatus());
		$this->assertSame(['message' => 'Admin or organisation-admin privileges required'], $full->getData());
	}//end testAnEmptyOrganisationAndTheFullExport()
}//end class
