<?php

/**
 * Unit tests for the public software catalogue and audience routing of the
 * Stackiq Portal Contribution Provider.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Portal;

use OCA\Stackiq\Portal\PortalContributionProvider;
use OCA\Stackiq\Service\PublicCatalogueRecords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The public catalogue methods hand off to PublicCatalogueRecords, and the
 * manifest is chosen by an exact audience match only.
 */
#[CoversClass(PortalContributionProvider::class)]
class PortalContributionProviderCatalogueTest extends TestCase {
	/**
	 * The published applications source.
	 *
	 * @var PublicCatalogueRecords&MockObject
	 */
	private PublicCatalogueRecords&MockObject $catalogue;

	/**
	 * System under test, wired to the catalogue mock.
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * Build the provider around a catalogue mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->catalogue = $this->createMock(originalClassName: PublicCatalogueRecords::class);
		$this->provider  = new PortalContributionProvider(catalogue: $this->catalogue);
	}

	/**
	 * The public list is exactly what the catalogue returns.
	 *
	 * @return void
	 */
	public function testPublicCatalogueReturnsTheCatalogueEntries(): void {
		$entries = [
			['id' => 'm-1', 'title' => 'Zaaksysteem', 'subtitle' => 'Acme'],
			['id' => 'm-2', 'title' => 'DMS', 'subtitle' => 'Beta'],
		];
		$this->catalogue->expects($this->once())->method('entries')->willReturn($entries);

		$this->assertSame(expected: $entries, actual: $this->provider->publicCatalogue());
	}

	/**
	 * An empty catalogue yields an empty public list.
	 *
	 * @return void
	 */
	public function testPublicCatalogueIsEmptyWhenTheCatalogueIs(): void {
		$this->catalogue->expects($this->once())->method('entries')->willReturn([]);

		$this->assertSame(expected: [], actual: $this->provider->publicCatalogue());
	}

	/**
	 * One entry is read from the catalogue by the requested id.
	 *
	 * @return void
	 */
	public function testPublicCatalogueEntryReadsTheRecordById(): void {
		$record = ['id' => 'm-1', 'title' => 'Zaaksysteem', 'versions' => []];
		$this->catalogue->expects($this->once())
			->method('record')
			->with('m-1')
			->willReturn($record);

		$this->assertSame(expected: $record, actual: $this->provider->publicCatalogueEntry(id: 'm-1'));
	}

	/**
	 * An unpublished or unknown entry stays null.
	 *
	 * @return void
	 */
	public function testPublicCatalogueEntryIsNullWhenNotPublished(): void {
		$this->catalogue->expects($this->once())
			->method('record')
			->with('hidden')
			->willReturn(null);

		$this->assertNull(actual: $this->provider->publicCatalogueEntry(id: 'hidden'));
	}

	/**
	 * Building a manifest never reads the catalogue: the manifest is pure data.
	 *
	 * @return void
	 */
	public function testManifestDoesNotReadTheCatalogue(): void {
		$this->catalogue->expects($this->never())->method('entries');
		$this->catalogue->expects($this->never())->method('record');

		$manifest = $this->provider->getContribution(subject: ['audience' => 'vendor-org']);

		$this->assertSame(
			expected: [
				[
					'id'             => 'softwareCatalogue',
					'label'          => 'Software catalogue',
					'listProvider'   => 'publicCatalogue',
					'recordProvider' => 'publicCatalogueEntry',
				],
			],
			actual: $manifest['publicRecords']
		);
		$this->assertSame(expected: ['label', 'collections', 'actions', 'notifications', 'publicRecords'], actual: array_keys($manifest));
	}

	/**
	 * Audiences that only resemble a served one get no manifest.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function nearMissAudiences(): array {
		return [
			'upper case'      => ['Vendor-org'],
			'padded'          => [' vendor-org'],
			'trailing space'  => ['participant-org '],
			'other separator' => ['vendor_org'],
			'null'            => [null],
			'list'            => [['vendor-org']],
		];
	}

	/**
	 * Only an exact audience string selects a manifest (fail-closed).
	 *
	 * @param mixed $audience The subject audience.
	 *
	 * @return void
	 */
	#[DataProvider('nearMissAudiences')]
	public function testNearMissAudienceGetsNoManifest(mixed $audience): void {
		$this->assertNull(actual: $this->provider->getContribution(subject: ['audience' => $audience]));
	}

	/**
	 * Other subject claims do not change which manifest is returned.
	 *
	 * @return void
	 */
	public function testOtherSubjectClaimsDoNotChangeTheManifest(): void {
		$plain = $this->provider->getContribution(subject: ['audience' => 'participant-org']);
		$rich  = $this->provider->getContribution(subject: [
			'audience'     => 'participant-org',
			'organisation' => 'org-9',
			'trust'        => 'low',
			'subjectRef'   => 'abc',
		]);

		$this->assertSame(expected: $plain, actual: $rich);
		$this->assertSame(
			expected: ['participantGebruik', 'participantContracts'],
			actual: array_column($rich['collections'], 'id')
		);
	}

	/**
	 * The vendor and participant manifests differ only in their collections.
	 *
	 * @return void
	 */
	public function testAudiencesShareTheManifestFrame(): void {
		$vendor      = $this->provider->getContribution(subject: ['audience' => 'vendor-org']);
		$participant = $this->provider->getContribution(subject: ['audience' => 'participant-org']);

		$this->assertNotSame(expected: $vendor['collections'], actual: $participant['collections']);
		unset($vendor['collections'], $participant['collections']);
		$this->assertSame(expected: $vendor, actual: $participant);
	}
}
