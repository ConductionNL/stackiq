<?php

/**
 * Links GEMMA reference components and standard versions to their standards.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\ArchiMate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\ArchiMate;

use Psr\Log\LoggerInterface;

/**
 * Derives the GEMMA standard links from ArchiMate elements and relationships.
 *
 * A Referentiecomponent gets `recommendedStandards` and `mandatoryStandards` from
 * relationships whose Verbindingsrol is Aanbevolen or Verplicht, `standards` as
 * both combined, and `linkedStandardVersions` as the versions of those standards.
 * A Standaardversie gets `standard` from a Specialization relationship. Either
 * end of a relationship may be the standard.
 *
 * A linked object is referenced by its id when that is a uuid, otherwise by its
 * slug (the identifier without its "id-" prefix).
 *
 * Objects are in import shape: `section` ("element" or "relationship", the
 * classic import uses the plural),
 * `identifier`, `@self.id`, and the flattened ArchiMate properties.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class GemmaStandardLinker {

	/**
	 * Reads the GEMMA facts off one object.
	 *
	 * @var GemmaObjectReader
	 */
	private readonly GemmaObjectReader $reader;

	/**
	 * The index key per GEMMA type the linker reads.
	 */
	private const TYPE_INDEX = [
		'Referentiecomponent' => 'components',
		'Standaard'           => 'standards',
		'Standaardversie'     => 'versions',
	];

	/**
	 * The link property per Verbindingsrol (lower case).
	 */
	private const ROLE_PROPERTY = [
		'aanbevolen' => 'recommendedStandards',
		'verplicht'  => 'mandatoryStandards',
	];

	/**
	 * Build the linker.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
		$this->reader = new GemmaObjectReader();
	}//end __construct()

	/**
	 * Add the standard links to the reference components and standard versions.
	 *
	 * @param array<int|string, array<string, mixed>> $objects The objects in import shape.
	 *
	 * @return array<int|string, array<string, mixed>> The objects, with the links added.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function link(array $objects): array {
		$state = $this->index(objects: $objects);
		foreach ($objects as $object) {
			if ($this->reader->section(object: $object) === 'relationship') {
				$this->collect(relationship: $object, state: $state);
			}
		}

		$this->applyRoles(objects: $objects, state: $state);
		$this->applyVersions(objects: $objects, state: $state);

		$this->logger->info(
			'GEMMA standard links derived',
			[
				'referentiecomponenten' => count($state['components']),
				'standaarden'           => count($state['standards']),
				'standaardversies'      => count($state['versions']),
				'linked_components'     => count($state['roles']),
				'linked_versions'       => count($state['versionOf']),
			]
		);

		return $objects;
	}//end link()

	/**
	 * Index the GEMMA elements by identifier, with the reference each is linked by.
	 *
	 * @param array<int|string, array<string, mixed>> $objects The objects.
	 *
	 * @return array<string, array<string, mixed>> The state the relationship pass fills in.
	 */
	private function index(array $objects): array {
		$state = ['components' => [], 'standards' => [], 'versions' => [], 'refs' => [], 'roles' => [], 'versionOf' => []];
		foreach ($objects as $position => $object) {
			if ($this->reader->section(object: $object) !== 'element' || isset($object['identifier']) === false) {
				continue;
			}

			$key = self::TYPE_INDEX[$this->reader->gemmaType(object: $object) ?? ''] ?? null;
			if ($key === null) {
				continue;
			}

			$identifier = (string) $object['identifier'];
			$state[$key][$identifier]  = $position;
			$state['refs'][$identifier] = $this->reader->reference(object: $object);
		}

		return $state;
	}//end index()

	/**
	 * Record what one relationship links.
	 *
	 * @param array<string, mixed>                $relationship The relationship.
	 * @param array<string, array<string, mixed>> $state        The state to fill in.
	 *
	 * @return void
	 */
	private function collect(array $relationship, array &$state): void {
		$ends = [$this->reader->endpoint(relationship: $relationship, end: 'source'), $this->reader->endpoint(relationship: $relationship, end: 'target')];
		if (in_array(null, $ends, true) === true) {
			return;
		}

		$link = new GemmaRelationshipEnds();
		$link->relationship = $relationship;
		$link->ends = $ends;
		$this->collectRole(link: $link, state: $state);
		$this->collectVersion(link: $link, state: $state);
	}//end collect()

	/**
	 * Record the standard a reference component links in a role.
	 *
	 * @param GemmaRelationshipEnds               $link  The relationship and its ends.
	 * @param array<string, array<string, mixed>> $state The state to fill in.
	 *
	 * @return void
	 */
	private function collectRole(GemmaRelationshipEnds $link, array &$state): void {
		$component = $this->pair(ends: $link->ends, kinds: [$state['components'], $state['standards']]);
		$role      = $link->relationship['verbindingsrol'] ?? null;
		if ($component === null || $role === null) {
			return;
		}

		$state['roles'][$component[0]] ??= ['recommendedStandards' => [], 'mandatoryStandards' => []];
		$property = self::ROLE_PROPERTY[strtolower((string) $this->reader->roleText(role: $role))] ?? null;
		if ($property !== null) {
			$state['roles'][$component[0]][$property][] = $component[1];
		}
	}//end collectRole()

	/**
	 * Record the standard a standard version specialises.
	 *
	 * @param GemmaRelationshipEnds               $link  The relationship and its ends.
	 * @param array<string, array<string, mixed>> $state The state to fill in.
	 *
	 * @return void
	 */
	private function collectVersion(GemmaRelationshipEnds $link, array &$state): void {
		$version = $this->pair(ends: $link->ends, kinds: [$state['versions'], $state['standards']]);
		if ($version !== null && $this->reader->relationType(relationship: $link->relationship) === 'Specialization') {
			$state['versionOf'][$version[0]] = $version[1];
		}
	}//end collectVersion()

	/**
	 * Order a relationship's two ends as [first kind, second kind], in either direction.
	 *
	 * @param array{0: string, 1: string}                    $ends  The source and target identifiers.
	 * @param array{0: array<string, mixed>, 1: array<string, mixed>} $kinds The two indexes to match.
	 *
	 * @return array{0: string, 1: string}|null The ordered ends, or null when they are not these kinds.
	 */
	private function pair(array $ends, array $kinds): ?array {
		[$source, $target] = $ends;
		if (isset($kinds[0][$source], $kinds[1][$target]) === true) {
			return [$source, $target];
		}

		if (isset($kinds[1][$source], $kinds[0][$target]) === true) {
			return [$target, $source];
		}

		return null;
	}//end pair()

	/**
	 * Write the role links and the linked versions onto the reference components.
	 *
	 * @param array<int|string, array<string, mixed>> $objects The objects to update.
	 * @param array<string, array<string, mixed>>     $state   The collected links.
	 *
	 * @return void
	 */
	private function applyRoles(array &$objects, array $state): void {
		$versionsOf = [];
		foreach ($state['versionOf'] as $version => $standard) {
			$versionsOf[$standard][] = $version;
		}

		foreach ($state['roles'] as $component => $roles) {
			$recommended = array_values(array_unique($roles['recommendedStandards']));
			$mandatory   = array_values(array_unique($roles['mandatoryStandards']));
			$standards   = array_values(array_unique(array_merge($recommended, $mandatory)));

			$versions = [];
			foreach ($standards as $standard) {
				$versions = array_merge($versions, $versionsOf[$standard] ?? []);
			}

			$position = $state['components'][$component];
			$objects[$position]['recommendedStandards'] = $this->references(identifiers: $recommended, refs: $state['refs']);
			$objects[$position]['mandatoryStandards']   = $this->references(identifiers: $mandatory, refs: $state['refs']);
			$objects[$position]['standards']            = $this->references(identifiers: $standards, refs: $state['refs']);
			if ($versions !== []) {
				$objects[$position]['linkedStandardVersions'] = $this->references(identifiers: $versions, refs: $state['refs']);
			}
		}
	}//end applyRoles()

	/**
	 * Write each standard version's standard.
	 *
	 * @param array<int|string, array<string, mixed>> $objects The objects to update.
	 * @param array<string, array<string, mixed>>     $state   The collected links.
	 *
	 * @return void
	 */
	private function applyVersions(array &$objects, array $state): void {
		foreach ($state['versionOf'] as $version => $standard) {
			$objects[$state['versions'][$version]]['standard'] = $state['refs'][$standard];
		}
	}//end applyVersions()

	/**
	 * The references for a list of identifiers.
	 *
	 * @param array<int, string>    $identifiers The identifiers.
	 * @param array<string, string> $refs        The reference per identifier.
	 *
	 * @return array<int, string> The references, in order.
	 */
	private function references(array $identifiers, array $refs): array {
		return array_values(array_map(static fn (string $identifier): string => $refs[$identifier], $identifiers));
	}//end references()

}//end class
