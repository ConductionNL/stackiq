<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\ReferenceComponentCoverageDerivation;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RCC-001: gap, covered, overlap and fillable per reference component.
 */
final class ReferenceComponentCoverageDerivationTest extends TestCase {
	public function testTwoApplicationsForOneComponentReadAsOverlap(): void {
		$result = $this->derive();
		$states = array_column($result['components'], 'state', 'name');

		self::assertSame('overlap', $states['Zaakregistratiecomponent']);
		self::assertSame('covered', $states['Financiële component']);
		self::assertSame('gap', $states['Documentbeheercomponent']);
		self::assertSame('gap', $states['Kennisbankcomponent']);

		$zrc = $this->row($result, 'Zaakregistratiecomponent');
		self::assertSame(['Mozard', 'OpenZaak'], array_column($zrc['applications'], 'moduleName'));
	}//end testTwoApplicationsForOneComponentReadAsOverlap()

	public function testTwoUsagesOfOneModuleCountOnceAndPlannedDoesNotCount(): void {
		$result = $this->derive();

		// Key2Financien runs twice (two versions) and a planned usage names it too.
		$financial = $this->row($result, 'Financiële component');
		self::assertCount(1, $financial['applications']);
		self::assertSame('Key2Financien', $financial['applications'][0]['moduleName']);
	}//end testTwoUsagesOfOneModuleCountOnceAndPlannedDoesNotCount()

	public function testAGapAUsedModuleDeclaresIsFillable(): void {
		$result = $this->derive();

		$documents = $this->row($result, 'Documentbeheercomponent');
		self::assertTrue($documents['fillable']);
		self::assertSame(['Mozard'], $documents['fillableBy']);
		self::assertFalse($this->row($result, 'Kennisbankcomponent')['fillable']);
	}//end testAGapAUsedModuleDeclaresIsFillable()

	public function testSummaryCountsStatesAndUsagesWithoutAComponent(): void {
		$summary = $this->derive()['summary'];

		self::assertSame(
			['components' => 4, 'gap' => 2, 'fillable' => 1, 'covered' => 1, 'overlap' => 1, 'usagesWithoutComponent' => 1],
			$summary
		);
	}//end testSummaryCountsStatesAndUsagesWithoutAComponent()

	/**
	 * @return array<string,mixed>
	 */
	private function derive(): array {
		$components = [
			['id' => 'zrc', 'name' => 'Zaakregistratiecomponent', 'grouping' => 'Mid-office'],
			['id' => 'fin', 'name' => 'Financiële component', 'grouping' => 'Back-office'],
			['id' => 'doc', 'name' => 'Documentbeheercomponent', 'grouping' => 'Mid-office'],
			['id' => 'kb', 'name' => 'Kennisbankcomponent', 'grouping' => 'Front-office'],
		];
		$usages = [
			['id' => 'u1', 'module' => 'mozard', 'status' => 'In production', 'usedForReferenceComponents' => ['zrc']],
			['id' => 'u2', 'module' => 'openzaak', 'status' => 'To be phased out', 'usedForReferenceComponents' => [['id' => 'zrc']]],
			['id' => 'u3', 'module' => 'key2', 'status' => 'In production', 'usedForReferenceComponents' => ['fin']],
			['id' => 'u4', 'module' => 'key2', 'status' => 'In production', 'usedForReferenceComponents' => ['fin']],
			['id' => 'u5', 'module' => 'exact', 'status' => 'Planned', 'usedForReferenceComponents' => ['fin', 'kb']],
			['id' => 'u6', 'module' => 'djuma', 'status' => 'In production'],
		];
		$names = ['mozard' => 'Mozard', 'openzaak' => 'OpenZaak', 'key2' => 'Key2Financien', 'exact' => 'Exact', 'djuma' => 'Djuma'];

		return (new ReferenceComponentCoverageDerivation(new PortfolioReportDerivation()))
			->derive(components: $components, usages: $usages, moduleNames: $names, moduleComponents: ['mozard' => ['zrc', 'doc']]);
	}//end derive()

	/**
	 * @param array<string,mixed> $result The derivation.
	 *
	 * @return array<string,mixed>
	 */
	private function row(array $result, string $name): array {
		foreach ($result['components'] as $row) {
			if ($row['name'] === $name) {
				return $row;
			}
		}

		self::fail('no row ' . $name);
	}//end row()
}//end class
