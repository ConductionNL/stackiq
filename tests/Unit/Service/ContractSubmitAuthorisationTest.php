<?php

/**
 * Golden test for ContractApprovalService::authorizeSubmit.
 *
 * Pins who may submit a contract for approval (and who is refused), and
 * which lookups each decision makes.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ContractApprovalService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Golden cases for the submit ownership guard.
 *
 * @covers \OCA\Stackiq\Service\ContractApprovalService
 *
 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
 */
class ContractSubmitAuthorisationTest extends TestCase {

	/**
	 * The contract lookups made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $finds = [];

	/**
	 * Decide for a caller over a contract with the given data.
	 *
	 * @param array      $groups   The caller's groups.
	 * @param string     $org      The caller's active organisation.
	 * @param array|null $contract The contract's data, or null when it cannot be loaded.
	 *
	 * @return bool
	 */
	private function authorize(array $groups, string $org, ?array $contract): bool {
		$this->finds = [];

		$entity = null;
		if ($contract !== null) {
			$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
			$entity->method('getObject')->willReturn($contract);
		}

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use ($entity) {
				$this->finds[] = $id;
				return $entity;
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$locator = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturn(1);
		$locator->method('getSchemaIdForObjectType')->willReturn(3);

		$service = new ContractApprovalService($container, $locator, $this->createMock(originalClassName: IEventDispatcher::class), new NullLogger());
		return $service->authorizeSubmit('c-1', $groups, $org);

	}//end authorize()

	/**
	 * Admins are allowed without a lookup; owners of the contract's organisation are allowed.
	 *
	 * @return void
	 */
	public function testAdminsAndOwnersAreAllowed(): void {
		$this->assertTrue($this->authorize(groups: ['users', 'admin'], org: '', contract: null));
		$this->assertSame([], $this->finds);

		$this->assertTrue($this->authorize(groups: ['aanbod-beheerder'], org: 'org-a', contract: ['_organisation' => 'org-a', 'provider' => 'org-b']));
		$this->assertSame(['c-1'], $this->finds);

		$this->assertTrue($this->authorize(groups: ['aanbod-beheerder'], org: 'org-b', contract: ['provider' => 'org-b']));
		$this->assertTrue($this->authorize(groups: ['aanbod-beheerder'], org: 'org-b', contract: ['_organisation' => null, 'provider' => 'org-b']));

	}//end testAdminsAndOwnersAreAllowed()

	/**
	 * Other roles, other organisations, blank organisations and unloadable contracts are refused.
	 *
	 * @return void
	 */
	public function testEveryoneElseIsRefused(): void {
		$this->assertFalse($this->authorize(groups: ['Admin', 'Aanbod-beheerder'], org: 'org-a', contract: ['_organisation' => 'org-a']));
		$this->assertSame([], $this->finds);

		$this->assertFalse($this->authorize(groups: ['aanbod-beheerder'], org: 'org-a', contract: ['_organisation' => 'org-b']));
		$this->assertFalse($this->authorize(groups: ['aanbod-beheerder'], org: 'org-a', contract: ['_organisation' => '', 'provider' => 'org-a']));
		$this->assertFalse($this->authorize(groups: ['aanbod-beheerder'], org: '', contract: []));
		$this->assertFalse($this->authorize(groups: ['aanbod-beheerder'], org: 'org-a', contract: []));
		$this->assertFalse($this->authorize(groups: ['aanbod-beheerder'], org: 'org-a', contract: null));
		$this->assertSame(['c-1'], $this->finds);

	}//end testEveryoneElseIsRefused()
}//end class
