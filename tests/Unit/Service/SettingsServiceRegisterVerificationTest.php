<?php

/**
 * Unit tests for the register-import-reliability post-import verification
 * pass, the status-payload surface, and the removal of the broken
 * app-semver-vs-content-version pre-gate in SettingsService.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-read-and-persist-every-configuration-domain-req-002
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Covers verifyRegisterAgainstEffectiveConfig(), getRegisterVerificationStatus(),
 * and shouldLoadSettings() — the three pieces that together turn a no-op or
 * silently-blocked register import into a visible, diagnosable state.
 */
final class SettingsServiceRegisterVerificationTest extends TestCase {

	/**
	 * Build a SettingsService with a real (mocked-dependency) constructor
	 * so verifyRegisterAgainstEffectiveConfig()'s container->get() call
	 * resolves, while getSchemaIdForObjectType() is overridden so the test
	 * does not need to also fake the voorzieningen/AMEF config lookups it
	 * would otherwise read through IAppConfig.
	 *
	 * @param SchemaMapper|MockObject $schemaMapper Mock returned for SchemaMapper::class.
	 * @param int|null $resolvedObjectType Value getSchemaIdForObjectType() returns.
	 *
	 * @return array{0: SettingsService, 1: LoggerInterface|MockObject}
	 */
	private function makeServiceForVerification($schemaMapper, ?int $resolvedObjectType): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($schemaMapper) {
				if ($id === SchemaMapper::class) {
					return $schemaMapper;
				}

				throw new \RuntimeException("Unexpected container->get({$id}) in test");
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$service = $this->getMockBuilder(SettingsService::class)
			->setConstructorArgs(
				[
					$this->createMock(IAppConfig::class),
					$this->createMock(IRequest::class),
					$container,
					$this->createMock(IAppManager::class),
					$logger,
					$this->createMock(IGroupManager::class),
					$this->createMock(IL10N::class),
				]
			)
			->onlyMethods(['getSchemaIdForObjectType'])
			->getMock();

		$service->method('getSchemaIdForObjectType')->willReturn($resolvedObjectType);

		return [$service, $logger];
	}//end makeServiceForVerification()

	/**
	 * Invoke the private verifyRegisterAgainstEffectiveConfig() method.
	 *
	 * @param SettingsService $service The service under test.
	 * @param array<string, mixed> $effectiveRegister The merged register data.
	 *
	 * @return array{ok: bool, missingSchemas: array<int, string>, unresolvedObjectTypes: array<int, string>}
	 */
	private function verify(SettingsService $service, array $effectiveRegister): array {
		$m = new ReflectionMethod($service, 'verifyRegisterAgainstEffectiveConfig');
		$m->setAccessible(true);
		return $m->invoke($service, $effectiveRegister);
	}//end verify()

	/**
	 * When every schema slug resolves and both tracked object types
	 * resolve to a schema id, verification reports ok with empty misses.
	 *
	 * @return void
	 */
	public function testAllSchemasAndObjectTypesResolveReportsOk(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturn([new \stdClass()]);

		[$service] = $this->makeServiceForVerification($schemaMapper, resolvedObjectType: 42);

		$result = $this->verify(
			$service,
			['components' => ['schemas' => ['module' => [], 'usage' => []]]]
		);

		$this->assertTrue($result['ok']);
		$this->assertSame([], $result['missingSchemas']);
		$this->assertSame([], $result['unresolvedObjectTypes']);
	}//end testAllSchemasAndObjectTypesResolveReportsOk()

	/**
	 * A schema slug present in the effective register that does not
	 * resolve in OpenRegister is reported as a missing schema and a
	 * WARNING is logged — this is the exact "eight merged features were
	 * dead on an upgraded instance" symptom made visible.
	 *
	 * @return void
	 */
	public function testUnresolvedSchemaSlugIsReportedAndLogged(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturn([]);

		[$service, $logger] = $this->makeServiceForVerification($schemaMapper, resolvedObjectType: 42);

		$logger->expects($this->atLeastOnce())->method('warning');

		$result = $this->verify(
			$service,
			['components' => ['schemas' => ['bioMeasure' => []]]]
		);

		$this->assertFalse($result['ok']);
		$this->assertSame(['bioMeasure'], $result['missingSchemas']);
	}//end testUnresolvedSchemaSlugIsReportedAndLogged()

	/**
	 * A tracked object type (organization/contactpersoon) that fails to
	 * resolve to a schema id after import is reported as unresolved.
	 *
	 * @return void
	 */
	public function testUnresolvedObjectTypeIsReported(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturn([new \stdClass()]);

		[$service] = $this->makeServiceForVerification($schemaMapper, resolvedObjectType: null);

		$result = $this->verify($service, ['components' => ['schemas' => ['module' => []]]]);

		$this->assertFalse($result['ok']);
		$this->assertSame(['organization', 'contactPerson'], $result['unresolvedObjectTypes']);
	}//end testUnresolvedObjectTypeIsReported()

	/**
	 * An effective register with no schemas at all is a no-op verification
	 * that reports ok (nothing to check), not a false failure.
	 *
	 * @return void
	 */
	public function testEmptySchemasReportsOkWithoutTouchingSchemaMapper(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->expects($this->never())->method('findBySlug');

		[$service] = $this->makeServiceForVerification($schemaMapper, resolvedObjectType: 42);

		$result = $this->verify($service, ['components' => ['schemas' => []]]);

		$this->assertTrue($result['ok']);
	}//end testEmptySchemasReportsOkWithoutTouchingSchemaMapper()

	/**
	 * getRegisterVerificationStatus() with nothing persisted yet reports
	 * an "unchecked" status rather than a false "ok" that looks the same
	 * as a verified-clean import.
	 *
	 * @return void
	 */
	public function testGetRegisterVerificationStatusReportsUncheckedWhenNothingPersisted(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$service = new SettingsService(
			$config,
			$this->createMock(IRequest::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IL10N::class)
		);

		$reflection = new ReflectionMethod($service, 'getRegisterVerificationStatus');
		$reflection->setAccessible(true);
		$status = $reflection->invoke($service);

		$this->assertTrue($status['ok']);
		$this->assertFalse($status['checked']);
		$this->assertNull($status['message']);
	}//end testGetRegisterVerificationStatusReportsUncheckedWhenNothingPersisted()

	/**
	 * getRegisterVerificationStatus() surfaces a persisted mismatch with a
	 * translated message, so a no-op import is visible in the settings
	 * status payload rather than looking identical to full success.
	 *
	 * @return void
	 */
	public function testGetRegisterVerificationStatusSurfacesPersistedMismatch(): void {
		$persisted = json_encode(
			[
				'ok' => false,
				'missingSchemas' => ['bioMeasure'],
				'unresolvedObjectTypes' => [],
			]
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($persisted);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$service = new SettingsService(
			$config,
			$this->createMock(IRequest::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class),
			$l10n
		);

		$reflection = new ReflectionMethod($service, 'getRegisterVerificationStatus');
		$reflection->setAccessible(true);
		$status = $reflection->invoke($service);

		$this->assertFalse($status['ok']);
		$this->assertTrue($status['checked']);
		$this->assertSame(['bioMeasure'], $status['missingSchemas']);
		$this->assertNotNull($status['message']);
	}//end testGetRegisterVerificationStatusSurfacesPersistedMismatch()

	/**
	 * shouldLoadSettings() always returns true (register-import-reliability):
	 * comparing this app's own semver against the register-content version
	 * ConfigurationService::getConfiguredAppVersion() returns is comparing
	 * two unrelated versioning schemes and could permanently block
	 * loadSettings() from ever running again. The only correct gate is
	 * importFromApp()'s own content-derived version comparison.
	 *
	 * @return void
	 */
	public function testShouldLoadSettingsAlwaysReturnsTrue(): void {
		$service = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();

		$reflection = new ReflectionMethod($service, 'shouldLoadSettings');
		$reflection->setAccessible(true);

		$this->assertTrue($reflection->invoke($service));
	}//end testShouldLoadSettingsAlwaysReturnsTrue()
}//end class
