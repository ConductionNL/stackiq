<?php

/**
 * Bounds on the shared strings of a CMDB workbook, checked before PhpSpreadsheet parses it.
 *
 * PhpSpreadsheet builds the whole shared-strings table before it reads a
 * sheet, keeps every formatting run of a rich-text entry or of a cell's
 * inline string as objects, and gives every cell that references a shared
 * string its own copy of the text. A small file can therefore make it hold
 * far more than the file's size. CmdbWorkbookReader calls these checks after
 * the size checks and before any part is parsed; both stream every part of
 * the package with XMLReader, through PhpSpreadsheet's own XmlScanner, and
 * refuse with `WORKBOOK_TOO_LARGE` (413):
 *
 * - more shared strings than `maxSharedStrings`, each run counted as one;
 * - more referenced shared-string text than `maxReferencedStringBytes`.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use OCA\Stackiq\Exception\CmdbImportException;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Streams a workbook's parts to bound what PhpSpreadsheet can be made to hold by its shared strings.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Each helper is one small step of a streamed check;
 * together they pass the threshold, and splitting the two checks apart would duplicate the streaming.
 */
class CmdbWorkbookBounds {
	/**
	 * Refuse a shared-strings table with more entries than the limit, without building it.
	 *
	 * The table's `count` and `uniqueCount` attributes are written by the
	 * producer and can be wrong, so the entries are counted, streamed with
	 * XMLReader. PhpSpreadsheet reads the `<si>` children of whatever part the
	 * workbook's relationships name, whatever that part's name or root
	 * element, so every part of the package is counted. It also builds every
	 * formatting run of a rich-text entry as objects, kept for the whole
	 * load, and does the same for the runs of a cell's own text (an inline
	 * string, `<is>`), so each `<r>` inside an entry or an inline string
	 * counts as an entry too. The count runs over all parts together.
	 *
	 * @param string $path The xlsx file.
	 * @param int $limit The maximum number of shared strings.
	 * @param object $scanner PhpSpreadsheet's XmlScanner, which every part passes before it is parsed.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException WORKBOOK_TOO_LARGE above the limit.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function assertSharedStringCount(string $path, int $limit, object $scanner): void {
		$count = 0;
		self::streamParts(
			path: $path,
			scanner: $scanner,
			consume: static function (XMLReader $xml) use ($limit, &$count): void {
				$count = self::countSharedStrings(xml: $xml, limit: $limit, count: $count);
				if ($count > $limit) {
					throw new CmdbImportException(
						errorCode: CmdbImportException::WORKBOOK_TOO_LARGE,
						message: 'The shared-strings table holds more entries than the profile allows',
						details: ['maxSharedStrings' => $limit]
					);
				}
			}
		);
	}//end assertSharedStringCount()

	/**
	 * Add one part's `<si>` children of the root element and the `<r>` runs inside them or inside an `<is>`, stopping past the limit.
	 *
	 * @param XMLReader $xml The reader, opened on one part.
	 * @param int $limit The maximum number of shared strings.
	 * @param int $count The entries counted so far.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	private static function countSharedStrings(XMLReader $xml, int $limit, int $count): int {
		$textDepth = null;
		while ($count <= $limit && $xml->read() === true) {
			$count += self::entriesAt(xml: $xml, textDepth: $textDepth);
		}

		return $count;
	}//end countSharedStrings()

	/**
	 * The entries the node the reader is on adds: 1 for a shared string or a run inside a text, else 0.
	 *
	 * @param XMLReader $xml The reader, on a node.
	 * @param int|null $textDepth The depth of the shared or inline string the reader is inside, or null; kept up to date.
	 *
	 * @return int
	 */
	private static function entriesAt(XMLReader $xml, ?int &$textDepth): int {
		if (self::closesText(xml: $xml, textDepth: $textDepth) === true) {
			$textDepth = null;
			return 0;
		}

		if ($xml->nodeType !== XMLReader::ELEMENT) {
			return 0;
		}

		return self::elementEntries(xml: $xml, textDepth: $textDepth);
	}//end entriesAt()

	/**
	 * Whether the reader is on the end of the shared or inline string it is inside.
	 *
	 * @param XMLReader $xml The reader, on a node.
	 * @param int|null $textDepth The depth of the shared or inline string the reader is inside, or null.
	 *
	 * @return bool
	 */
	private static function closesText(XMLReader $xml, ?int $textDepth): bool {
		return $xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $textDepth;
	}//end closesText()

	/**
	 * The entries the element the reader is on adds: 1 for a shared string or a run inside a text, else 0.
	 *
	 * @param XMLReader $xml The reader, on an element.
	 * @param int|null $textDepth The depth of the shared or inline string the reader is inside, or null; set when
	 *                            the element opens one.
	 *
	 * @return int
	 */
	private static function elementEntries(XMLReader $xml, ?int &$textDepth): int {
		if ($textDepth === null && self::opensText(xml: $xml) === true) {
			$textDepth = self::depthUnlessEmpty(xml: $xml);
			return (int)($xml->localName === 'si');
		}

		return (int)($textDepth !== null && $xml->localName === 'r');
	}//end elementEntries()

	/**
	 * The depth of the element the reader is on, or null for an empty element, which has no end to wait for.
	 *
	 * @param XMLReader $xml The reader, on an element.
	 *
	 * @return int|null
	 */
	private static function depthUnlessEmpty(XMLReader $xml): ?int {
		if ($xml->isEmptyElement === true) {
			return null;
		}

		return $xml->depth;
	}//end depthUnlessEmpty()

	/**
	 * Whether the reader is on a text PhpSpreadsheet builds runs for: a shared string or an inline string.
	 *
	 * @param XMLReader $xml The reader, on an element.
	 *
	 * @return bool
	 */
	private static function opensText(XMLReader $xml): bool {
		return ($xml->depth === 1 && $xml->localName === 'si') || $xml->localName === 'is';
	}//end opensText()

	/**
	 * Refuse a workbook whose cells reference more shared-string text than the limit, before any sheet is parsed.
	 *
	 * PhpSpreadsheet gives every cell that references a shared string its own
	 * copy of the text, and clones every run of a rich-text string first, so a
	 * long or many-run string referenced by many cells costs memory and time
	 * per cell however small the file is. Each entry weighs the bytes of its
	 * text plus 16 per element in it (so a run weighs at least 32), and the
	 * weights of the entries every `t="s"` cell references are added up,
	 * streamed with XMLReader.
	 *
	 * The check charges at least what PhpSpreadsheet can build, never less:
	 * every part of the package is read, whatever its name, as a possible
	 * table and a possible sheet; entries are numbered per namespace and an
	 * index weighs the heaviest entry any table has there; every `<v>` of a
	 * shared-string cell is charged, read both as its own text and as all
	 * the text inside it; every cell counts, read or not; and the sum counts
	 * once per source sheet, because two source sheets may point at the same
	 * part.
	 *
	 * @param string $path The xlsx file.
	 * @param int $limit The maximum weight of the referenced shared strings.
	 * @param int $sheetCount The number of source sheets the profile reads.
	 * @param object $scanner PhpSpreadsheet's XmlScanner, which every part passes before it is parsed.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException WORKBOOK_TOO_LARGE above the limit.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function assertReferencedStringBytes(string $path, int $limit, int $sheetCount, object $scanner): void {
		$tables = [];
		self::streamParts(
			path: $path,
			scanner: $scanner,
			consume: static function (XMLReader $xml) use (&$tables): void {
				$tables[] = self::sharedStringWeights(xml: $xml);
			}
		);
		$weights = self::heaviestByIndex(tables: $tables);
		if ($weights === []) {
			return;
		}

		$budget = intdiv($limit, max(1, $sheetCount));
		$total = 0;
		self::streamParts(
			path: $path,
			scanner: $scanner,
			consume: static function (XMLReader $xml) use ($weights, $budget, $limit, &$total): void {
				$total = self::referencedWeight(xml: $xml, weights: $weights, total: $total, budget: $budget);
				if ($total > $budget) {
					throw new CmdbImportException(
						errorCode: CmdbImportException::WORKBOOK_TOO_LARGE,
						message: 'The cells of the workbook reference more shared-string text than the profile allows',
						details: ['maxReferencedStringBytes' => $limit]
					);
				}
			}
		);
	}//end assertReferencedStringBytes()

	/**
	 * The weight of each `<si>` child of a part's root element, by its position among the entries of its namespace.
	 *
	 * PhpSpreadsheet numbers the entries of one namespace; which one depends
	 * on the workbook, so every namespace is numbered and the heaviest entry
	 * at a position counts.
	 *
	 * @param XMLReader $xml The reader, opened on one part.
	 *
	 * @return array<int, int>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	private static function sharedStringWeights(XMLReader $xml): array {
		$strings = ['byNamespace' => [], 'positions' => [], 'namespace' => null];
		while ($xml->read() === true) {
			self::weighNode(xml: $xml, strings: $strings);
		}

		return self::heaviestByIndex(tables: $strings['byNamespace']);
	}//end sharedStringWeights()

	/**
	 * Add what the node the reader is on weighs to the shared string it is inside, opening a new one on an `<si>`.
	 *
	 * @param XMLReader $xml The reader, on a node.
	 * @param array<string, mixed> $strings The weights so far by namespace and position (byNamespace), the last
	 *                                      position per namespace (positions) and the namespace of the `<si>` the
	 *                                      reader is inside (namespace); updated.
	 *
	 * @return void
	 */
	private static function weighNode(XMLReader $xml, array &$strings): void {
		if ($xml->depth === 1 && $xml->nodeType === XMLReader::ELEMENT) {
			$strings['namespace'] = self::openSharedString(xml: $xml, strings: $strings);
		}

		$namespace = $strings['namespace'];
		if ($namespace !== null && $xml->depth >= 1) {
			$strings['byNamespace'][$namespace][$strings['positions'][$namespace]] += self::nodeWeight(xml: $xml);
		}
	}//end weighNode()

	/**
	 * Number the `<si>` the reader is on within its namespace and start its weight at 0.
	 *
	 * @param XMLReader $xml The reader, on a child element of the part's root.
	 * @param array<string, mixed> $strings The weights and positions so far; updated.
	 *
	 * @return string|null The namespace of the `<si>`, or null for any other element.
	 */
	private static function openSharedString(XMLReader $xml, array &$strings): ?string {
		if ($xml->localName !== 'si') {
			return null;
		}

		$namespace = $xml->namespaceURI;
		$strings['positions'][$namespace] = (($strings['positions'][$namespace] ?? -1) + 1);
		$strings['byNamespace'][$namespace][$strings['positions'][$namespace]] = 0;

		return $namespace;
	}//end openSharedString()

	/**
	 * Merge weight tables, keeping the heaviest weight at each index.
	 *
	 * @param array<array-key, array<int, int>> $tables The tables.
	 *
	 * @return array<int, int>
	 */
	private static function heaviestByIndex(array $tables): array {
		$merged = [];
		foreach ($tables as $table) {
			foreach ($table as $index => $weight) {
				$merged[$index] = max(($merged[$index] ?? 0), $weight);
			}
		}

		return $merged;
	}//end heaviestByIndex()

	/**
	 * What one node inside a shared string adds to its weight: 16 for an element, the bytes of a text.
	 *
	 * @param XMLReader $xml The reader, on the node.
	 *
	 * @return int
	 */
	private static function nodeWeight(XMLReader $xml): int {
		if ($xml->nodeType === XMLReader::ELEMENT) {
			return 16;
		}

		if (self::isText(xml: $xml) === true) {
			return strlen($xml->value);
		}

		return 0;
	}//end nodeWeight()

	/**
	 * Add the weights of the shared strings the `t="s"` cells of one part reference, stopping past the budget.
	 *
	 * Every `<v>` after a shared-string cell opens, up to the next cell, is
	 * charged: PhpSpreadsheet reads the cell's first `<v>` of its namespace,
	 * and charging all of them never charges less.
	 *
	 * @param XMLReader $xml The reader, opened on one part.
	 * @param array<int, int> $weights The weight of each shared string, by index.
	 * @param int $total The weight counted so far.
	 * @param int $budget The weight above which the workbook is refused.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	private static function referencedWeight(XMLReader $xml, array $weights, int $total, int $budget): int {
		$shared = false;
		while ($total <= $budget && $xml->read() === true) {
			$shared = self::inSharedStringCell(xml: $xml, shared: $shared);
			if ($shared === true && self::isElementNamed(xml: $xml, name: 'v') === true) {
				$total += self::valueWeight(xml: $xml, weights: $weights);
			}
		}

		return $total;
	}//end referencedWeight()

	/**
	 * Whether the reader is inside a shared-string cell: a `<c>` element decides it, any other node keeps it.
	 *
	 * @param XMLReader $xml The reader, on a node.
	 * @param bool $shared Whether the reader was inside a shared-string cell before this node.
	 *
	 * @return bool
	 */
	private static function inSharedStringCell(XMLReader $xml, bool $shared): bool {
		if (self::isElementNamed(xml: $xml, name: 'c') === true) {
			return $xml->getAttribute('t') === 's';
		}

		return $shared;
	}//end inSharedStringCell()

	/**
	 * Whether the reader is on an element with this local name.
	 *
	 * @param XMLReader $xml The reader, on a node.
	 * @param string $name The local name.
	 *
	 * @return bool
	 */
	private static function isElementNamed(XMLReader $xml, string $name): bool {
		return $xml->nodeType === XMLReader::ELEMENT && $xml->localName === $name;
	}//end isElementNamed()

	/**
	 * The weight of the shared string a `<v>` element points at.
	 *
	 * PhpSpreadsheet casts the element's own text to int; all the text inside
	 * it can differ when it holds child elements, so both are read and the
	 * heavier entry counts.
	 *
	 * @param XMLReader $xml The reader, on the `<v>` element; it is left on its end.
	 * @param array<int, int> $weights The weight of each shared string, by index.
	 *
	 * @return int
	 */
	private static function valueWeight(XMLReader $xml, array $weights): int {
		$texts = self::valueTexts(xml: $xml);

		return max(($weights[(int)$texts['own']] ?? 0), ($weights[(int)$texts['all']] ?? 0));
	}//end valueWeight()

	/**
	 * The element's own text and all the text inside it.
	 *
	 * @param XMLReader $xml The reader, on the `<v>` element; it is left on its end.
	 *
	 * @return array{depth: int, own: string, all: string}
	 */
	private static function valueTexts(XMLReader $xml): array {
		$texts = ['depth' => $xml->depth, 'own' => '', 'all' => ''];
		if ($xml->isEmptyElement === true) {
			return $texts;
		}

		while ($xml->read() === true && $xml->depth > $texts['depth']) {
			$texts = self::withText(xml: $xml, texts: $texts);
		}

		return $texts;
	}//end valueTexts()

	/**
	 * Add the text node the reader is on to all the text, and to the own text when it is a direct child.
	 *
	 * @param XMLReader $xml The reader, inside the `<v>` element.
	 * @param array{depth: int, own: string, all: string} $texts The `<v>` element's depth and its texts so far.
	 *
	 * @return array{depth: int, own: string, all: string}
	 */
	private static function withText(XMLReader $xml, array $texts): array {
		if (self::isText(xml: $xml) === false) {
			return $texts;
		}

		$texts['all'] .= $xml->value;
		if ($xml->depth === ($texts['depth'] + 1)) {
			$texts['own'] .= $xml->value;
		}

		return $texts;
	}//end withText()

	/**
	 * Whether the reader is on a text node: text, CDATA or whitespace.
	 *
	 * @param XMLReader $xml The reader.
	 *
	 * @return bool
	 */
	private static function isText(XMLReader $xml): bool {
		return in_array($xml->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true);
	}//end isText()

	/**
	 * Stream every part of the package through a callback, with the part's name, as PhpSpreadsheet would parse it.
	 *
	 * @param string $path The xlsx file.
	 * @param object $scanner PhpSpreadsheet's XmlScanner.
	 * @param callable(XMLReader, string): void $consume Reads one opened part; gets the reader and the part's name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function eachPart(string $path, object $scanner, callable $consume): void {
		self::streamParts(path: $path, scanner: $scanner, consume: $consume);
	}//end eachPart()

	/**
	 * Stream every part of the package through a callback, as PhpSpreadsheet would parse it.
	 *
	 * Parts are read by index, so a part counts whatever its name, and each
	 * passes PhpSpreadsheet's XmlScanner first, which also converts its
	 * encoding. A part the scanner refuses, or that is not XML, is skipped:
	 * PhpSpreadsheet cannot read it either. Network access and entity
	 * substitution stay off; libxml errors are kept from the log and
	 * cleared, also when the callback throws.
	 *
	 * @param string $path The xlsx file.
	 * @param object $scanner PhpSpreadsheet's XmlScanner.
	 * @param callable(XMLReader, string): void $consume Reads one opened part; gets the reader and the part's name.
	 *
	 * @return void
	 */
	private static function streamParts(string $path, object $scanner, callable $consume): void {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			return;
		}

		try {
			for ($index = 0; $index < $zip->numFiles; $index++) {
				$previous = libxml_use_internal_errors(true);
				try {
					$xml = self::openPart(zip: $zip, index: $index, scanner: $scanner);
					if ($xml !== null) {
						$consume($xml, (string)$zip->getNameIndex($index));
						$xml->close();
					}
				} finally {
					libxml_clear_errors();
					libxml_use_internal_errors($previous);
				}
			}
		} finally {
			$zip->close();
		}
	}//end streamParts()

	/**
	 * Open one part of the package with XMLReader, after PhpSpreadsheet's XmlScanner.
	 *
	 * @param ZipArchive $zip The open package.
	 * @param int $index The part's index.
	 * @param object $scanner PhpSpreadsheet's XmlScanner.
	 *
	 * @return XMLReader|null Null for an empty part, or one the scanner refuses.
	 */
	private static function openPart(ZipArchive $zip, int $index, object $scanner): ?XMLReader {
		$content = $zip->getFromIndex($index);
		if (is_string($content) === false || $content === '') {
			return null;
		}

		$scanned = self::scanned(scanner: $scanner, content: $content);
		if ($scanned === null) {
			return null;
		}

		return self::readerOf(content: $scanned);
	}//end openPart()

	/**
	 * The part's content after PhpSpreadsheet's XmlScanner, or null when the scanner refuses it.
	 *
	 * @param object $scanner PhpSpreadsheet's XmlScanner.
	 * @param string $content The part's content.
	 *
	 * @return string|null
	 */
	private static function scanned(object $scanner, string $content): ?string {
		try {
			return (string)$scanner->scan($content);
		} catch (Throwable) {
			return null;
		}
	}//end scanned()

	/**
	 * An XMLReader on the content, without network access, or null when it is not XML.
	 *
	 * @param string $content The scanned content.
	 *
	 * @return XMLReader|null
	 */
	private static function readerOf(string $content): ?XMLReader {
		$xml = new XMLReader();
		if ($xml->XML($content, null, LIBXML_NONET) === false) {
			return null;
		}

		return $xml;
	}//end readerOf()
}//end class
