<?php

/**
 * The per-part size bound of a CMDB workbook, applied only to the parts the import can parse.
 *
 * PhpSpreadsheet builds the whole XML tree of every part it parses, so a part
 * that unpacks to more than the profile's `maxPartBytes` is refused before
 * anything is parsed. It parses only the sheets the import loads, though:
 * an export carries sheets the import never reads (an archive, a list of
 * resolver groups, the original data), and one of those may unpack to more
 * than the bound without costing the import anything. Such a part is let
 * through when every relationship in the package that may name it is the
 * worksheet relationship of a sheet that is not a source sheet. Any doubt
 * keeps the bound: a part that some other relationship may name, a
 * worksheet relationship no sheet refers to, or a sheet whose name matches
 * a source sheet, whatever its case. "May name" compares the last path
 * segment, case-insensitively and also without its first character, so it
 * covers every name PhpSpreadsheet tries for a target (root-relative,
 * `./`, case-insensitive, the Apache POI fallback, backslashes).
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
use XMLReader;

/**
 * Refuses a part beyond the per-part bound unless only unread sheets refer to it.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 */
class CmdbPartReferences {
	/**
	 * Refuse the first part beyond the limit that the import may parse.
	 *
	 * @param string $path The xlsx file.
	 * @param array<string, int> $oversized Part name => unpacked size, for the parts beyond the limit.
	 * @param array<int, string> $sourceSheets The sheet names the import reads.
	 * @param int $limit The profile's `maxPartBytes`.
	 * @param object $scanner PhpSpreadsheet's XmlScanner, which every part passes before it is read.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException WORKBOOK_TOO_LARGE for a part the import may parse, with `maxPartBytes`,
	 *                             `part`, `size` and, for a sheet, `sheet`.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function assertPartSizes(string $path, array $oversized, array $sourceSheets, int $limit, object $scanner): void {
		if ($oversized === []) {
			return;
		}

		$references = self::references(path: $path, scanner: $scanner, sourceSheets: $sourceSheets);
		foreach ($oversized as $part => $size) {
			$verdict = self::verdict(part: $part, references: $references);
			if ($verdict['unread'] === true) {
				continue;
			}

			$details = ['maxPartBytes' => $limit, 'part' => $part, 'size' => $size];
			if ($verdict['sheet'] !== null) {
				$details['sheet'] = $verdict['sheet'];
			}

			throw new CmdbImportException(
				errorCode: CmdbImportException::WORKBOOK_TOO_LARGE,
				message: 'A part of the workbook the import reads unpacks to more bytes than the profile allows',
				details: $details
			);
		}
	}//end assertPartSizes()

	/**
	 * Whether only unread sheets may name a part, and the sheet that names it.
	 *
	 * @param string $part The part's name in the package.
	 * @param array<int, array{keys: array<int, string>, sheet: ?string, unread: bool}> $references Every relationship.
	 *
	 * @return array{unread: bool, sheet: ?string}
	 */
	private static function verdict(string $part, array $references): array {
		$key = self::lastSegment(path: $part);
		$unread = false;
		$sheet = null;
		foreach ($references as $reference) {
			if (in_array($key, $reference['keys'], true) === false) {
				continue;
			}

			$sheet ??= $reference['sheet'];
			if ($reference['unread'] === false) {
				return ['unread' => false, 'sheet' => $sheet];
			}

			$unread = true;
		}

		return ['unread' => $unread, 'sheet' => $sheet];
	}//end verdict()

	/**
	 * Every relationship in the package: the names it may resolve to, its sheet, and whether that sheet is unread.
	 *
	 * Every part is read, whatever its name, for `Relationship` elements and
	 * for `sheet` elements. A worksheet relationship takes the names of the
	 * sheets that refer to its id from the part its relationships file
	 * belongs to (`dir/_rels/name.rels` belongs to `dir/name`).
	 *
	 * @param string $path The xlsx file.
	 * @param object $scanner PhpSpreadsheet's XmlScanner.
	 * @param array<int, string> $sourceSheets The sheet names the import reads.
	 *
	 * @return array<int, array{keys: array<int, string>, sheet: ?string, unread: bool}>
	 */
	private static function references(string $path, object $scanner, array $sourceSheets): array {
		$relationships = [];
		$sheets = [];
		(new CmdbWorkbookBounds())->eachPart(
			path: $path,
			scanner: $scanner,
			consume: static function (XMLReader $xml, string $name) use (&$relationships, &$sheets): void {
				self::collect(xml: $xml, name: $name, relationships: $relationships, sheets: $sheets);
			}
		);

		$read = array_map(static fn (string $sheet): string => mb_strtolower(trim($sheet)), $sourceSheets);
		$references = [];
		foreach ($relationships as $relationship) {
			$names = [];
			if ($relationship['worksheet'] === true) {
				$names = $sheets[$relationship['owner']][$relationship['id']] ?? [];
			}

			$unread = ($names !== []);
			foreach ($names as $sheetName) {
				if (in_array(mb_strtolower(trim($sheetName)), $read, true) === true) {
					$unread = false;
				}
			}

			$references[] = [
				'keys'   => self::keys(target: $relationship['target']),
				'sheet'  => ($names[0] ?? null),
				'unread' => $unread,
			];
		}

		return $references;
	}//end references()

	/**
	 * Collect the relationships and the sheet elements of one part.
	 *
	 * @param XMLReader $xml The reader, opened on the part.
	 * @param string $name The part's name in the package.
	 * @param array<int, array{owner: string, id: string, target: string, worksheet: bool}> $relationships Added to.
	 * @param array<string, array<string, array<int, string>>> $sheets Owner part => relationship id => sheet names; added to.
	 *
	 * @return void
	 */
	private static function collect(XMLReader $xml, string $name, array &$relationships, array &$sheets): void {
		$owner = self::owner(relsPart: $name);
		$self = mb_strtolower($name);
		while ($xml->read() === true) {
			if ($xml->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($xml->localName === 'Relationship') {
				$relationships[] = [
					'owner'     => $owner,
					'id'        => (string)$xml->getAttribute('Id'),
					'target'    => (string)$xml->getAttribute('Target'),
					'worksheet' => str_ends_with(mb_strtolower((string)$xml->getAttribute('Type')), '/worksheet'),
				];
			} elseif ($xml->localName === 'sheet' && $xml->getAttribute('name') !== null) {
				$sheets[$self][self::idAttribute(xml: $xml)][] = (string)$xml->getAttribute('name');
			}
		}
	}//end collect()

	/**
	 * The value of the element's `id` attribute in any namespace (PhpSpreadsheet reads `r:id`).
	 *
	 * @param XMLReader $xml The reader, on the element; it is moved back to it.
	 *
	 * @return string
	 */
	private static function idAttribute(XMLReader $xml): string {
		$id = '';
		if ($xml->moveToFirstAttribute() === true) {
			do {
				if ($xml->localName === 'id') {
					$id = $xml->value;
				}
			} while ($xml->moveToNextAttribute() === true);

			$xml->moveToElement();
		}

		return $id;
	}//end idAttribute()

	/**
	 * The part a relationships part belongs to, lower-cased: `dir/_rels/name.rels` belongs to `dir/name`.
	 *
	 * @param string $relsPart The relationships part's name.
	 *
	 * @return string An empty string for a part that is not named like a relationships part.
	 */
	private static function owner(string $relsPart): string {
		$path = mb_strtolower(str_replace('\\', '/', $relsPart));
		if (preg_match('#^(?:(.*)/)?_rels/([^/]+)\.rels$#', $path, $match) !== 1) {
			return '';
		}

		return ltrim($match[1] . '/' . $match[2], '/');
	}//end owner()

	/**
	 * The last path segments a relationship target may resolve to, lower-cased.
	 *
	 * @param string $target The relationship's `Target`.
	 *
	 * @return array<int, string>
	 */
	private static function keys(string $target): array {
		$keys = [];
		foreach ([$target, rawurldecode($target)] as $candidate) {
			$segment = self::lastSegment(path: $candidate);
			$keys[] = $segment;
			$keys[] = substr($segment, 1);
		}

		return array_values(array_unique($keys));
	}//end keys()

	/**
	 * The last segment of a path, after `/` or `\`, lower-cased.
	 *
	 * @param string $path The path.
	 *
	 * @return string
	 */
	private static function lastSegment(string $path): string {
		$segments = preg_split('#[/\\\\]#', $path);
		return mb_strtolower((string)end($segments));
	}//end lastSegment()
}//end class
