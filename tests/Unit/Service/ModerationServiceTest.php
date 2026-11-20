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
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getObject')->willReturn($entry);
		$entity->method('getUuid')->willReturn((string) $entry['id']);

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return $this->createStub(originalClassName: ObjectEntity::class);
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not bound: ' . $id);
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getRegisterIdForObjectType')->willReturn(1);
		$settings->method('getSchemaIdForObjectType')->willReturn(2);

		return new ModerationService(container: $container, settingsService: $settings, logger: new NullLogger());
	}//end moderation()

	/**
	 * An approved registration and its status both read active in one save.
	 *
	 * @return void
	 */
	public function testAnApprovedRegistrationAndItsStatusBothReadActiveInOneSave(): void {
		$moderation = $this->moderation(
			entry: ['id' => 'gemeente-voorbeeld', 'name' => 'Gemeente Voorbeeld', 'registrationStatus' => 'pending', 'status' => 'Draft']
		);

		$result = $moderation->approve('gemeente-voorbeeld');

		$this->assertSame(expected: ['ok' => true, 'reason' => 'approved', 'status' => 'active'], actual: $result);
		$this->assertCount(expectedCount: 1, haystack: $this->saved);
		$this->assertSame(expected: 'active', actual: $this->saved[0]['registrationStatus']);
		$this->assertSame(expected: 'Active', actual: $this->saved[0]['status']);
		$this->assertSame(expected: 'Gemeente Voorbeeld', actual: $this->saved[0]['name']);
	}

	/**
	 * Arejected registration keeps its status.
	 *
	 * @return void
	 */
	public function testARejectedRegistrationKeepsItsStatus(): void {
		$moderation = $this->moderation(
			entry: ['id' => 'gemeente-voorbeeld', 'name' => 'Gemeente Voorbeeld', 'registrationStatus' => 'pending', 'status' => 'Draft']
		);

		$moderation->reject('gemeente-voorbeeld');

		$this->assertSame(expected: 'rejected', actual: $this->saved[0]['registrationStatus']);
		$this->assertSame(expected: 'Draft', actual: $this->saved[0]['status']);
	}

	/**
	 * An approved review is not given an organisation status.
	 *
	 * @return void
	 */
	public function testAnApprovedReviewIsNotGivenAnOrganisationStatus(): void {
		$moderation = $this->moderation(entry: ['id' => 'review-1', 'rating' => 4, 'status' => 'pending']);

		$moderation->approve('review-1', ModerationService::MODERATED_TYPE_REVIEW);

		$this->assertSame(expected: 'approved', actual: $this->saved[0]['status']);
		$this->assertArrayNotHasKey(key: 'registrationStatus', array: $this->saved[0]);
	}

	/**
	 * The active value is one the organisation schema allows.
	 *
	 * @return void
	 */
	public function testTheActiveValueIsOneTheOrganisationSchemaAllows(): void {
		$register = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);

		$this->assertContains(
			needle: ModerationService::ORGANISATION_STATUS_ACTIVE,
			haystack: $register['components']['schemas']['organization']['properties']['status']['enum']
		);
	}
}
