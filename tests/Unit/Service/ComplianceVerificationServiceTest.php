<?php

/**
 * The verify action refuses before it writes, and writes only the stamp.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Service\ComplianceVerificationService;
use OCA\Stackiq\Service\OpenRegisterLookup;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers ComplianceVerificationService over a mocked object service.
 *
 * @covers \OCA\Stackiq\Service\ComplianceVerificationService
 * @uses   \OCA\Stackiq\Service\ComplianceVerification
 * @uses   \OCA\Stackiq\Service\RelationRefs
 * @uses   \OCA\Stackiq\Service\OpenRegisterLookup
 */
final class ComplianceVerificationServiceTest extends TestCase {

	/**
	 * The saves made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Whether the guard flag was up during each save.
	 *
	 * @var list<bool>
	 */
	private array $writingDuringSave = [];

	/**
	 * A service over stored objects keyed by uuid; compliancy schema 20, module schema 21, register 3.
	 *
	 * @param array<string, array<string, mixed>> $stored The stored objects.
	 *
	 * @return ComplianceVerificationService
	 */
	private function service(array $stored): ComplianceVerificationService {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options) use ($stored) {
				if (isset($stored[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($stored[$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac) {
				$this->saves[] = ['object' => $object, 'schema' => $options->target->schema, 'uuid' => $options->uuid, 'rbac' => $_rbac];
				$this->writingDuringSave[] = ComplianceVerificationService::isWriting();
				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$orContainer = $this->createMock(originalClassName: ContainerInterface::class);
		$orContainer->method('get')->willReturn($objects);
		$lookup = new OpenRegisterLookup(
			appManager: $apps,
			container: $orContainer,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$types = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$types->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $t) => ['compliancy' => 20, 'module' => 21][$t] ?? null);
		$types->method('getRegisterIdForObjectType')->willReturn(3);

		return new ComplianceVerificationService(
			lookup: $lookup,
			types: $types,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end service()

	/**
	 * A claim on module m1 by supplier org-s, with evidence.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stored(): array {
		return [
			'c1' => ['module' => 'm1', 'url' => 'https://example.org/report.pdf'],
			'm1' => ['name' => 'Zaaksysteem', 'provider' => ['id' => 'org-s']],
		];
	}//end stored()

	/**
	 * Areviewer of another organisation verifies and only the stamp is written.
	 *
	 * @return void
	 */
	public function testAReviewerOfAnotherOrganisationVerifiesAndOnlyTheStampIsWritten(): void {
		$result = $this->service(stored: $this->stored())->verify(
			id: 'c1',
			reviewer: 'Rita Reviewer',
			groups: ['functioneel-beheerder'],
			organisation: 'org-g',
			now: '2026-11-17T10:00:00Z'
		);

		$stamp = ['verifiedBy' => 'Rita Reviewer', 'verifiedAt' => '2026-11-17T10:00:00Z', 'verifiedByOrganisation' => 'org-g'];
		$this->assertSame(expected: ['status' => 200, 'body' => $stamp], actual: $result);
		$this->assertSame(
			expected: [
				['object' => ['module' => 'm1', 'url' => 'https://example.org/report.pdf'] + $stamp, 'schema' => 20, 'uuid' => 'c1', 'rbac' => false],
			],
			actual: $this->saves
		);
		$this->assertSame(expected: [true], actual: $this->writingDuringSave);
		$this->assertFalse(condition: ComplianceVerificationService::isWriting());
	}

	/**
	 * The supplier is refused before any write.
	 *
	 * @return void
	 */
	public function testTheSupplierIsRefusedBeforeAnyWrite(): void {
		$result = $this->service(stored: $this->stored())->verify(id: 'c1', reviewer: 'Sam', groups: ['admin'], organisation: 'org-s', now: 'now');

		$this->assertSame(expected: 403, actual: $result['status']);
		$this->assertSame(expected: [], actual: $this->saves);
	}

	/**
	 * An unknown claim or an unreadable module is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownClaimOrAnUnreadableModuleIsRefused(): void {
		$this->assertSame(
			expected: 404,
			actual: $this->service(stored: [])->verify(id: 'c9', reviewer: 'R', groups: ['admin'], organisation: 'org-g', now: 'now')['status']
		);
		$this->assertSame(
			expected: 409,
			actual: $this->service(stored: ['c1' => ['module' => 'gone', 'url' => 'x']])->verify(
				id: 'c1',
				reviewer: 'R',
				groups: ['admin'],
				organisation: 'org-g',
				now: 'now'
			)['status']
		);
		$this->assertSame(expected: [], actual: $this->saves);
	}
}
