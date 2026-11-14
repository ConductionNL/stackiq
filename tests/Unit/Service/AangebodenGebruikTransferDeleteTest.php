<?php

/**
 * Unit tests for AangebodenGebruikService::setGebruikSelfToActiveOrg() and deleteGebruikAsAfnemer().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Both operations bypass RBAC and multitenancy in OpenRegister, so the only
 * guard is the service's own check that the active organisation is the
 * consumer or the provider of the usage. These tests pin that guard and
 * what each operation writes once it passes.
 */
final class AangebodenGebruikTransferDeleteTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * Objects find() returns, keyed by "<schema>/<id>".
	 *
	 * @var array<string, ObjectEntityInterface>
	 */
	private array $objects = [];

	/**
	 * Build the service with the given active organisation.
	 *
	 * @param string|null $activeOrg Active organisation uuid, or null for none.
	 * @param array       $config    The voorzieningen config.
	 *
	 * @return AangebodenGebruikService
	 */
	private function service(?string $activeOrg, array $config=['register' => '1', 'gebruik_schema' => '10', 'koppeling_schema' => '11']): AangebodenGebruikService {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?ObjectEntityInterface {
				if (isset($this->objects[$schema . '/' . $id]) === false) {
					throw new \Exception('not found');
				}

				return $this->objects[$schema . '/' . $id];
			}
		);

		$organisations = $this->createMock(OrganisationService::class);
		$userSession = $this->createMock(IUserSession::class);
		if ($activeOrg !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('jan');
			$userSession->method('getUser')->willReturn($user);
			$org = new Organisation();
			$org->setUuid($activeOrg);
			$organisations->method('getActiveOrganisation')->willReturn($org);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\Service\ObjectService', $this->objectService],
				['OCA\OpenRegister\Service\OrganisationService', $organisations],
			]
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getVoorzieningenConfig')->willReturn($config);
		$this->settings->method('getSchemaIdForObjectType')->willReturn(20);

		return new AangebodenGebruikService(
			config: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $this->settings,
			userSession: $userSession
		);

	}//end service()

	/**
	 * Register an object find() can return.
	 *
	 * @param string $schema Schema id the object lives in.
	 * @param string $id     Object id.
	 * @param array  $data   Object data.
	 *
	 * @return void
	 */
	private function object(string $schema, string $id, array $data): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getRegister')->willReturn('1');
		$entity->method('getSchema')->willReturn($schema);
		$this->objects[$schema . '/' . $id] = $entity;

	}//end object()

	/**
	 * The consumer organisation takes the usage over: @self gets the
	 * organisation and the accepting user, registeredBy follows the
	 * organisation's type, and the save goes to the object's own schema.
	 *
	 * @return void
	 */
	public function testTheConsumerTakesTheUsageOver(): void {
		$this->object('10', 'g-1', ['name' => 'Zaaksysteem', 'consumer' => ['id' => 'org-a'], 'provider' => 'org-b']);
		$this->object('20', 'org-a', ['type' => 'Municipality']);
		$service = $this->service('org-a');
		$saved = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true) use (&$saved): ObjectEntityInterface {
				$saved = compact('object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy');
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($object);
				return $entity;
			}
		);

		$result = $service->setGebruikSelfToActiveOrg('g-1');

		$this->assertTrue($result['success']);
		$this->assertSame(['organisation' => 'org-a', 'owner' => 'jan'], $saved['object']['@self']);
		$this->assertSame('Municipality', $saved['object']['registeredBy']);
		$this->assertSame('Zaaksysteem', $saved['object']['name'], 'Unchanged fields are carried forward.');
		$this->assertSame(['1', '10', 'g-1'], [$saved['register'], $saved['schema'], $saved['uuid']]);
		$this->assertSame($saved['object'], $result['usage']);

	}//end testTheConsumerTakesTheUsageOver()

	/**
	 * A provider stored as a plain uuid may also take it over; an unknown
	 * organisation type leaves registeredBy alone. The object is found in the
	 * koppeling schema after the gebruik schema misses.
	 *
	 * @return void
	 */
	public function testTheProviderTakesAnIntegrationOver(): void {
		$this->object('11', 'k-1', ['provider' => 'org-b', 'registeredBy' => 'Supplier']);
		$this->object('20', 'org-b', ['type' => 'Unknown']);
		$service = $this->service('org-b');
		$this->objectService->expects($this->once())->method('saveObject')
			->with($this->callback(fn (array $object): bool => $object['registeredBy'] === 'Supplier' && $object['@self']['organisation'] === 'org-b'))
			->willReturn($this->createMock(ObjectEntityInterface::class));

		$this->assertTrue($service->setGebruikSelfToActiveOrg('k-1')['success']);

	}//end testTheProviderTakesAnIntegrationOver()

	/**
	 * An organisation that is neither party is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function testAThirdOrganisationCannotTakeTheUsageOver(): void {
		$this->object('10', 'g-1', ['consumer' => 'org-a', 'provider' => ['id' => 'org-b']]);
		$service = $this->service('org-c');
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $service->setGebruikSelfToActiveOrg('g-1');

		$this->assertFalse($result['success']);
		$this->assertSame('Operation not allowed: active organization is not the afnemer or aanbieder', $result['error']);
		$this->assertSame('org-a', $result['debug']['resolved_afnemer_id']);
		$this->assertSame('org-b', $result['debug']['resolved_aanbieder_id']);

	}//end testAThirdOrganisationCannotTakeTheUsageOver()

	/**
	 * The early refusals: no id, no active organisation, unknown object, and
	 * no register configured.
	 *
	 * @return void
	 */
	public function testTransferRefusals(): void {
		$this->assertSame('Gebruik ID is required', $this->service('org-a')->setGebruikSelfToActiveOrg('')['error']);
		$this->assertSame('No current organization available', $this->service(null)->setGebruikSelfToActiveOrg('g-1')['error']);
		$this->assertSame('Gebruik object not found', $this->service('org-a')->setGebruikSelfToActiveOrg('missing')['error']);
		$this->assertSame('Gebruik object not found', $this->service('org-a', [])->setGebruikSelfToActiveOrg('g-1')['error']);

	}//end testTransferRefusals()

	/**
	 * A failing save is reported, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsReported(): void {
		$this->object('10', 'g-1', ['consumer' => 'org-a']);
		$service = $this->service('org-a');
		$this->objectService->method('saveObject')->willThrowException(new \Exception('locked'));

		$this->assertSame('Failed to update usage: locked', $service->setGebruikSelfToActiveOrg('g-1')['error']);

	}//end testAFailingSaveIsReported()

	/**
	 * The consumer deletes the usage in the object's own register and
	 * schema, with RBAC and multitenancy off.
	 *
	 * @return void
	 */
	public function testTheConsumerDeletesTheUsage(): void {
		$this->object('10', 'g-1', ['consumer' => ['id' => 'org-a']]);
		$service = $this->service('org-a');
		$this->objectService->expects($this->once())->method('setRegister')->with('1')->willReturnSelf();
		$this->objectService->expects($this->once())->method('setSchema')->with('10')->willReturnSelf();
		$this->objectService->expects($this->once())->method('deleteObject')
			->with(uuid: 'g-1', _rbac: false, _multitenancy: false)
			->willReturn(true);

		$result = $service->deleteGebruikAsAfnemer('g-1');

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Gebruik object deleted successfully',
				'deleted' => true,
				'gebruik_id' => 'g-1',
				'organisation' => 'org-a',
			],
			$result
		);

	}//end testTheConsumerDeletesTheUsage()

	/**
	 * A third organisation cannot delete; nothing reaches deleteObject.
	 *
	 * @return void
	 */
	public function testAThirdOrganisationCannotDelete(): void {
		$this->object('10', 'g-1', ['consumer' => 'org-a', 'provider' => 'org-b']);
		$service = $this->service('org-c');
		$this->objectService->expects($this->never())->method('deleteObject');

		$result = $service->deleteGebruikAsAfnemer('g-1');

		$this->assertFalse($result['deleted']);
		$this->assertSame('org-c', $result['debug']['current_org']);

	}//end testAThirdOrganisationCannotDelete()

	/**
	 * Delete refusals and a failing delete.
	 *
	 * @return void
	 */
	public function testDeleteRefusalsAndFailure(): void {
		$this->assertSame('Gebruik ID is required', $this->service('org-a')->deleteGebruikAsAfnemer('')['error']);
		$this->assertSame('No current organization available', $this->service(null)->deleteGebruikAsAfnemer('g-1')['error']);
		$this->assertSame('Gebruik object not found', $this->service('org-a')->deleteGebruikAsAfnemer('missing')['error']);

		$this->object('11', 'k-1', ['provider' => 'org-b']);
		$service = $this->service('org-b');
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('deleteObject')->willThrowException(new \Exception('in use'));

		$this->assertSame('Failed to delete usage: in use', $service->deleteGebruikAsAfnemer('k-1')['error']);

	}//end testDeleteRefusalsAndFailure()
}//end class
