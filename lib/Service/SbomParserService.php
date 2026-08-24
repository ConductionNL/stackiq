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

		$bomFormat = $data['bomFormat'] ?? null;
		$specVersion = $data['specVersion'] ?? null;
		$isCycloneDx = $bomFormat === 'CycloneDX';
		$isSupportedVersion = is_string($specVersion) === true
			&& in_array($specVersion, self::SUPPORTED_CYCLONEDX_VERSIONS, true) === true;

		if ($isCycloneDx === false || $isSupportedVersion === false) {
			throw new UnsupportedSbomFormatException(
				message: sprintf(
					'Unsupported SBOM format/version: bomFormat=%s, specVersion=%s (expected CycloneDX 1.5 or 1.6)',
					$this->describe(value: $bomFormat),
					$this->describe(value: $specVersion)
				)
			);
		}

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
		$licenses = [];
		foreach (($component['licenses'] ?? []) as $licenseEntry) {
			if (is_array($licenseEntry) === false) {
				continue;
			}

			if (isset($licenseEntry['license']['id']) === true) {
				$licenses[] = (string)$licenseEntry['license']['id'];
			} elseif (isset($licenseEntry['license']['name']) === true) {
				$licenses[] = (string)$licenseEntry['license']['name'];
			} elseif (isset($licenseEntry['expression']) === true) {
				$licenses[] = (string)$licenseEntry['expression'];
			}
		}

		$hashes = [];
		foreach (($component['hashes'] ?? []) as $hashEntry) {
			if (is_array($hashEntry) === false) {
				continue;
			}

			$hashes[] = [
				'alg' => (string)($hashEntry['alg'] ?? ''),
				'value' => (string)($hashEntry['content'] ?? ''),
			];
		}

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
	 * Normalize one SPDX `packages[]` entry into the shared component DTO
	 * shape.
	 *
	 * @param array<string, mixed> $package One raw SPDX package.
	 *
	 * @return array<string, mixed> The normalized component DTO.
	 */
	private function normalizeSpdxPackage(array $package): array {
		$purl = '';
		foreach (($package['externalRefs'] ?? []) as $externalRef) {
			if (is_array($externalRef) === true && ($externalRef['referenceType'] ?? '') === 'purl') {
				$purl = (string)($externalRef['referenceLocator'] ?? '');
				break;
			}
		}

		$license = $package['licenseConcluded'] ?? ($package['licenseDeclared'] ?? '');
		$licenses = [];
		if (is_string($license) === true && $license !== '' && strtoupper($license) !== 'NOASSERTION') {
			$licenses[] = $license;
		}

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
			if (is_array($vulnerability) === false) {
				continue;
			}

			$cveId = $vulnerability['id'] ?? null;
			if (is_string($cveId) === false || $cveId === '') {
				continue;
			}

			foreach (($vulnerability['affects'] ?? []) as $affects) {
				if (is_array($affects) === false) {
					continue;
				}

				$ref = $affects['ref'] ?? null;
				if (is_string($ref) === false || $ref === '') {
					continue;
				}

				$pairs[] = [
					'cveId' => $cveId,
					'componentBomRef' => $ref,
				];
			}
		}//end foreach

		return $pairs;
	}//end extractVexPairs()

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
