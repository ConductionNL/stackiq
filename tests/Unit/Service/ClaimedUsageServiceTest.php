<?php

/**
 * Unit tests for ClaimedUsageService.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
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

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\ClaimedUsageService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A supplier lists and answers the usages other organisations claim of its products.
 *
 * @covers \OCA\Stackiq\Service\ClaimedUsageService
 * @uses   \OCA\Stackiq\Service\OpenRegisterLookup
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
class ClaimedUsageServiceTest extends TestCase {

	private const SUPPLIER = 'org-centric';

	private const CLAIMANT = 'org-zuiddrecht';

	private ObjectServiceInterface&MockObject $objectService;

	private ?string $activeOrg = self::SUPPLIER;

	private ClaimedUsageService $service;

	/**
	 * Set up the service over mocked OpenRegister services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => 2, 'gebruik_schema' => 10]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carla');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

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

		$this->service = new ClaimedUsageService(
			appManager: $appManager,
			container: $container,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			settings: $settings
		);

	}//end setUp()

	/**
	 * A usage entity owned by an organisation.
	 *
	 * @param array<string, mixed> $data         The usage data.
	 * @param string               $organisation The owning organisation.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function usage(array $data, string $organisation): ObjectEntity&MockObject {
		$usage = $this->createMock(ObjectEntity::class);
		$usage->method('getObject')->willReturn($data);
		$usage->method('getUuid')->willReturn('usage-1');
		$usage->method('getRegister')->willReturn('2');
		$usage->method('getSchema')->willReturn('10');
		$usage->method('getOrganisation')->willReturn($organisation);
		$usage->method('getOwner')->willReturn('zara');
		return $usage;

	}//end usage()

	/**
	 * The list holds only unanswered usages of the supplier's products owned by others, searched as the system.
	 *
	 * @return void
	 */
	public function testTheListHoldsUnansweredClaimsOfOthersOnly(): void {
		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				['@self' => ['register' => 2, 'schema' => 10], 'provider' => self::SUPPLIER, '_extend' => ['consumer', 'module', 'moduleVersion'], '_limit' => 500],
				false,
				false
			)
			->willReturn(
				[
					'results' => [
						[
							'provider' => ['id' => self::SUPPLIER],
							'consumer' => ['id' => self::CLAIMANT, 'name' => 'Gemeente Zuiddrecht'],
							'module' => ['id' => 'm-1', 'name' => 'Suite4Gemeenten'],
							'moduleVersion' => ['id' => 'v-1', 'name' => '10.4'],
							'@self' => ['id' => 'usage-1', 'organisation' => self::CLAIMANT, 'created' => '2026-10-01T09:00:00+00:00'],
						],
						['provider' => self::SUPPLIER, '@self' => ['id' => 'own', 'organisation' => self::SUPPLIER]],
						['provider' => self::SUPPLIER, 'providerResponse' => 'declined', '@self' => ['id' => 'answered', 'organisation' => self::CLAIMANT]],
						['provider' => 'org-other', '@self' => ['id' => 'other', 'organisation' => self::CLAIMANT]],
						['provider' => self::SUPPLIER, '@self' => ['id' => 'orphan']],
					],
				]
			);

		self::assertSame(
			[
				'status' => 'ok',
				'organisation' => self::SUPPLIER,
				'results' => [
					[
						'id' => 'usage-1',
						'organisation' => 'Gemeente Zuiddrecht',
						'module' => 'Suite4Gemeenten',
						'moduleVersion' => '10.4',
						'claimedAt' => '2026-10-01T09:00:00+00:00',
					],
				],
			],
			$this->service->claimed()
		);

	}//end testTheListHoldsUnansweredClaimsOfOthersOnly()

	/**
	 * Without an active organisation, or when OpenRegister fails, the list is empty.
	 *
	 * @return void
	 */
	public function testTheListIsEmptyWithoutAnOrganisationOrOpenRegister(): void {
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new RuntimeException('down'));
		self::assertSame(['status' => 'unavailable', 'organisation' => self::SUPPLIER, 'results' => []], $this->service->claimed());

		$this->activeOrg = null;
		self::assertSame(['status' => 'no_organisation', 'organisation' => null, 'results' => []], $this->service->claimed());

	}//end testTheListIsEmptyWithoutAnOrganisationOrOpenRegister()

	/**
	 * Accepting records the answer, date and user, keeps the claimant as owner, and deletes nothing.
	 *
	 * @return void
	 */
	public function testTheSupplierAcceptsAClaim(): void {
		$usage = $this->usage(['provider' => self::SUPPLIER, 'status' => 'In production'], self::CLAIMANT);
		$this->objectService->method('find')->willReturn($usage);
		$this->objectService->expects($this->never())->method('deleteObject');

		$saved = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid, bool $rbac, bool $multitenancy) use (&$saved, $usage) {
				$saved = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $rbac, '_multitenancy' => $multitenancy];
				return $usage;
			}
		);

		$result = $this->service->answer(uuid: 'usage-1', answer: 'accepted');

		self::assertSame('ok', $result['status']);
		self::assertSame('accepted', $saved['object']['providerResponse']);
		self::assertSame('carla', $saved['object']['providerResponseBy']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $saved['object']['providerResponseAt']);
		self::assertSame(['organisation' => self::CLAIMANT, 'owner' => 'zara'], $saved['object']['@self']);
		self::assertSame('In production', $saved['object']['status']);
		self::assertSame(['2', '10', 'usage-1'], [$saved['register'], $saved['schema'], $saved['uuid']]);
		self::assertFalse($saved['_rbac']);
		self::assertFalse($saved['_multitenancy']);

	}//end testTheSupplierAcceptsAClaim()

	/**
	 * Declining records `declined` and leaves the usage in place.
	 *
	 * @return void
	 */
	public function testTheSupplierDeclinesAClaimWithoutDeletingIt(): void {
		$usage = $this->usage(['provider' => ['id' => self::SUPPLIER]], self::CLAIMANT);
		$this->objectService->method('find')->willReturn($usage);
		$this->objectService->expects($this->never())->method('deleteObject');
		$this->objectService->expects($this->once())->method('saveObject')
			->with($this->callback(fn (array $object): bool => $object['providerResponse'] === 'declined'))
			->willReturn($usage);

		self::assertSame('ok', $this->service->answer(uuid: 'usage-1', answer: 'declined')['status']);

	}//end testTheSupplierDeclinesAClaimWithoutDeletingIt()

	/**
	 * Another organisation, or the supplier on its own record, is refused before any write.
	 *
	 * @return void
	 */
	public function testOnlyTheProviderAnswersAClaimOfAnother(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->usage(['provider' => 'org-other'], self::CLAIMANT),
			$this->usage(['provider' => self::SUPPLIER], self::SUPPLIER),
			$this->usage([], self::CLAIMANT)
		);

		self::assertSame(['status' => 'forbidden'], $this->service->answer(uuid: 'usage-1', answer: 'accepted'));
		self::assertSame(['status' => 'forbidden'], $this->service->answer(uuid: 'usage-1', answer: 'accepted'));
		self::assertSame(['status' => 'forbidden'], $this->service->answer(uuid: 'usage-1', answer: 'declined'));

	}//end testOnlyTheProviderAnswersAClaimOfAnother()

	/**
	 * A wrong answer, a missing usage, no active organisation or a failed save write nothing or report it.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailures(): void {
		self::assertSame(['status' => 'invalid'], $this->service->answer(uuid: 'usage-1', answer: 'maybe'));
		self::assertSame(['status' => 'invalid'], $this->service->answer(uuid: ' ', answer: 'accepted'));

		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			null,
			$this->usage(['provider' => self::SUPPLIER], self::CLAIMANT)
		);
		self::assertSame(['status' => 'not_found'], $this->service->answer(uuid: 'usage-1', answer: 'accepted'));

		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('locked'));
		self::assertSame(['status' => 'unavailable'], $this->service->answer(uuid: 'usage-1', answer: 'accepted'));

		$this->activeOrg = null;
		self::assertSame(['status' => 'no_organisation'], $this->service->answer(uuid: 'usage-1', answer: 'accepted'));

	}//end testRefusalsAndFailures()
}//end class
