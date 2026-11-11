<?php

/**
 * SBOM Parser Service.
 *
 * A PURE parser: it turns the bytes of an uploaded Software Bill of
 * Materials document into a normalized, format-agnostic component list.
 * It has no dependency on OpenRegister's `ObjectService`, no HTTP client,
 * and performs no I/O beyond decoding the string it is handed — so it is
 * unit-testable against fixture files alone, with no database and no
 * network (ADR-008, design Decision 1 / Decision 7).
 *
 * Supports CycloneDX 1.5/1.6 JSON (`bomFormat: "CycloneDX"`) as the
 * required format, and SPDX 2.x JSON as an optional second format sharing
 * the same component DTO shape. The two are separate, explicit entry
 * points (`parse()` / `parseSpdx()`) rather than one auto-detecting
 * method — the upload UI already knows which format button the user
 * picked, so a malformed file gets a precise "wrong format selected"
 * error instead of a guessed partial parse (design Decision 1,
 * "Alternative considered").
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-cyclonedx-sbom-files-are-parsed-into-a-normalized-component-list
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Exception\UnsupportedSbomFormatException;

/**
 * Pure CycloneDX 1.5/1.6 (+ optional SPDX 2.x) SBOM parser.
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-cyclonedx-sbom-files-are-parsed-into-a-normalized-component-list
 */
class SbomParserService {
	/**
	 * CycloneDX `specVersion` values this app understands.
	 *
	 * @var array<int,string>
	 */
	private const SUPPORTED_CYCLONEDX_VERSIONS = ['1.5', '1.6'];

	/**
	 * Maximum `json_decode` nesting depth — bounds a maliciously/accidentally
	 * deep document rather than trusting the file (design "Security
	 * Considerations": bounded json_decode depth).
	 */
	private const MAX_JSON_DEPTH = 64;

	/**
	 * Parse a CycloneDX 1.5/1.6 JSON document into a normalized component
	 * list plus any VEX (`vulnerabilities[]`) CVE/bom-ref pairs it carries.
	 *
	 * @param string $json The raw uploaded file contents.
	 *
	 * @return array{components: array<int, array<string, mixed>>, vulnerabilities: array<int, array{cveId: string, componentBomRef: string}>}
	 *
	 * @throws UnsupportedSbomFormatException When the document is not valid
	 *                                        JSON, or its `bomFormat`/
	 *                                        `specVersion` is not supported.
	 *                                        No partial component list is
	 *                                        ever returned in that case.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-cyclonedx-sbom-files-are-parsed-into-a-normalized-component-list
	 */
	public function parse(string $json): array {
		$data = $this->decode(json: $json);
		$this->assertSupportedCycloneDx(data: $data);

		$components = [];
		foreach (($data['components'] ?? []) as $componentData) {
			if (is_array($componentData) === false) {
				continue;
			}

			$components[] = $this->normalizeCycloneDxComponent(component: $componentData);
		}

		return [
			'components' => $components,
			'vulnerabilities' => $this->extractVexPairs(vulnerabilities: $data['vulnerabilities'] ?? []),
		];
	}//end parse()

	/**
	 * Refuse a document that is not CycloneDX in a supported spec version.
	 *
	 * @param array<string, mixed> $data The decoded document.
	 *
	 * @return void
	 *
	 * @throws UnsupportedSbomFormatException When `bomFormat`/`specVersion` is not supported.
	 */
	private function assertSupportedCycloneDx(array $data): void {
		$bomFormat = $data['bomFormat'] ?? null;
		$specVersion = $data['specVersion'] ?? null;
		$isCycloneDx = $bomFormat === 'CycloneDX';
		$isSupportedVersion = $this->isSupportedCycloneDxVersion(specVersion: $specVersion);

		if ($isCycloneDx === false || $isSupportedVersion === false) {
			throw new UnsupportedSbomFormatException(
				message: sprintf(
					'Unsupported SBOM format/version: bomFormat=%s, specVersion=%s (expected CycloneDX 1.5 or 1.6)',
					$this->describe(value: $bomFormat),
					$this->describe(value: $specVersion)
				)
			);
		}
	}//end assertSupportedCycloneDx()

	/**
	 * Whether a CycloneDX spec version is one this parser reads.
	 *
	 * @param mixed $specVersion The document's `specVersion`.
	 *
	 * @return bool True for a supported version string.
	 */
	private function isSupportedCycloneDxVersion(mixed $specVersion): bool {
		return is_string($specVersion) === true
			&& in_array($specVersion, self::SUPPORTED_CYCLONEDX_VERSIONS, true) === true;
	}//end isSupportedCycloneDxVersion()

	/**
	 * Parse an SPDX 2.x JSON document into the same normalized component DTO
	 * shape as {@see parse()} (name/version/purl/licenses). SPDX carries no
	 * VEX-equivalent block in this app's scope, so `vulnerabilities` is
	 * always empty for this entry point.
	 *
	 * @param string $json The raw uploaded file contents.
	 *
	 * @return array{components: array<int, array<string, mixed>>, vulnerabilities: array<int, array{cveId: string, componentBomRef: string}>}
	 *
	 * @throws UnsupportedSbomFormatException When the document is not valid
	 *                                        JSON or its `spdxVersion` is not
	 *                                        an SPDX-2.x document.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#notes
	 */
	public function parseSpdx(string $json): array {
		$data = $this->decode(json: $json);
		$spdxVersion = $data['spdxVersion'] ?? null;
		$isSpdx2 = is_string($spdxVersion) === true && str_starts_with($spdxVersion, 'SPDX-2.') === true;

		if ($isSpdx2 === false) {
			throw new UnsupportedSbomFormatException(
				message: sprintf(
					'Unsupported SPDX document version: %s (expected an SPDX-2.x document)',
					$this->describe(value: $spdxVersion)
				)
			);
		}

		$components = [];
		foreach (($data['packages'] ?? []) as $packageData) {
			if (is_array($packageData) === false) {
				continue;
			}

			$components[] = $this->normalizeSpdxPackage(package: $packageData);
		}

		return [
			'components' => $components,
			'vulnerabilities' => [],
		];
	}//end parseSpdx()

	/**
	 * Decode raw JSON text into an associative array, bounding depth and
	 * rejecting anything that isn't a JSON object/array at the top level.
	 *
	 * @param string $json The raw uploaded file contents.
	 *
	 * @return array<string, mixed> The decoded document.
	 *
	 * @throws UnsupportedSbomFormatException When decoding fails.
	 */
	private function decode(string $json): array {
		$decoded = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_BIGINT_AS_STRING);

		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			throw new UnsupportedSbomFormatException(
				message: 'Uploaded content is not a valid JSON document: ' . json_last_error_msg()
			);
		}

		return $decoded;
	}//end decode()

	/**
	 * Normalize one CycloneDX `components[]` entry into the shared component
	 * DTO shape.
	 *
	 * @param array<string, mixed> $component One raw CycloneDX component.
	 *
	 * @return array<string, mixed> The normalized component DTO.
	 */
	private function normalizeCycloneDxComponent(array $component): array {
		$licenses = $this->cycloneDxLicenses(licenseEntries: $component['licenses'] ?? []);
		$hashes = $this->cycloneDxHashes(hashEntries: $component['hashes'] ?? []);

		return [
			'name' => (string)($component['name'] ?? ''),
			'version' => (string)($component['version'] ?? ''),
			'purl' => (string)($component['purl'] ?? ''),
			'licenses' => $licenses,
			'type' => (string)($component['type'] ?? ''),
			'hashes' => $hashes,
			'bomRef' => (string)($component['bom-ref'] ?? ''),
		];
	}//end normalizeCycloneDxComponent()

	/**
	 * The licences of a CycloneDX component, one per entry that names one.
	 *
	 * @param mixed $licenseEntries The raw `licenses[]` of the component.
	 *
	 * @return array<int, string> The licence ids, names or expressions.
	 */
	private function cycloneDxLicenses(mixed $licenseEntries): array {
		$licenses = [];
		foreach ($licenseEntries as $licenseEntry) {
			if (is_array($licenseEntry) === false) {
				continue;
			}

			$license = $this->cycloneDxLicense(licenseEntry: $licenseEntry);
			if ($license !== null) {
				$licenses[] = $license;
			}
		}

		return $licenses;
	}//end cycloneDxLicenses()

	/**
	 * The licence an entry names: its licence id, else its licence name, else its expression.
	 *
	 * @param array<string, mixed> $licenseEntry One raw `licenses[]` entry.
	 *
	 * @return string|null The licence, or null when the entry names none.
	 */
	private function cycloneDxLicense(array $licenseEntry): ?string {
		$license = $licenseEntry['license']['id'] ?? $licenseEntry['license']['name'] ?? $licenseEntry['expression'] ?? null;
		if ($license === null) {
			return null;
		}

		return (string)$license;
	}//end cycloneDxLicense()

	/**
	 * The hashes of a CycloneDX component.
	 *
	 * @param mixed $hashEntries The raw `hashes[]` of the component.
	 *
	 * @return array<int, array{alg: string, value: string}> The hashes.
	 */
	private function cycloneDxHashes(mixed $hashEntries): array {
		$hashes = [];
		foreach ($hashEntries as $hashEntry) {
			if (is_array($hashEntry) === false) {
				continue;
			}

			$hashes[] = [
				'alg' => (string)($hashEntry['alg'] ?? ''),
				'value' => (string)($hashEntry['content'] ?? ''),
			];
		}

		return $hashes;
	}//end cycloneDxHashes()

	/**
	 * Normalize one SPDX `packages[]` entry into the shared component DTO
	 * shape.
	 *
	 * @param array<string, mixed> $package One raw SPDX package.
	 *
	 * @return array<string, mixed> The normalized component DTO.
	 */
	private function normalizeSpdxPackage(array $package): array {
		$purl = $this->spdxPurl(externalRefs: $package['externalRefs'] ?? []);
		$licenses = $this->spdxLicenses(package: $package);

		return [
			'name' => (string)($package['name'] ?? ''),
			'version' => (string)($package['versionInfo'] ?? ''),
			'purl' => $purl,
			'licenses' => $licenses,
			'type' => 'library',
			'hashes' => [],
			'bomRef' => (string)($package['SPDXID'] ?? ''),
		];
	}//end normalizeSpdxPackage()

	/**
	 * The locator of the first purl reference of an SPDX package.
	 *
	 * @param mixed $externalRefs The raw `externalRefs[]` of the package.
	 *
	 * @return string The purl, or '' when the package has none.
	 */
	private function spdxPurl(mixed $externalRefs): string {
		foreach ($externalRefs as $externalRef) {
			if (is_array($externalRef) === true && ($externalRef['referenceType'] ?? '') === 'purl') {
				return (string)($externalRef['referenceLocator'] ?? '');
			}
		}

		return '';
	}//end spdxPurl()

	/**
	 * The licence of an SPDX package: concluded, else declared, unless NOASSERTION.
	 *
	 * @param array<string, mixed> $package One raw SPDX package.
	 *
	 * @return array<int, string> The licence, or no licence.
	 */
	private function spdxLicenses(array $package): array {
		$license = $package['licenseConcluded'] ?? ($package['licenseDeclared'] ?? '');
		$licenses = [];
		if (is_string($license) === true && $license !== '' && strtoupper($license) !== 'NOASSERTION') {
			$licenses[] = $license;
		}

		return $licenses;
	}//end spdxLicenses()

	/**
	 * Extract `{cveId, componentBomRef}` pairs from a CycloneDX top-level
	 * `vulnerabilities[]` (VEX) block, one pair per `id` × `affects[].ref`
	 * combination.
	 *
	 * @param array<int, mixed> $vulnerabilities The raw `vulnerabilities[]` array.
	 *
	 * @return array<int, array{cveId: string, componentBomRef: string}> The extracted pairs.
	 */
	private function extractVexPairs(array $vulnerabilities): array {
		$pairs = [];
		foreach ($vulnerabilities as $vulnerability) {
			$cveId = self::vexCveId(vulnerability: $vulnerability);
			if ($cveId === '') {
				continue;
			}

			foreach (self::vexRefs(affects: ($vulnerability['affects'] ?? [])) as $ref) {
				$pairs[] = ['cveId' => $cveId, 'componentBomRef' => $ref];
			}
		}

		return $pairs;
	}//end extractVexPairs()

	/**
	 * The CVE id of one VEX entry, or '' when the entry has none.
	 *
	 * @param mixed $vulnerability One raw `vulnerabilities[]` entry.
	 *
	 * @return string The id.
	 */
	private static function vexCveId(mixed $vulnerability): string {
		if (is_array($vulnerability) === false) {
			return '';
		}

		$cveId = $vulnerability['id'] ?? null;
		if (is_string($cveId) === false) {
			return '';
		}

		return $cveId;
	}//end vexCveId()

	/**
	 * The component refs one VEX entry affects, skipping malformed ones.
	 *
	 * @param mixed $affects The raw `affects[]` of one entry.
	 *
	 * @return array<int, string> The non-empty refs, in order.
	 */
	private static function vexRefs(mixed $affects): array {
		$refs = [];
		foreach ($affects as $entry) {
			$ref = null;
			if (is_array($entry) === true) {
				$ref = ($entry['ref'] ?? null);
			}

			if (is_string($ref) === true && $ref !== '') {
				$refs[] = $ref;
			}
		}

		return $refs;
	}//end vexRefs()

	/**
	 * Render an arbitrary value for an error message.
	 *
	 * @param mixed $value The value to describe.
	 *
	 * @return string A human-readable description.
	 */
	private function describe(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		if ($value === null) {
			return '(none)';
		}

		return (string)json_encode($value);
	}//end describe()
}//end class
