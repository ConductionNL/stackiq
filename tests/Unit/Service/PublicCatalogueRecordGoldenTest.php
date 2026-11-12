<?php

/**
 * Golden tests for PublicCatalogueRecords::record().
 *
 * Pins the whole public record of a published application (summary, the
 * reference component rows in order, components that are not found left
 * out), the queries it sends in order, and no record for an unknown or
 * unpublished application, so splitting the method keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\PublicCatalogueRecords;
use OCA\Stackiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Golden tests for one public catalogue record.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class PublicCatalogueRecordGoldenTest extends TestCase {

	/**
	 * Every query sent, in order.
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

	/**
	 * The records over a register with these modules.
	 *
	 * @param array<int, array> $modules The modules.
	 *
	 * @return PublicCatalogueRecords
	 */
	private function records(array $modules): PublicCatalogueRecords {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($modules): array {
				$this->queries[] = $query;
				$rows = match ($query['@self']['schema']) {
					22 => $modules,
					21 => [['id' => 'conduction', 'name' => 'Conduction']],
					30 => [['id' => 'zrc', 'name' => 'Zaakregistratiecomponent'], ['@self' => ['id' => 'drc'], 'name' => 'Documentregistratiecomponent']],
				};
				if (isset($query['id']) === true) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['id'] ?? $row['@self']['id'], $query['id'], true)));
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '1', 'module_schema' => '22', 'organisatie_schema' => '21']);
		$settings->method('getAmefConfig')->willReturn(['register' => '2', 'element_schema' => '30']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		return new PublicCatalogueRecords($settings, $container, new PortfolioReportDerivation(), new NullLogger(), $l10n);
	}//end records()

	/**
	 * The whole record of a published application, and the queries behind it.
	 *
	 * @return void
	 */
	public function testTheWholeRecordAndItsQueries(): void {
		$module = [
			'id' => 'openzaak',
			'name' => 'OpenZaak',
			'shortDescription' => 'Zaken',
			'provider' => ['id' => 'conduction'],
			'licentietype' => ' EUPL-1.2 ',
			'website' => 'https://example.org',
			'referenceComponents' => [['id' => 'drc'], 'missing', 'zrc', ['id' => 'drc']],
			'publicationDate' => '2026-01-01T00:00:00+00:00',
		];

		$record = $this->records(modules: [$module])->record(id: 'openzaak');

		$this->assertSame(
			[
				'title' => 'OpenZaak',
				'subtitle' => 'Zaken',
				'summary' => [
					['label' => 'T:Supplier', 'value' => 'Conduction'],
					['label' => 'T:Licence', 'value' => 'EUPL-1.2'],
					['label' => 'T:Website', 'value' => 'https://example.org'],
				],
				'columns' => [['key' => 'component', 'label' => 'T:Reference component']],
				'rows' => [
					['component' => 'Documentregistratiecomponent'],
					['component' => 'Zaakregistratiecomponent'],
					['component' => 'Documentregistratiecomponent'],
				],
			],
			$record
		);
		$this->assertSame(
			[
				['@self' => ['register' => 1, 'schema' => 22], '_limit' => 500, 'id' => ['openzaak']],
				['@self' => ['register' => 1, 'schema' => 21], 'id' => ['conduction'], '_limit' => 1],
				['@self' => ['register' => 2, 'schema' => 30], 'id' => ['drc', 'missing', 'zrc'], '_limit' => 3],
			],
			array_map(static fn (array $query): array => array_diff_key($query, ['_rbac' => true, '_multitenancy' => true]), $this->queries)
		);
	}//end testTheWholeRecordAndItsQueries()

	/**
	 * A record without supplier or components still has every field.
	 *
	 * @return void
	 */
	public function testARecordWithoutRelationsHasEmptyValues(): void {
		$record = $this->records(modules: [['id' => 'bare', 'publicationDate' => '2026-01-01T00:00:00+00:00']])->record(id: 'bare');

		$this->assertSame(
			[
				'title' => '',
				'subtitle' => '',
				'summary' => [
					['label' => 'T:Supplier', 'value' => ''],
					['label' => 'T:Licence', 'value' => ''],
					['label' => 'T:Website', 'value' => ''],
				],
				'columns' => [['key' => 'component', 'label' => 'T:Reference component']],
				'rows' => [],
			],
			$record
		);
		$this->assertCount(1, $this->queries);
	}//end testARecordWithoutRelationsHasEmptyValues()

	/**
	 * An unknown or unpublished application has no record and reads nothing more.
	 *
	 * @return void
	 */
	public function testNoRecordForAnUnknownOrUnpublishedApplication(): void {
		$this->assertNull($this->records(modules: [])->record(id: 'gone'));
		$this->assertNull($this->records(modules: [['id' => 'draft', 'provider' => 'conduction']])->record(id: 'draft'));
		$this->assertCount(2, $this->queries);
	}//end testNoRecordForAnUnknownOrUnpublishedApplication()

}//end class
