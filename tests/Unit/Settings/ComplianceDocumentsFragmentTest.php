<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sharing-compliance-documents/specs/shared-compliance-documents/spec.md#requirement-req-scd-002-the-audience-decides-who-reads-a-document
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-SCD-001, REQ-SCD-002: the complianceDocument schema joins the stackiq
 * register, and its read rules follow the document's audience.
 *
 * The rules are asserted on the merged register, the configuration OpenRegister
 * imports; the read scenarios evaluate them the way OpenRegister's match does,
 * per caller group and organisation.
 */
final class ComplianceDocumentsFragmentTest extends TestCase {

	/**
	 * The organisation that published the documents.
	 *
	 * @var string
	 */
	private const SUPPLIER = 'org-supplier';

	/**
	 * The register with every fragment merged the way the loader merges them.
	 *
	 * @return array<string,mixed>
	 */
	private function merged(): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep   = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$files  = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true));
			$merged = $keep->invoke(null, $before, $merged);
		}

		return $merged;
	}//end merged()

	/**
	 * The merged complianceDocument schema.
	 *
	 * @return array<string,mixed>
	 */
	private function schema(): array {
		return $this->merged()['components']['schemas']['complianceDocument'];
	}//end schema()

	/**
	 * The schema joins the stackiq register and declares every field of the design.
	 *
	 * @return void
	 */
	public function testTheSchemaJoinsTheRegister(): void {
		$register = $this->merged()['components']['registers']['stackiq'];
		self::assertContains('complianceDocument', $register['schemas']);
		self::assertSame('0.1.0', $this->merged()['components']['schemas']['complianceDocument']['version']);

		$schema = $this->schema();
		self::assertSame('complianceDocument', $schema['slug']);
		self::assertSame(
			['module', 'documentType', 'title', 'summary', 'issuedOn', 'validUntil', 'publisher', 'audience', 'sharedWithOrganisations'],
			array_keys($schema['properties'])
		);
		self::assertSame(['public', 'government', 'named'], $schema['properties']['audience']['enum']);
		self::assertSame('named', $schema['properties']['audience']['default']);
		self::assertSame(
			['DPIA', 'processing agreement', 'pentest report', 'assurance report', 'certificate', 'ENSIA statement', 'other'],
			$schema['properties']['documentType']['enum']
		);
		self::assertTrue($schema['configuration']['allowFiles']);
	}//end testTheSchemaJoinsTheRegister()

	/**
	 * A pentest report shared with municipality A is read by A and not by B.
	 *
	 * @return void
	 */
	public function testANamedDocumentIsReadOnlyByTheOrganisationsItIsSharedWith(): void {
		$report = ['audience' => 'named', 'sharedWithOrganisations' => ['org-a'], '_organisation' => self::SUPPLIER];

		self::assertTrue($this->mayRead(group: 'gebruik-beheerder', organisation: 'org-a', document: $report));
		self::assertFalse($this->mayRead(group: 'gebruik-beheerder', organisation: 'org-b', document: $report));
		self::assertFalse($this->mayRead(group: 'ambtenaar', organisation: 'org-b', document: $report));
		self::assertFalse($this->mayRead(group: 'public', organisation: '', document: $report));
		self::assertTrue($this->mayRead(group: 'aanbod-beheerder', organisation: self::SUPPLIER, document: $report), 'the publisher reads its own');
		self::assertTrue($this->mayRead(group: 'software-catalog-admins', organisation: 'org-b', document: $report));
	}//end testANamedDocumentIsReadOnlyByTheOrganisationsItIsSharedWith()

	/**
	 * A public document is read by an anonymous visitor.
	 *
	 * @return void
	 */
	public function testAPublicDocumentIsReadByAnyone(): void {
		$agreement = ['audience' => 'public', 'sharedWithOrganisations' => [], '_organisation' => self::SUPPLIER];

		self::assertTrue($this->mayRead(group: 'public', organisation: '', document: $agreement));
		self::assertTrue($this->mayRead(group: 'gebruik-beheerder', organisation: 'org-b', document: $agreement));
	}//end testAPublicDocumentIsReadByAnyone()

	/**
	 * A government document is read by every government group, not by an anonymous visitor or another supplier.
	 *
	 * @return void
	 */
	public function testAGovernmentDocumentIsReadByGovernmentGroupsOnly(): void {
		$dpia = ['audience' => 'government', 'sharedWithOrganisations' => [], '_organisation' => self::SUPPLIER];

		foreach (['ambtenaar', 'gebruik-beheerder', 'gebruik-raadpleger', 'functioneel-beheerder'] as $group) {
			self::assertTrue($this->mayRead(group: $group, organisation: 'org-b', document: $dpia), $group);
		}

		self::assertFalse($this->mayRead(group: 'public', organisation: '', document: $dpia));
		self::assertFalse($this->mayRead(group: 'aanbod-beheerder', organisation: 'org-other-supplier', document: $dpia));
	}//end testAGovernmentDocumentIsReadByGovernmentGroupsOnly()

	/**
	 * Only the publisher's organisation and the catalogue admins edit or delete a document.
	 *
	 * @return void
	 */
	public function testOnlyThePublisherEditsItsDocuments(): void {
		$authorization = $this->schema()['authorization'];
		foreach (['update', 'delete'] as $action) {
			foreach ($authorization[$action] as $grant) {
				if ($grant === 'software-catalog-admins') {
					continue;
				}

				self::assertIsArray($grant, $action);
				self::assertSame(['_organisation' => '$organisation'], $grant['match'], $action . ' ' . $grant['group']);
				self::assertArrayNotHasKey('crossOrganisation', $grant);
			}
		}
	}//end testOnlyThePublisherEditsItsDocuments()

	/**
	 * Every read grant other than the catalogue admins' is bound to a match, so no group reads a document unconditionally.
	 *
	 * @return void
	 */
	public function testNoGroupReadsEveryDocument(): void {
		foreach ($this->schema()['authorization']['read'] as $grant) {
			self::assertIsArray($grant);
			if ($grant['group'] === 'software-catalog-admins') {
				continue;
			}

			self::assertNotEmpty($grant['match'] ?? [], $grant['group'] . ' reads without a condition');
		}
	}//end testNoGroupReadsEveryDocument()

	/**
	 * The demo data holds a public processing agreement and a named pentest report.
	 *
	 * @return void
	 */
	public function testTheDemoDataShowsTheAudiences(): void {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/stackiq_mock_register.json'), true);
		$documents = array_values(array_filter(
			$mock['components']['objects'],
			static fn (array $object): bool => ($object['@self']['schema'] ?? '') === 'complianceDocument'
		));

		self::assertSame(['public', 'named'], array_column($documents, 'audience'));
		self::assertSame(['processing agreement', 'pentest report'], array_column($documents, 'documentType'));
	}//end testTheDemoDataShowsTheAudiences()

	/**
	 * Whether a caller in this group and organisation reads the document, by the schema's read grants.
	 *
	 * Mirrors OpenRegister's rule: a grant names a group; its match holds when
	 * every key equals the object's value, `$organisation` standing for the
	 * caller's organisation and `$contains` for membership of a list; a grant
	 * without crossOrganisation also needs the object to be of the caller's organisation.
	 *
	 * @param string               $group        The caller's group.
	 * @param string               $organisation The caller's active organisation, '' for none.
	 * @param array<string, mixed> $document     The document.
	 *
	 * @return bool
	 */
	private function mayRead(string $group, string $organisation, array $document): bool {
		foreach ($this->schema()['authorization']['read'] as $grant) {
			if (($grant['group'] ?? null) !== $group) {
				continue;
			}

			if (($grant['crossOrganisation'] ?? false) !== true && ($document['_organisation'] ?? null) !== $organisation) {
				continue;
			}

			if ($this->conditionsHold(match: ($grant['match'] ?? []), organisation: $organisation, document: $document) === true) {
				return true;
			}
		}

		return false;
	}//end mayRead()

	/**
	 * Whether every condition of a match holds.
	 *
	 * @param array<string, mixed> $match        The match.
	 * @param string               $organisation The caller's organisation.
	 * @param array<string, mixed> $document     The document.
	 *
	 * @return bool
	 */
	private function conditionsHold(array $match, string $organisation, array $document): bool {
		foreach ($match as $field => $expected) {
			$actual = ($document[$field] ?? null);
			if (is_array($expected) === true && array_key_exists('$contains', $expected) === true) {
				$needle = ($expected['$contains'] === '$organisation') ? $organisation : $expected['$contains'];
				if ($needle === '' || is_array($actual) === false || in_array($needle, $actual, true) === false) {
					return false;
				}

				continue;
			}

			if ($expected === '$organisation') {
				$expected = $organisation;
			}

			if ($actual !== $expected || $expected === '') {
				return false;
			}
		}

		return true;
	}//end conditionsHold()
}//end class
