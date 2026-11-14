<?php

/**
 * ArchiMate Model Comparator.
 *
 * Compares two ArchiMate exchange files by identifier, per category
 * (architecture-round-trip-check, REQ-ART-001). It is the judge of the
 * round-trip check: a source file and the file stackiq generates back from it
 * go in, and per category the counts and the missing, extra and changed
 * identifiers come out, each changed one with the fields that differ.
 *
 * Counting alone was rejected (design D1): equal counts hide one element lost
 * and another added, and say nothing about a relationship whose target moved.
 * The order of items in a file never counts. Pure: it reads two strings and
 * touches nothing else.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-001-stackiq-shall-compare-two-exchange-files-by-identifier-per-category
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;

/**
 * Compares two ArchiMate exchange files per category.
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-001-stackiq-shall-compare-two-exchange-files-by-identifier-per-category
 */
class ArchiMateModelComparator {

	/**
	 * The most examples a category reports; the counts are always complete.
	 */
	public const MAX_EXAMPLES = 50;

	/**
	 * The categories, in report order.
	 */
	public const CATEGORIES = ArchiMateModelReader::CATEGORIES;

	/**
	 * Constructor.
	 *
	 * @param ArchiMateModelReader $reader Reads an exchange file into its categories.
	 */
	public function __construct(
		private readonly ArchiMateModelReader $reader = new ArchiMateModelReader(),
	) {
	}//end __construct()

	/**
	 * Compare a source file with the file the round trip produced.
	 *
	 * @param string $sourceXml The exchange file as uploaded or exported before.
	 * @param string $resultXml The exchange file after the round trip.
	 *
	 * @return array{lossless: bool, categories: array<string, array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When either file is not XML.
	 *
	 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-001-stackiq-shall-compare-two-exchange-files-by-identifier-per-category
	 */
	public function compare(string $sourceXml, string $resultXml): array {
		$source = $this->reader->read(xml: $sourceXml, label: 'source');
		$result = $this->reader->read(xml: $resultXml, label: 'result');

		$categories = [];
		$lossless   = true;
		foreach (self::CATEGORIES as $category) {
			$report = $this->compareCategory(source: $source[$category], result: $result[$category]);
			$categories[$category] = $report;
			if ($report['missing'] > 0 || $report['extra'] > 0 || $report['changed'] > 0) {
				$lossless = false;
			}
		}

		return ['lossless' => $lossless, 'categories' => $categories];
	}//end compare()

	/**
	 * The report for one category.
	 *
	 * @param array<string, array{name: string, fields: array<string, string>}> $source The source items.
	 * @param array<string, array{name: string, fields: array<string, string>}> $result The result items.
	 *
	 * @return array<string, mixed>
	 */
	private function compareCategory(array $source, array $result): array {
		$missing = array_values(array_diff(array_keys($source), array_keys($result)));
		$extra   = array_values(array_diff(array_keys($result), array_keys($source)));
		$changed = [];
		foreach (array_intersect_key($source, $result) as $identifier => $item) {
			$fields = $this->differingFields(source: $item['fields'], result: $result[$identifier]['fields']);
			if ($fields !== []) {
				$changed[(string) $identifier] = $fields;
			}
		}

		$examples = [];
		foreach ($missing as $identifier) {
			$examples[] = ['kind' => 'missing', 'identifier' => (string) $identifier, 'name' => $source[$identifier]['name']];
		}

		foreach ($changed as $identifier => $fields) {
			$examples[] = ['kind' => 'changed', 'identifier' => $identifier, 'name' => $source[$identifier]['name'], 'fields' => $fields];
		}

		foreach ($extra as $identifier) {
			$examples[] = ['kind' => 'extra', 'identifier' => (string) $identifier, 'name' => $result[$identifier]['name']];
		}

		return [
			'source' => count($source),
			'result' => count($result),
			'missing' => count($missing),
			'extra' => count($extra),
			'changed' => count($changed),
			'examples' => array_slice($examples, 0, self::MAX_EXAMPLES),
		];
	}//end compareCategory()

	/**
	 * The names of the fields whose values differ, in sorted order.
	 *
	 * @param array<string, string> $source The source fields.
	 * @param array<string, string> $result The result fields.
	 *
	 * @return list<string>
	 */
	private function differingFields(array $source, array $result): array {
		$fields = [];
		foreach (array_unique(array_merge(array_keys($source), array_keys($result))) as $field) {
			if (($source[$field] ?? null) !== ($result[$field] ?? null)) {
				$fields[] = (string) $field;
			}
		}

		sort($fields);

		return $fields;
	}//end differingFields()
}//end class
