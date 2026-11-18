<?php

/**
 * Approving a self-registered organisation makes it active everywhere (REQ-CLR-010).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/open-data-publishing/spec.md#requirement-approving-a-self-registered-organisation-makes-it-active-req-clr-010
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Approval used to leave the organisation's own status at Draft, so user
 * provisioning never treated it as active.
 *
 * @covers \OCA\Stackiq\Service\ModerationService
 */
final class ModerationServiceTest extends TestCase {

	/**
	 * The data each saveObject() call received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The moderation service over a store that finds one entry and records what is saved.
	 *
	 * @param array<string, mixed> $entry The stored entry.
	 *
	 * @return ModerationService
	 */
	private function moderation(array $entry): ModerationService {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($entry);
		$entity->method('getUuid')->willReturn((string) $entry['id']);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return $this->createStub(ObjectEntity::class);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not bound: ' . $id);
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterIdForObjectType')->willReturn(1);
		$settings->method('getSchemaIdForObjectType')->willReturn(2);

		return new ModerationService($container, $settings, new NullLogger());
	}//end moderation()

	public function testAnApprovedRegistrationAndItsStatusBothReadActiveInOneSave(): void {
		$moderation = $this->moderation(['id' => 'gemeente-voorbeeld', 'name' => 'Gemeente Voorbeeld', 'registrationStatus' => 'pending', 'status' => 'Draft']);

		$result = $moderation->approve('gemeente-voorbeeld');

		$this->assertSame(['ok' => true, 'reason' => 'approved', 'status' => 'active'], $result);
		$this->assertCount(1, $this->saved);
		$this->assertSame('active', $this->saved[0]['registrationStatus']);
		$this->assertSame('Active', $this->saved[0]['status']);
		$this->assertSame('Gemeente Voorbeeld', $this->saved[0]['name']);
	}

	public function testARejectedRegistrationKeepsItsStatus(): void {
		$moderation = $this->moderation(['id' => 'gemeente-voorbeeld', 'name' => 'Gemeente Voorbeeld', 'registrationStatus' => 'pending', 'status' => 'Draft']);

		$moderation->reject('gemeente-voorbeeld');

		$this->assertSame('rejected', $this->saved[0]['registrationStatus']);
		$this->assertSame('Draft', $this->saved[0]['status']);
	}

	public function testAnApprovedReviewIsNotGivenAnOrganisationStatus(): void {
		$moderation = $this->moderation(['id' => 'review-1', 'rating' => 4, 'status' => 'pending']);

		$moderation->approve('review-1', ModerationService::MODERATED_TYPE_REVIEW);

		$this->assertSame('approved', $this->saved[0]['status']);
		$this->assertArrayNotHasKey('registrationStatus', $this->saved[0]);
	}

	public function testTheActiveValueIsOneTheOrganisationSchemaAllows(): void {
		$register = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);

		$this->assertContains(ModerationService::ORGANISATION_STATUS_ACTIVE, $register['components']['schemas']['organization']['properties']['status']['enum']);
	}
}
