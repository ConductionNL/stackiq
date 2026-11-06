<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\PublicCatalogueRecords;
use OCA\Stackiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-PORT-005: the published applications as a public record list.
 */
final class PublicCatalogueRecordsTest extends TestCase {
	/** @var array<int, array<string, mixed>> */
	private array $queries = [];

	public function testTheListHoldsOnlyPublishedApplicationsWithSupplierAndLicence(): void {
		$entries = $this->records()->entries();

		self::assertSame([['id' => 'openzaak', 'title' => 'OpenZaak', 'subtitle' => 'Conduction · EUPL-1.2']], $entries);
		foreach ($this->queries as $query) {
			self::assertArrayHasKey('_limit', $query);
		}
	}//end testTheListHoldsOnlyPublishedApplicationsWithSupplierAndLicence()

	public function testARecordNamesTheReferenceComponents(): void {
		$record = $this->records()->record(id: 'openzaak');

		self::assertSame('OpenZaak', $record['title']);
		self::assertSame([['component' => 'Zaakregistratiecomponent']], $record['rows']);
		self::assertSame('Conduction', $record['summary'][0]['value']);
		self::assertSame('EUPL-1.2', $record['summary'][1]['value']);
	}//end testARecordNamesTheReferenceComponents()

	public function testAnUnpublishedApplicationHasNoRecord(): void {
		self::assertNull($this->records()->record(id: 'draft'));
		self::assertNull($this->records()->record(id: 'withdrawn'));
		self::assertNull($this->records()->record(id: 'future'));
	}//end testAnUnpublishedApplicationHasNoRecord()

	public function testPublishedMeansTheDateHasComeAndNoDepublicationHas(): void {
		$records = $this->records();
		$now = new DateTimeImmutable('2026-10-23T12:00:00+00:00');

		self::assertTrue($records->isPublished(module: ['publicationDate' => '2026-10-22T00:00:00+00:00'], now: $now));
		self::assertTrue($records->isPublished(module: ['publicationDate' => '2026-10-22T00:00:00+00:00', 'depublicationDate' => '2026-11-01T00:00:00+00:00'], now: $now));
		self::assertFalse($records->isPublished(module: ['publicationDate' => '2026-10-22T00:00:00+00:00', 'depublicationDate' => '2026-10-23T00:00:00+00:00'], now: $now));
		self::assertFalse($records->isPublished(module: ['publicationDate' => '2026-10-24T00:00:00+00:00'], now: $now));
		self::assertFalse($records->isPublished(module: [], now: $now));
	}//end testPublishedMeansTheDateHasComeAndNoDepublicationHas()

	private function records(): PublicCatalogueRecords {
		$modules = [
			['id' => 'openzaak', 'name' => 'OpenZaak', 'provider' => 'conduction', 'licence' => 'EUPL-1.2', 'referenceComponents' => [['id' => 'zrc']], 'publicationDate' => '2026-01-01T00:00:00+00:00'],
			['id' => 'draft', 'name' => 'Draft app', 'provider' => 'conduction'],
			['id' => 'withdrawn', 'name' => 'Old app', 'publicationDate' => '2025-01-01T00:00:00+00:00', 'depublicationDate' => '2026-01-01T00:00:00+00:00'],
			['id' => 'future', 'name' => 'Next app', 'publicationDate' => '2099-01-01T00:00:00+00:00'],
		];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($modules): array {
				$this->queries[] = $query;
				$rows = match ($query['@self']['schema']) {
					22 => $modules,
					21 => [['id' => 'conduction', 'name' => 'Conduction']],
					30 => [['id' => 'zrc', 'name' => 'Zaakregistratiecomponent']],
				};
				if (isset($query['id']) === true) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['id'], $query['id'], true)));
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
		$l10n->method('t')->willReturnArgument(0);

		return new PublicCatalogueRecords($settings, $container, new PortfolioReportDerivation(), new NullLogger(), $l10n);
	}//end records()
}//end class
