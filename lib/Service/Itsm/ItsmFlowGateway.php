<?php

/**
 * The service desk exchange's door into OpenRegister's flow store.
 *
 * Stackiq does not depend on OpenRegister at install time, so its flow
 * classes are resolved from the container when they are needed. This class
 * is the one place that does it: validate a flow, save it, publish and
 * enable it, start a run, and read one object of another app's register.
 * The exchange service is tested against this seam.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Itsm
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Itsm;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Validates, saves, publishes and runs flows through OpenRegister.
 */
class ItsmFlowGateway {

	/**
	 * OpenRegister's preflight, which checks a flow against the live node registry.
	 *
	 * @var string
	 */
	private const PREFLIGHT = 'OCA\OpenRegister\Service\Flow\FlowNodePreflight';

	/**
	 * OpenRegister's flow store.
	 *
	 * @var string
	 */
	private const FLOWS = 'OCA\OpenRegister\Service\Flow\FlowService';

	/**
	 * OpenRegister's flow versions, which publish a draft.
	 *
	 * @var string
	 */
	private const VERSIONS = 'OCA\OpenRegister\Service\Flow\FlowVersionService';

	/**
	 * OpenRegister's object service.
	 *
	 * @var string
	 */
	private const OBJECTS = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The server container.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister's flow engine is there.
	 *
	 * @return bool True when the flow classes resolve.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function available(): bool {
		return class_exists('\\' . self::FLOWS) === true && class_exists('\\' . self::PREFLIGHT) === true;
	}//end available()

	/**
	 * Check a flow document against the live node registry.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 *
	 * @return array{blocking: list<array<string, mixed>>, warnings: list<array<string, mixed>>} The findings.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function inspect(array $flow): array {
		$result = $this->service(class: self::PREFLIGHT)->inspect($flow);

		return [
			'blocking' => array_values((array) ($result['blocking'] ?? [])),
			'warnings' => array_values((array) ($result['warnings'] ?? [])),
		];
	}//end inspect()

	/**
	 * Save a flow, publish it and switch it on.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 * @param string|null          $uuid The flow to update, or null to create one.
	 *
	 * @return string The flow's uuid.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function saveAndPublish(array $flow, ?string $uuid): string {
		$flows = $this->service(class: self::FLOWS);
		if ($uuid !== null && $this->exists(uuid: $uuid) === false) {
			$uuid = null;
		}

		$saved = $flows->save($flow, $uuid);
		$this->service(class: self::VERSIONS)->publish($saved);

		$enabled = $flows->save(['enabled' => true], (string) $saved->getUuid());

		return (string) $enabled->getUuid();
	}//end saveAndPublish()

	/**
	 * Start a run of a flow with a payload, which seeds the run's first item.
	 *
	 * @param string               $uuid    The flow.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return string The run's uuid.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
	 */
	public function run(string $uuid, array $payload): string {
		$run = $this->service(class: self::FLOWS)->run($uuid, [], ['payload' => $payload], false);

		return (string) $run->getUuid();
	}//end run()

	/**
	 * Read one object of a register and schema by id, uuid or slug.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The id, uuid or slug.
	 *
	 * @return array<string, mixed>|null The object, or null when there is none.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function findObject(string $register, string $schema, string $id): ?array {
		try {
			$found = $this->service(class: self::OBJECTS)->find($id, [], false, $register, $schema, false, false);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($found) === false) {
			return null;
		}

		$object = (array) $found->jsonSerialize();
		$object['uuid'] = (string) $found->getUuid();

		return $object;
	}//end findObject()

	/**
	 * Whether a flow still exists.
	 *
	 * @param string $uuid The flow.
	 *
	 * @return bool True when it does.
	 */
	private function exists(string $uuid): bool {
		try {
			$this->service(class: self::FLOWS)->find($uuid);
		} catch (Throwable $e) {
			return false;
		}

		return true;
	}//end exists()

	/**
	 * Resolve one OpenRegister service.
	 *
	 * @param string $class The class name.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister does not provide it.
	 */
	private function service(string $class): object {
		try {
			$service = $this->container->get($class);
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister does not provide ' . $class . ': ' . $e->getMessage(), 0, $e);
		}

		if (is_object($service) === false) {
			throw new RuntimeException('OpenRegister does not provide ' . $class . '.');
		}

		return $service;
	}//end service()
}//end class
