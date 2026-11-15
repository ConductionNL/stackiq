<?php

/**
 * Stackiq ViewImageService.
 *
 * Draws an architecture view as one standalone SVG image from its stored
 * geometry: a box per node at its stored position and size, with its name and
 * ArchiMate type, children inside their parents, and a line per connection
 * through its bendpoints with the arrowhead of its relation type. A caption
 * names the view and the date.
 *
 * The file is read outside Nextcloud, in a browser or an office suite, so the
 * colours come from the ArchiMate layer convention in one constant instead of
 * the theme. Every text taken from the view is escaped, and the SVG holds no
 * script, no external reference and no foreignObject.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Renders a stored view as SVG.
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
 */
class ViewImageService {

	/**
	 * Space around the drawing, in pixels.
	 *
	 * @var integer
	 */
	private const MARGIN = 20;

	/**
	 * Height of the caption band under the drawing, in pixels.
	 *
	 * @var integer
	 */
	private const CAPTION_HEIGHT = 40;

	/**
	 * The ArchiMate layer colours, keyed by layer.
	 *
	 * @var array<string, string>
	 */
	private const PALETTE = [
		'business' => '#ffffb5',
		'application' => '#b5ffff',
		'technology' => '#c9e7b7',
		'motivation' => '#ccccff',
		'strategy' => '#f5deaa',
		'implementation' => '#ffe0e0',
		'other' => '#ffffff',
	];

	/**
	 * Type-name prefixes per layer, English and Dutch, matched lowercased and without spaces, in this order.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const LAYER_PREFIXES = [
		'business' => ['business', 'bedrijfs', 'contract', 'representation', 'product'],
		'application' => ['application', 'applicatie', 'dataobject', 'gegevensobject'],
		'technology' => [
			'technology', 'technologie', 'node', 'device', 'systemsoftware', 'communicationnetwork',
			'path', 'artifact', 'artefact', 'equipment', 'facility', 'distributionnetwork', 'material',
		],
		// Strategy before motivation: `valuestream` must not read as `value`.
		'strategy' => ['resource', 'capability', 'valuestream', 'courseofaction'],
		'motivation' => [
			'stakeholder', 'driver', 'assessment', 'goal', 'outcome', 'principle', 'requirement',
			'constraint', 'meaning', 'value', 'doel', 'principe', 'eis',
		],
		'implementation' => ['workpackage', 'deliverable', 'implementationevent', 'plateau', 'gap'],
	];

	/**
	 * Per relation type: the marker at the target end, the marker at the source end, and the dash pattern.
	 *
	 * @var array<string, array{end: string, start: string, dash: string}>
	 */
	private const RELATION_STYLES = [
		'serving' => ['end' => 'open', 'start' => '', 'dash' => ''],
		'flow' => ['end' => 'filled', 'start' => '', 'dash' => '6 3'],
		'triggering' => ['end' => 'filled', 'start' => '', 'dash' => ''],
		'access' => ['end' => 'open', 'start' => '', 'dash' => '2 3'],
		'influence' => ['end' => 'open', 'start' => '', 'dash' => '6 3'],
		'realization' => ['end' => 'hollow', 'start' => '', 'dash' => '6 3'],
		'specialization' => ['end' => 'hollow', 'start' => '', 'dash' => ''],
		'assignment' => ['end' => 'filled', 'start' => 'dot', 'dash' => ''],
		'aggregation' => ['end' => '', 'start' => 'diamond-hollow', 'dash' => ''],
		'composition' => ['end' => '', 'start' => 'diamond-filled', 'dash' => ''],
		'association' => ['end' => '', 'start' => '', 'dash' => ''],
	];

	/**
	 * Draw a view as SVG.
	 *
	 * @param array<string, mixed>   $view The view: `name`, `nodes` and `viewRelationships`
	 *                                     (also read from `xml` when only stored there).
	 * @param DateTimeInterface|null $at   The date in the caption; now when omitted.
	 *
	 * @return string The SVG document.
	 *
	 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
	 */
	public function renderSvg(array $view, ?DateTimeInterface $at = null): string {
		$nodes = $this->nodesOf(view: $view);
		$boxes = [];
		foreach ($nodes as $node) {
			$boxes[(string)$node['viewNodeId']] = $node;
		}

		$connections = $this->connectionsOf(view: $view);
		[$width, $height] = $this->extent(nodes: $nodes, connections: $connections);

		$name = (string)($view['name'] ?? '');
		$date = ($at ?? new DateTimeImmutable())->format('Y-m-d');
		$totalHeight = ($height + self::CAPTION_HEIGHT);

		$svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$svg .= sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="0 0 %s %s" font-family="Arial, sans-serif">',
			$this->num(value: $width),
			$this->num(value: $totalHeight),
			$this->num(value: $width),
			$this->num(value: $totalHeight)
		) . "\n";
		$svg .= '<title>' . $this->escape(text: $name) . '</title>' . "\n";
		$svg .= $this->markerDefinitions();
		$svg .= '<rect x="0" y="0" width="' . $this->num(value: $width) . '" height="' . $this->num(value: $totalHeight) . '" fill="#ffffff"/>' . "\n";

		// Parents before children, so a child is painted on top of its parent.
		foreach ($this->paintOrder(nodes: $nodes) as $node) {
			$svg .= $this->nodeSvg(node: $node);
		}

		foreach ($connections as $connection) {
			$svg .= $this->connectionSvg(connection: $connection, boxes: $boxes);
		}

		$svg .= sprintf(
			'<text x="%s" y="%s" font-size="12" fill="#222222">%s</text>',
			self::MARGIN,
			$this->num(value: $height + 24),
			$this->escape(text: $name . ' · ' . $date . ' · drawn by stackiq')
		) . "\n";

		return $svg . '</svg>' . "\n";
	}//end renderSvg()

	/**
	 * The drawable nodes: those with an id and a size.
	 *
	 * @param array<string, mixed> $view The view.
	 *
	 * @return array<int, array<string, mixed>> The nodes, with numeric geometry.
	 */
	private function nodesOf(array $view): array {
		$raw = ($view['nodes'] ?? ($view['xml']['nodes'] ?? []));
		if (is_array($raw) === false) {
			return [];
		}

		$nodes = [];
		foreach ($raw as $node) {
			if (is_array($node) === false || (string)($node['viewNodeId'] ?? '') === '') {
				continue;
			}

			$node['x'] = (float)($node['x'] ?? 0);
			$node['y'] = (float)($node['y'] ?? 0);
			$node['width'] = max(1.0, (float)($node['width'] ?? 100));
			$node['height'] = max(1.0, (float)($node['height'] ?? 50));
			$nodes[] = $node;
		}

		return $nodes;
	}//end nodesOf()

	/**
	 * The connections of the view.
	 *
	 * @param array<string, mixed> $view The view.
	 *
	 * @return array<int, array<string, mixed>> The connections.
	 */
	private function connectionsOf(array $view): array {
		$raw = ($view['viewRelationships'] ?? ($view['xml']['viewRelationships'] ?? []));
		if (is_array($raw) === false) {
			return [];
		}

		return array_values(array_filter($raw, 'is_array'));
	}//end connectionsOf()

	/**
	 * The drawing's width and height: the furthest node edge or bendpoint, plus the margin.
	 *
	 * @param array<int, array<string, mixed>> $nodes       The nodes.
	 * @param array<int, array<string, mixed>> $connections The connections.
	 *
	 * @return array{0: float, 1: float} Width and height.
	 */
	private function extent(array $nodes, array $connections): array {
		$right = 0.0;
		$bottom = 0.0;
		foreach ($nodes as $node) {
			$right = max($right, ($node['x'] + $node['width']));
			$bottom = max($bottom, ($node['y'] + $node['height']));
		}

		foreach ($connections as $connection) {
			foreach ($this->bendpointsOf(connection: $connection) as [$x, $y]) {
				$right = max($right, $x);
				$bottom = max($bottom, $y);
			}
		}

		return [($right + self::MARGIN), ($bottom + self::MARGIN)];
	}//end extent()

	/**
	 * The nodes ordered so that every parent comes before its children.
	 *
	 * @param array<int, array<string, mixed>> $nodes The nodes.
	 *
	 * @return array<int, array<string, mixed>> The nodes in paint order.
	 */
	private function paintOrder(array $nodes): array {
		$depth = [];
		$byId = [];
		foreach ($nodes as $node) {
			$byId[(string)$node['viewNodeId']] = $node;
		}

		foreach ($nodes as $index => $node) {
			$level = 0;
			$parent = (string)($node['parent'] ?? '');
			$seen = [];
			while ($parent !== '' && isset($byId[$parent]) === true && isset($seen[$parent]) === false) {
				$seen[$parent] = true;
				$level++;
				$parent = (string)($byId[$parent]['parent'] ?? '');
			}

			$depth[$index] = $level;
		}

		$order = array_keys($nodes);
		usort(
			$order,
			static fn (int $a, int $b): int => ([$depth[$a], $a] <=> [$depth[$b], $b])
		);

		return array_map(static fn (int $index): array => $nodes[$index], $order);
	}//end paintOrder()

	/**
	 * One node: its box, its name and its type.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return string The SVG fragment.
	 */
	private function nodeSvg(array $node): string {
		$type = (string)($node['type'] ?? '');
		$name = (string)($node['name'] ?? '');
		$svg = sprintf(
			'<rect x="%s" y="%s" width="%s" height="%s" rx="3" fill="%s" stroke="#5c5c5c" stroke-width="1"/>',
			$this->num(value: $node['x']),
			$this->num(value: $node['y']),
			$this->num(value: $node['width']),
			$this->num(value: $node['height']),
			self::PALETTE[$this->layerOf(type: $type)]
		) . "\n";

		if ($name !== '') {
			$svg .= sprintf(
				'<text x="%s" y="%s" font-size="12" fill="#000000">%s</text>',
				$this->num(value: $node['x'] + 6),
				$this->num(value: $node['y'] + 16),
				$this->escape(text: $name)
			) . "\n";
		}

		if ($type !== '' && $type !== 'label' && $type !== 'element') {
			$svg .= sprintf(
				'<text x="%s" y="%s" font-size="9" fill="#555555">%s</text>',
				$this->num(value: $node['x'] + 6),
				$this->num(value: $node['y'] + 28),
				$this->escape(text: $type)
			) . "\n";
		}

		return $svg;
	}//end nodeSvg()

	/**
	 * The ArchiMate layer of an element type, for its colour.
	 *
	 * @param string $type The node type, as stored.
	 *
	 * @return string A key of PALETTE.
	 */
	private function layerOf(string $type): string {
		$key = strtolower(str_replace([' ', '-', '_'], '', $type));
		foreach (self::LAYER_PREFIXES as $layer => $prefixes) {
			foreach ($prefixes as $prefix) {
				if (str_starts_with($key, $prefix) === true) {
					return $layer;
				}
			}
		}

		return 'other';
	}//end layerOf()

	/**
	 * One connection: a polyline from the source box edge through the bendpoints to the target box edge.
	 *
	 * @param array<string, mixed>                $connection The connection.
	 * @param array<string, array<string, mixed>> $boxes      The nodes by view node id.
	 *
	 * @return string The SVG fragment, or '' when an end is not on the view.
	 */
	private function connectionSvg(array $connection, array $boxes): string {
		$source = ($boxes[(string)($connection['sourceId'] ?? '')] ?? null);
		$target = ($boxes[(string)($connection['targetId'] ?? '')] ?? null);
		if ($source === null || $target === null) {
			return '';
		}

		$bends = $this->bendpointsOf(connection: $connection);
		$first = ($bends[0] ?? $this->centre(box: $target));
		$last = ($bends[(count($bends) - 1)] ?? $this->centre(box: $source));

		$points = array_merge(
			[$this->edgePoint(box: $source, toward: $first)],
			$bends,
			[$this->edgePoint(box: $target, toward: $last)]
		);

		$style = $this->relationStyle(type: (string)($connection['type'] ?? ''));
		$attributes = '';
		if ($style['dash'] !== '') {
			$attributes .= ' stroke-dasharray="' . $style['dash'] . '"';
		}

		if ($style['end'] !== '') {
			$attributes .= ' marker-end="url(#end-' . $style['end'] . ')"';
		}

		if ($style['start'] !== '') {
			$attributes .= ' marker-start="url(#start-' . $style['start'] . ')"';
		}

		$path = [];
		foreach ($points as [$x, $y]) {
			$path[] = 'L' . $this->num(value: $x) . ' ' . $this->num(value: $y);
		}

		// The path starts with a move to its first point.
		$path[0] = 'M' . substr($path[0], 1);

		return '<path d="' . implode(' ', $path) . '" fill="none" stroke="#333333" stroke-width="1.2"' . $attributes . '/>' . "\n";
	}//end connectionSvg()

	/**
	 * The drawing style of a relation type; an unknown type is drawn as an association.
	 *
	 * @param string $type The stored relation type, e.g. `serving` or `ServingRelationship`.
	 *
	 * @return array{end: string, start: string, dash: string}
	 */
	private function relationStyle(string $type): array {
		$key = strtolower((string)preg_replace('/relationship$/i', '', $type));
		return (self::RELATION_STYLES[$key] ?? self::RELATION_STYLES['association']);
	}//end relationStyle()

	/**
	 * The bendpoints of a connection.
	 *
	 * @param array<string, mixed> $connection The connection.
	 *
	 * @return array<int, array{0: float, 1: float}> The points.
	 */
	private function bendpointsOf(array $connection): array {
		$points = [];
		foreach ((array)($connection['bendpoints'] ?? []) as $point) {
			if (is_array($point) === true && isset($point['x'], $point['y']) === true) {
				$points[] = [(float)$point['x'], (float)$point['y']];
			}
		}

		return $points;
	}//end bendpointsOf()

	/**
	 * The centre of a box.
	 *
	 * @param array<string, mixed> $box The node.
	 *
	 * @return array{0: float, 1: float}
	 */
	private function centre(array $box): array {
		return [($box['x'] + ($box['width'] / 2)), ($box['y'] + ($box['height'] / 2))];
	}//end centre()

	/**
	 * Where the line from a box's centre toward a point leaves the box.
	 *
	 * @param array<string, mixed>      $box    The node.
	 * @param array{0: float, 1: float} $toward The point the line heads for.
	 *
	 * @return array{0: float, 1: float}
	 */
	private function edgePoint(array $box, array $toward): array {
		[$cx, $cy] = $this->centre(box: $box);
		$dx = ($toward[0] - $cx);
		$dy = ($toward[1] - $cy);
		if ($dx === 0.0 && $dy === 0.0) {
			return [$cx, $cy];
		}

		$halfWidth = ($box['width'] / 2);
		$halfHeight = ($box['height'] / 2);
		$scaleX = INF;
		if ($dx !== 0.0) {
			$scaleX = ($halfWidth / abs($dx));
		}

		$scaleY = INF;
		if ($dy !== 0.0) {
			$scaleY = ($halfHeight / abs($dy));
		}

		$scale = min($scaleX, $scaleY, 1.0);
		return [($cx + ($dx * $scale)), ($cy + ($dy * $scale))];
	}//end edgePoint()

	/**
	 * The arrowhead and tail markers, drawn inline.
	 *
	 * @return string The defs block.
	 */
	private function markerDefinitions(): string {
		return '<defs>'
			. '<marker id="end-open" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto">'
			. '<path d="M0 0 L10 5 L0 10" fill="none" stroke="#333333"/></marker>'
			. '<marker id="end-filled" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto">'
			. '<path d="M0 0 L10 5 L0 10 Z" fill="#333333"/></marker>'
			. '<marker id="end-hollow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="10" markerHeight="10" orient="auto">'
			. '<path d="M0 0 L10 5 L0 10 Z" fill="#ffffff" stroke="#333333"/></marker>'
			. '<marker id="start-dot" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="6" markerHeight="6" orient="auto">'
			. '<circle cx="5" cy="5" r="4" fill="#333333"/></marker>'
			. '<marker id="start-diamond-hollow" viewBox="0 0 12 8" refX="1" refY="4" markerWidth="12" markerHeight="8" orient="auto">'
			. '<path d="M1 4 L6 0 L11 4 L6 8 Z" fill="#ffffff" stroke="#333333"/></marker>'
			. '<marker id="start-diamond-filled" viewBox="0 0 12 8" refX="1" refY="4" markerWidth="12" markerHeight="8" orient="auto">'
			. '<path d="M1 4 L6 0 L11 4 L6 8 Z" fill="#333333"/></marker>'
			. '</defs>' . "\n";
	}//end markerDefinitions()

	/**
	 * Escape a text for SVG content or an attribute.
	 *
	 * @param string $text The text.
	 *
	 * @return string The escaped text.
	 */
	private function escape(string $text): string {
		return htmlspecialchars($text, (ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE), 'UTF-8');
	}//end escape()

	/**
	 * A number as written in the SVG: at most one decimal.
	 *
	 * @param float|int $value The number.
	 *
	 * @return string The written number.
	 */
	private function num(float|int $value): string {
		$rounded = round((float)$value, 1);
		if ($rounded === floor($rounded)) {
			return (string)(int)$rounded;
		}

		return (string)$rounded;
	}//end num()
}//end class
