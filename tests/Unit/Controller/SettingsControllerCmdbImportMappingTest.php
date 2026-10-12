<?php

/**
 * Tests for the read-only mapping endpoint of the CMDB import:
 * auth posture, the route, the flat answer from the shipped files, and the
 * translation of a broken pack, a missing validator and an unexpected error.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/CmdbTestSupport.php';

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * GET /api/settings/cmdb-import/mapping.
 */
class SettingsControllerCmdbImportMappingTest extends TestCase {
	/**
	 * Nextcloud's annotation regex (ControllerMethodReflector), as AdminAuthPostureTest uses it.
	 */
	private const ANNOTATION = '/^\h+\*\h+@(?P<annotation>[A-Z]\w+)((?P<parameter>.*))?$/m';

	/**
	 * Directories made by copyOfShippedDirectory().
	 *
	 * @var array<int, string>
	 */
	private array $directories = [];

	/**
	 * Remove the copied directories.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->directories as $directory) {
			array_map('unlink', glob($directory . '/*.json'));
			rmdir($directory);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A container that knows nothing, so the validator comes from class_exists.
	 *
	 * @return ContainerInterface
	 */
	private function emptyContainer(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		return $container;
	}//end emptyContainer()

	/**
	 * A copy of the shipped profile directory, to break on purpose.
	 *
	 * @return string The directory.
	 */
	private function copyOfShippedDirectory(): string {
		$directory = sys_get_temp_dir() . '/stackiq-cmdb-mapping-' . bin2hex(random_bytes(4));
		mkdir($directory);
		foreach (glob(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/*.json') as $file) {
			copy($file, $directory . '/' . basename($file));
		}

		$this->directories[] = $directory;
		return $directory;
	}//end copyOfShippedDirectory()

	/**
	 * The controller with the given profile, logger and translator.
	 *
	 * @param CmdbImportProfile|null $profile The profile; null lets the controller build the shipped one.
	 * @param LoggerInterface|MockObject|null $logger The logger.
	 * @param IL10N|null $l10n The translator; null answers the English text.
	 *
	 * @return SettingsController
	 */
	private function controller(?CmdbImportProfile $profile, LoggerInterface|MockObject|null $logger = null, ?IL10N $l10n = null): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		return new SettingsController(
			'stackiq',
			$request,
			$this->createMock(IAppConfig::class),
			$this->emptyContainer(),
			$this->createMock(IAppManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SettingsService::class),
			$this->createMock(OrganizationSyncService::class),
			$this->createMock(ArchiMateService::class),
			$this->createMock(ProgressTracker::class),
			$this->createMock(EolSyncService::class),
			($logger ?? $this->createMock(LoggerInterface::class)),
			null,
			$profile,
			$l10n
		);
	}//end controller()

	/**
	 * The route is for Nextcloud admins only, with CSRF, and declares that with a reason.
	 *
	 * No attribute at all is Nextcloud's admin gate; the declaration is the
	 * `@auth admin-only <reason>` tag, as on the import routes. Checked as
	 * attribute and as the annotation Nextcloud's regex reads, so a comment
	 * line that starts with an exemption token fails too.
	 *
	 * @return void
	 */
	public function testTheRouteIsForNextcloudAdminsOnly(): void {
		$reflection = new ReflectionMethod(SettingsController::class, 'getCmdbImportMapping');
		$docblock = (string)$reflection->getDocComment();

		$this->assertSame([], $reflection->getAttributes(), 'getCmdbImportMapping carries no attribute');
		$this->assertMatchesRegularExpression('/^\h+\*\h+@auth admin-only \S.{19,}$/m', $docblock, 'declares @auth admin-only with a reason');

		preg_match_all(self::ANNOTATION, $docblock, $matches);
		$exemptions = [
			'AuthorizedAdminSetting' => AuthorizedAdminSetting::class,
			'NoAdminRequired' => NoAdminRequired::class,
			'NoCSRFRequired' => NoCSRFRequired::class,
			'PublicPage' => PublicPage::class,
		];
		foreach ($exemptions as $annotation => $attribute) {
			$this->assertSame([], $reflection->getAttributes($attribute), $annotation);
			$this->assertNotContains($annotation, $matches['annotation'], $annotation);
		}
	}//end testTheRouteIsForNextcloudAdminsOnly()

	/**
	 * The route keeps its path and verb.
	 *
	 * @return void
	 */
	public function testTheRouteIsRegistered(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], null, 'name');
		$this->assertSame(
			['name' => 'settings#getCmdbImportMapping', 'url' => '/api/settings/cmdb-import/mapping', 'verb' => 'GET'],
			$byName['settings#getCmdbImportMapping']
		);
	}//end testTheRouteIsRegistered()

	/**
	 * The shipped files are answered flat: the profile and the five packs the import runs, in TARGETS order.
	 *
	 * @return void
	 */
	public function testTheShippedMappingIsAnsweredFlat(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: $this->emptyContainer());

		$response = $this->controller(profile: $profile)->getCmdbImportMapping();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['profile', 'packs'], array_keys($data), 'flat envelope, no success or message key');

		$this->assertSame(['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB'], array_column($data['profile']['sheets'], 'name'));
		$this->assertSame(['Beheer' => 'Beheer geregeld: nee'], $data['profile']['sheets'][0]['constants']);
		$this->assertSame('APPID', $data['profile']['keyColumn']);
		$this->assertSame('Applicatie Naam', $data['profile']['nameColumn']);
		$this->assertSame(['APPID', 'Applicatie Naam'], $data['profile']['requiredColumns']);
		$this->assertSame(['Datum', 'Referentie datum wijziging', 'End-of-Life Functioneel'], $data['profile']['dateColumns']);
		// The modes the shipped profile declares, read from the file so the
		// assertion follows the profile instead of pinning one release's list.
		$shipped = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/cmdb-import/topdesk-profile.json'), true);
		$this->assertSame($shipped['missingRecords'], $data['profile']['missingRecords']);
		$this->assertSame('topdesk-profile.json', $data['profile']['profileFile']);

		$this->assertSame(CmdbImportProfile::TARGETS, array_column($data['packs'], 'target'));
		$this->assertCount(5, $data['packs']);
		$this->assertSame(
			['stackiq-topdesk-module', 'stackiq-topdesk-manufacturer', 'stackiq-topdesk-municipality', 'stackiq-topdesk-usage', 'stackiq-topdesk-business-owner'],
			array_column($data['packs'], 'id')
		);
		$this->assertSame(
			['topdesk-module.json', 'topdesk-manufacturer.json', 'topdesk-municipality.json', 'topdesk-usage.json', 'topdesk-business-owner.json'],
			array_column($data['packs'], 'file')
		);

		// What is shown is what runs: every pack lists as many mappings as the loader hands the engine.
		foreach ($data['packs'] as $pack) {
			$this->assertNotSame('', $pack['name'], $pack['target']);
			$this->assertNotSame('', $pack['version'], $pack['target']);
			$this->assertCount(count($profile->pack(target: $pack['target'])['fieldMappings']), $pack['fieldMappings'], $pack['target']);
		}

		$module = $data['packs'][0]['fieldMappings'][0];
		$this->assertSame(['source' => 'Applicatie Naam', 'target' => 'name', 'required' => true, 'transform' => ['type' => 'trim']], $module);
		$this->assertFalse($data['packs'][0]['fieldMappings'][2]['required'], 'Applicatie Code is optional');

		$status = $data['packs'][3]['fieldMappings'][0];
		$this->assertSame('Applicatie Status', $status['source']);
		$this->assertSame('status', $status['target']);
		$this->assertSame('lookup', $status['transform']['type']);
		$this->assertSame('In production', $status['transform']['map']['In productie']);

		$note = $data['packs'][3]['fieldMappings'][3];
		$this->assertSame('concat', $note['transform']['type']);
		$this->assertSame(['Cluster', 'Applicatie Eigenaar (Afdeling)'], $note['transform']['fields']);
	}//end testTheShippedMappingIsAnsweredFlat()

	/**
	 * A pack the validator refuses is 503 MAPPING_UNAVAILABLE, in the import's envelope, with the loader's reason.
	 *
	 * @return void
	 */
	public function testABrokenPackIs503WithTheReason(): void {
		CmdbTestSupport::loadMigrationPack();
		$directory = $this->copyOfShippedDirectory();
		$pack = json_decode((string)file_get_contents($directory . '/topdesk-usage.json'), true);
		$pack['fieldMappings'][0]['transform'] = ['type' => 'uppercase'];
		file_put_contents($directory . '/topdesk-usage.json', json_encode($pack));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->anything(),
			$this->callback(fn (array $context): bool => $context['error'] === 'MAPPING_UNAVAILABLE' && str_contains($context['reason'], 'topdesk-usage.json'))
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'NL ' . $text);

		$profile = new CmdbImportProfile(container: $this->emptyContainer(), directory: $directory);
		$response = $this->controller(profile: $profile, logger: $logger, l10n: $l10n)->getCmdbImportMapping();
		$data = $response->getData();

		$this->assertSame(503, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('MAPPING_UNAVAILABLE', $data['error']);
		$this->assertSame('NL The import mapping cannot be shown: OpenRegister is missing or a mapping file is invalid.', $data['message']);
		$this->assertStringContainsString('topdesk-usage.json', $data['details']->reason);
		$this->assertStringContainsString('uppercase', $data['details']->reason, 'the validator names the unknown transform');
	}//end testABrokenPackIs503WithTheReason()

	/**
	 * Without OpenRegister's validator the mapping is 503 too, and without a translator the English text is answered.
	 *
	 * @return void
	 */
	public function testAMissingValidatorIs503(): void {
		$profile = new class(container: $this->emptyContainer()) extends CmdbImportProfile {
			public const VALIDATOR_CLASS = 'OCA\OpenRegister\Service\MigrationPack\NoSuchValidator';
		};

		$response = $this->controller(profile: $profile)->getCmdbImportMapping();
		$data = $response->getData();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('MAPPING_UNAVAILABLE', $data['error']);
		$this->assertSame('The import mapping cannot be shown: OpenRegister is missing or a mapping file is invalid.', $data['message']);
		$this->assertSame('OpenRegister PackDefinitionValidator is not available', $data['details']->reason);
	}//end testAMissingValidatorIs503()

	/**
	 * An unexpected error is 500 IMPORT_FAILED with a static message, and the exception is logged.
	 *
	 * @return void
	 */
	public function testAnUnexpectedErrorIs500AndLogged(): void {
		$profile = $this->createMock(CmdbImportProfile::class);
		$profile->method('mappingOverview')->willThrowException(new RuntimeException('disk on fire'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->anything(),
			$this->callback(fn (array $context): bool => ($context['exception'] ?? null) instanceof RuntimeException)
		);

		$response = $this->controller(profile: $profile, logger: $logger)->getCmdbImportMapping();
		$data = $response->getData();

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('IMPORT_FAILED', $data['error']);
		$this->assertStringNotContainsString('disk on fire', $data['message']);
		$this->assertEquals((object)[], $data['details']);
	}//end testAnUnexpectedErrorIs500AndLogged()
}//end class
