<?php

/**
 * Unit tests for AanbodService::acceptAanbod and AanbodService::denyAanbod.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\AanbodService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that accepting or denying an aanbod is limited to its afnemer or aanbieder.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\AanbodService
 * @uses   \OCA\Stackiq\Service\OpenRegisterLookup
 */
class AanbodServiceAcceptDenyTest extends TestCase {

	private const ACTING_ORG = 'org-acting';

	private ObjectServiceInterface&MockObject $objectService;

	private RegisterConfigReaderInterface&MockObject $settings;

	private IUserSession&MockObject $userSession;

	private ?string $activeOrg = self::ACTING_ORG;

	private bool $openRegisterInstalled = true;

	private bool $signedIn = true;

	private AanbodService $service;

	/**
	 * Set up mocks and the service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->settings = $this->createMock(RegisterConfigReaderInterface::class);
		$this->settings->method('getVoorzieningenConfig')->willReturn(
			[
				'register' => 2,
				'gebruik_schema' => 10,
				'koppeling_schema' => 11,
				'module_schema' => null,
				'dienst_schema' => 13,
			]
		);
		$this->settings->method('getSchemaIdForObjectType')->with('organization')->willReturn(20);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn === true ? $user : null);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturnCallback(
			fn (): array => $this->openRegisterInstalled === true ? ['openregister'] : []
		);

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnCallback(
			function (): ?Organisation {
				if ($this->activeOrg === null) {
					return null;
				}

				$organisation = new Organisation();
				$organisation->setUuid($this->activeOrg);
				return $organisation;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
				'OCA\OpenRegister\Service\OrganisationService' => $organisations,
			}
		);

		$this->service = new AanbodService(
			$this->createMock(IAppConfig::class),
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class),
			$this->settings,
			$this->userSession
		);

	}//end setUp()

	/**
	 * An aanbod entity mock.
	 *
	 * @param array $data The aanbod data
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function aanbod(array $data): ObjectEntity&MockObject {
		$aanbod = $this->createMock(ObjectEntity::class);
		$aanbod->method('getObject')->willReturn($data);
		$aanbod->method('getRegister')->willReturn('2');
		$aanbod->method('getSchema')->willReturn('11');
		return $aanbod;

	}//end aanbod()

	/**
	 * Make find() return the aanbod in the koppeling schema (after a miss and a
	 * throw in the gebruik schema) and the organisation with the given type.
	 *
	 * @param ObjectEntity|null $aanbod  The aanbod to find, null for nowhere
	 * @param string|null       $orgType The acting organisation's type
	 *
	 * @return void
	 */
	private function findReturns(?ObjectEntity $aanbod, ?string $orgType=null): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($aanbod, $orgType) {
				if ($id === self::ACTING_ORG) {
					$this->assertSame([2, 20], [$register, $schema]);
					$organisation = $this->createMock(ObjectEntity::class);
					$organisation->method('getObject')->willReturn(['type' => $orgType]);
					return $organisation;
				}

				$this->assertSame('aanbod-1', $id);
				$this->assertSame(2, $register);
				if ($schema === 10) {
					throw new RuntimeException('not in this schema');
				}

				if ($schema === 11) {
					return $aanbod;
				}

				return null;
			}
		);

	}//end findReturns()

	/**
	 * The afnemer accepts: @self gets the organisation and owner, registeredBy
	 * follows the organisation type, and the saved object is returned.
	 *
	 * @return void
	 */
	public function testConsumerAcceptsAndTakesOwnership(): void {
		$this->findReturns($this->aanbod(['naam' => 'X', 'consumer' => ['id' => self::ACTING_ORG], 'provider' => 'org-other']), 'Supplier');

		$saved = $this->createMock(ObjectEntity::class);
		$saved->method('getObject')->willReturn(['saved' => true]);
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				[
					'naam' => 'X',
					'consumer' => ['id' => self::ACTING_ORG],
					'provider' => 'org-other',
					'@self' => ['organisation' => self::ACTING_ORG, 'owner' => 'alice'],
					'registeredBy' => 'Supplier',
				],
				[],
				'2',
				'11',
				'aanbod-1',
				false,
				false
			)
			->willReturn($saved);

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Aanbod object accepted successfully',
				'aanbod' => ['saved' => true],
				'updated_fields' => ['@self.organisation', '@self.owner'],
			],
			$this->service->acceptAanbod('aanbod-1')
		);

	}//end testConsumerAcceptsAndTakesOwnership()

	/**
	 * The aanbieder (as a plain UUID) may accept; an unknown organisation type
	 * leaves registeredBy unset.
	 *
	 * @return void
	 */
	public function testProviderAcceptsWithoutKnownType(): void {
		$this->findReturns($this->aanbod(['provider' => self::ACTING_ORG]), 'Alien');

		$saved = $this->createMock(ObjectEntity::class);
		$saved->method('getObject')->willReturn([]);
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['provider' => self::ACTING_ORG, '@self' => ['organisation' => self::ACTING_ORG, 'owner' => 'alice']])
			->willReturn($saved);

		$this->assertTrue($this->service->acceptAanbod('aanbod-1')['success']);

	}//end testProviderAcceptsWithoutKnownType()

	/**
	 * Another organisation may not accept, and nothing is saved.
	 *
	 * @return void
	 */
	public function testOtherOrganisationMayNotAccept(): void {
		$this->findReturns($this->aanbod(['consumer' => 'org-a', 'provider' => ['id' => 'org-b']]));
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->service->acceptAanbod('aanbod-1');

		$this->assertFalse($result['success']);
		$this->assertSame('Operation not allowed: active organization is not the afnemer or aanbieder', $result['error']);
		$this->assertNull($result['aanbod']);
		$this->assertSame(
			[
				'afnemer_in_object' => 'org-a',
				'resolved_afnemer_id' => 'org-a',
				'aanbieder_in_object' => ['id' => 'org-b'],
				'resolved_aanbieder_id' => 'org-b',
				'current_org' => self::ACTING_ORG,
			],
			$result['debug']
		);

	}//end testOtherOrganisationMayNotAccept()

	/**
	 * Without a signed-in user there is no active organisation: accept and deny refuse and write nothing.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsWritten(): void {
		$this->signedIn = false;
		$this->findReturns($this->aanbod(['consumer' => self::ACTING_ORG]));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->assertSame(
			['success' => false, 'error' => 'No current organization available', 'aanbod' => null],
			$this->service->acceptAanbod('aanbod-1')
		);
		$this->assertSame(
			['success' => false, 'error' => 'No current organization available', 'deleted' => false],
			$this->service->denyAanbod('aanbod-1')
		);

	}//end testWithoutAUserNothingIsWritten()

	/**
	 * A refused accept and a refused deny keep their exact shape, key order included.
	 *
	 * @return void
	 */
	public function testNotAllowedResultsKeepTheirShape(): void {
		$this->findReturns($this->aanbod(['consumer' => ['id' => 'org-a']]));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->objectService->expects($this->never())->method('deleteObject');

		$debug = [
			'afnemer_in_object' => ['id' => 'org-a'],
			'resolved_afnemer_id' => 'org-a',
			'aanbieder_in_object' => null,
			'resolved_aanbieder_id' => null,
			'current_org' => self::ACTING_ORG,
		];
		$error = 'Operation not allowed: active organization is not the afnemer or aanbieder';

		$this->assertSame(
			['success' => false, 'error' => $error, 'aanbod' => null, 'debug' => $debug],
			$this->service->acceptAanbod('aanbod-1')
		);
		$this->assertSame(
			['success' => false, 'error' => $error, 'deleted' => false, 'debug' => $debug],
			$this->service->denyAanbod('aanbod-1')
		);

	}//end testNotAllowedResultsKeepTheirShape()

	/**
	 * Accept refuses an empty id, a missing organisation and an unknown aanbod.
	 *
	 * @return void
	 */
	public function testAcceptRefusals(): void {
		$this->findReturns(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(
			['success' => false, 'error' => 'Aanbod ID is required', 'aanbod' => null],
			$this->service->acceptAanbod('')
		);
		$this->assertSame(
			['success' => false, 'error' => 'Aanbod object not found', 'aanbod' => null],
			$this->service->acceptAanbod('aanbod-1')
		);

		$this->activeOrg = null;
		$this->assertSame(
			['success' => false, 'error' => 'No current organization available', 'aanbod' => null],
			$this->service->acceptAanbod('aanbod-1')
		);

	}//end testAcceptRefusals()

	/**
	 * Without OpenRegister accept reports the failure.
	 *
	 * @return void
	 */
	public function testAcceptWithoutOpenRegisterFails(): void {
		$this->openRegisterInstalled = false;

		$this->assertSame(
			['success' => false, 'error' => 'Failed to accept aanbod: OpenRegister app is not installed', 'aanbod' => null],
			$this->service->acceptAanbod('aanbod-1')
		);

	}//end testAcceptWithoutOpenRegisterFails()

	/**
	 * The afnemer denies: the object is deleted in its own register and schema.
	 *
	 * @return void
	 */
	public function testConsumerDeniesAndObjectIsDeleted(): void {
		$this->findReturns($this->aanbod(['consumer' => self::ACTING_ORG]));

		$this->objectService->expects($this->once())->method('setRegister')->with('2')->willReturnSelf();
		$this->objectService->expects($this->once())->method('setSchema')->with('11')->willReturnSelf();
		$this->objectService->expects($this->once())->method('deleteObject')
			->with('aanbod-1', null, null, false, false)
			->willReturn(true);

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Aanbod object denied successfully',
				'deleted' => true,
				'aanbod_id' => 'aanbod-1',
				'organisation' => self::ACTING_ORG,
			],
			$this->service->denyAanbod('aanbod-1')
		);

	}//end testConsumerDeniesAndObjectIsDeleted()

	/**
	 * Another organisation may not deny, and nothing is deleted.
	 *
	 * @return void
	 */
	public function testOtherOrganisationMayNotDeny(): void {
		$this->findReturns($this->aanbod(['consumer' => ['name' => 'no id'], 'provider' => '']));
		$this->objectService->expects($this->never())->method('deleteObject');

		$result = $this->service->denyAanbod('aanbod-1');

		$this->assertFalse($result['success']);
		$this->assertFalse($result['deleted']);
		$this->assertSame('Operation not allowed: active organization is not the afnemer or aanbieder', $result['error']);
		$this->assertNull($result['debug']['resolved_afnemer_id']);
		$this->assertNull($result['debug']['resolved_aanbieder_id']);

	}//end testOtherOrganisationMayNotDeny()

	/**
	 * Deny refuses an empty id, an unknown aanbod and a missing organisation.
	 *
	 * @return void
	 */
	public function testDenyRefusals(): void {
		$this->findReturns(null);

		$this->assertSame(
			['success' => false, 'error' => 'Aanbod ID is required', 'deleted' => false],
			$this->service->denyAanbod('')
		);
		$this->assertSame(
			['success' => false, 'error' => 'Aanbod object not found', 'deleted' => false],
			$this->service->denyAanbod('aanbod-1')
		);

		$this->activeOrg = null;
		$this->assertSame(
			['success' => false, 'error' => 'No current organization available', 'deleted' => false],
			$this->service->denyAanbod('aanbod-1')
		);

	}//end testDenyRefusals()

	/**
	 * A failing delete is reported, not thrown.
	 *
	 * @return void
	 */
	public function testFailingDeleteIsReported(): void {
		$this->findReturns($this->aanbod(['provider' => ['id' => self::ACTING_ORG]]));
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('deleteObject')->willThrowException(new RuntimeException('locked'));

		$this->assertSame(
			['success' => false, 'error' => 'Failed to deny aanbod: locked', 'deleted' => false],
			$this->service->denyAanbod('aanbod-1')
		);

	}//end testFailingDeleteIsReported()

}//end class
