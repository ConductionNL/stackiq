<?php

/**
 * UsageClassificationService
 *
 * The GEMMA suggestion for a usage's BIO classification and the anonymous
 * cross-organisation summary of an application's classifications
 * (security-baseline-classification REQ-BCL-003, REQ-BCL-004).
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
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use RuntimeException;

/**
 * Suggests a usage's levels from GEMMA and counts how organisations classify an application.
 *
 * The suggestion reads the GEMMA scores (1 to 3) of the reference components
 * the usage names, or of its application when it names none, and takes the
 * highest per aspect as BBN1 to BBN3; it saves nothing. The summary reads the
 * classified usages of one application across organisations, counts distinct
 * consuming organisations, and per aspect and overall the organisations per
 * level, and withholds the levels below {@see self::MIN_ORGANISATIONS}. It
 * never returns an organisation name or id.
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
 */
class UsageClassificationService {

	/**
	 * Below this many classifying organisations the summary gives no levels.
	 *
	 * A constant on purpose (design, Risks): a setting could be lowered by accident.
	 *
	 * @var int
	 */
	public const MIN_ORGANISATIONS = 3;

	/**
	 * At most this many usages are read for one summary.
	 *
	 * @var int
	 */
	public const MAX_USAGES = 5000;

	/**
	 * The usage property per aspect, and the GEMMA element property it is suggested from.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const ASPECTS = [
		'availability'    => ['availabilityLevel', 'availability'],
		'integrity'       => ['integrityLevel', 'integrity'],
		'confidentiality' => ['confidentialityLevel', 'confidentiality'],
	];

	/**
	 * The levels, lowest first.
	 *
	 * @var list<string>
	 */
	public const LEVELS = ['BBN1', 'BBN2', 'BBN3'];

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $config The registers, schemas and OpenRegister's object service.
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-003-stackiq-shall-suggest-the-levels-from-gemmas-reference-components
	 */
	public function __construct(
		private readonly RegisterConfigReaderInterface $config,
	) {
	}//end __construct()

	/**
	 * The suggested level per aspect for a usage the caller may read.
	 *
	 * @param string $usageId The usage.
	 *
	 * @return array{levels: array<string, string|null>, scored: int, components: int}|null Null when the usage is not found.
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-003-stackiq-shall-suggest-the-levels-from-gemmas-reference-components
	 */
	public function suggestion(string $usageId): ?array {
		$usage = $this->find(type: 'usage', id: $usageId);
		if ($usage === null) {
			return null;
		}

		$components = self::ids(value: $usage['usedForReferenceComponents'] ?? []);
		if ($components === [] && is_string($usage['module'] ?? null) === true && $usage['module'] !== '') {
			$components = self::ids(value: ($this->find(type: 'module', id: $usage['module']) ?? [])['referenceComponents'] ?? []);
		}

		$highest = array_fill_keys(array_keys(self::ASPECTS), 0);
		$scored  = 0;
		foreach ($components as $componentId) {
			$scores = self::scoresOf(element: $this->find(type: 'element', id: $componentId) ?? []);
			if ($scores === []) {
				continue;
			}

			$scored++;
			foreach ($scores as $aspect => $score) {
				$highest[$aspect] = max($highest[$aspect], $score);
			}
		}

		return [
			'levels'     => array_map(static fn (int $score): ?string => self::LEVELS[$score - 1] ?? null, $highest),
			'scored'     => $scored,
			'components' => count($components),
		];
	}//end suggestion()

	/**
	 * How many organisations classified an application, and at three or more, per level.
	 *
	 * @param string $moduleId The application.
	 *
	 * @return array{organisations: int, levels: array<string, array<string, int>>|null}
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	public function summary(string $moduleId): array {
		$perOrganisation = [];
		foreach ($this->classifiedUsagesOf(moduleId: $moduleId) as $usage) {
			$organisation = (string) ($usage['consumer'] ?? ($usage['@self']['organisation'] ?? ''));
			if ($organisation === '') {
				continue;
			}

			// Several usages of one organisation count once, at their highest level per aspect.
			$current = ($perOrganisation[$organisation] ?? []);
			foreach (self::levelsOf(usage: $usage) as $aspect => $rank) {
				$current[$aspect] = max(($current[$aspect] ?? 0), $rank);
			}

			$perOrganisation[$organisation] = $current;
		}

		$count = count($perOrganisation);
		if ($count < self::MIN_ORGANISATIONS) {
			return ['organisations' => $count, 'levels' => null];
		}

		$levels = [];
		foreach ([...array_keys(self::ASPECTS), 'overall'] as $aspect) {
			$levels[$aspect] = array_fill_keys(self::LEVELS, 0);
		}

		foreach ($perOrganisation as $ranks) {
			foreach ($ranks as $aspect => $rank) {
				$levels[$aspect][self::LEVELS[$rank - 1]]++;
			}
		}

		return ['organisations' => $count, 'levels' => $levels];
	}//end summary()

	/**
	 * The type of an organisation (Municipality, Collaboration, Supplier, ...), or null.
	 *
	 * @param string $organisationId The organisation.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	public function organisationTypeOf(string $organisationId): ?string {
		$type = ($this->find(type: 'organization', id: $organisationId) ?? [])['type'] ?? null;
		if (is_string($type) === false || $type === '') {
			return null;
		}

		return $type;
	}//end organisationTypeOf()

	/**
	 * The classified usages of an application across organisations, read on the server's behalf.
	 *
	 * @param string $moduleId The application.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	private function classifiedUsagesOf(string $moduleId): array {
		$found = $this->objects()->searchObjects(
			query: [
				'@self'  => [
					'register' => $this->config->getRegisterIdForObjectType('usage'),
					'schema'   => $this->config->getSchemaIdForObjectType('usage'),
				],
				'module' => $moduleId,
				'_limit' => self::MAX_USAGES,
			],
			_rbac: false,
			_multitenancy: false
		);

		if (is_array($found) === false) {
			return [];
		}

		$usages = [];
		foreach ($found as $object) {
			$usage = self::asArray(object: $object);
			if (($usage['module'] ?? null) === $moduleId && self::levelsOf(usage: $usage) !== []) {
				$usages[] = $usage;
			}
		}

		return $usages;
	}//end classifiedUsagesOf()

	/**
	 * A usage's levels as ranks 1 to 3, per aspect and overall; only the levels it has.
	 *
	 * @param array<string, mixed> $usage The usage.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	private static function levelsOf(array $usage): array {
		$ranks = [];
		foreach (self::ASPECTS as $aspect => [$property]) {
			$rank = array_search($usage[$property] ?? null, self::LEVELS, true);
			if ($rank !== false) {
				$ranks[$aspect] = ($rank + 1);
			}
		}

		if ($ranks === []) {
			return [];
		}

		$ranks['overall'] = max($ranks);
		$overall = array_search($usage['bbnLevel'] ?? null, self::LEVELS, true);
		if ($overall !== false) {
			$ranks['overall'] = ($overall + 1);
		}

		return $ranks;
	}//end levelsOf()

	/**
	 * The GEMMA scores 1 to 3 of a reference component per aspect; the aspects it has a score for.
	 *
	 * @param array<string, mixed> $element The reference component.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-003-stackiq-shall-suggest-the-levels-from-gemmas-reference-components
	 */
	private static function scoresOf(array $element): array {
		$scores = [];
		foreach (self::ASPECTS as $aspect => [, $gemmaProperty]) {
			$score = filter_var($element[$gemmaProperty] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
			if ($score !== false) {
				$scores[$aspect] = $score;
			}
		}

		return $scores;
	}//end scoresOf()

	/**
	 * One object of a type the caller may read, as an array, or null.
	 *
	 * @param string $type The object type.
	 * @param string $id   The object id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-003-stackiq-shall-suggest-the-levels-from-gemmas-reference-components
	 */
	private function find(string $type, string $id): ?array {
		$objects = $this->objects();
		try {
			$object = $objects->find(
				id: $id,
				register: $this->config->getRegisterIdForObjectType($type),
				schema: $this->config->getSchemaIdForObjectType($type)
			);
		} catch (\Throwable) {
			// Not found, or not readable by the caller: the same answer.
			return null;
		}

		if ($object === null) {
			return null;
		}

		return self::asArray(object: $object);
	}//end find()

	/**
	 * The ids in a list of references: strings, or objects with an id or uuid.
	 *
	 * @param mixed $value The references.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-003-stackiq-shall-suggest-the-levels-from-gemmas-reference-components
	 */
	private static function ids(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$ids = [];
		foreach ($value as $reference) {
			if (is_array($reference) === true) {
				$reference = ($reference['id'] ?? ($reference['uuid'] ?? null));
			}

			if (is_string($reference) === true && $reference !== '') {
				$ids[$reference] = true;
			}
		}

		return array_keys($ids);
	}//end ids()

	/**
	 * An object entity or array as an array.
	 *
	 * @param mixed $object The object.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	private static function asArray(mixed $object): array {
		if ($object instanceof ObjectEntityInterface) {
			$data = $object->getObject();
			$data['@self']['organisation'] = ($data['@self']['organisation'] ?? $object->getOrganisation());
			return $data;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		return $object;
	}//end asArray()

	/**
	 * OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->config->getObjectService();
		if ($objects === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objects;
	}//end objects()
}//end class
