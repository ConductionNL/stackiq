<?php

/**
 * The registeredBy value of an object, from its organisation's type.
 *
 * Shared by the offer and offered-usage services, which both stamp
 * registeredBy on an object when an organisation accepts it.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Support
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Support;

use Exception;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets registeredBy on an object from the type of the organisation that accepts it.
 *
 * @internal
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class RegisteredByFromOrganisation {

	/**
	 * Mapping from organisatie.type to registeredBy value.
	 */
	private const TYPE_MAP = [
		'Municipality' => 'Municipality',
		'Supplier' => 'Supplier',
		'Collaboration' => 'Collaboration',
		'Community' => 'Community',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $settingsService The register and schema configuration.
	 * @param LoggerInterface               $logger          Logs the update and a failed lookup.
	 */
	public function __construct(
		private readonly RegisterConfigReaderInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Update registeredBy on object data based on the organisation's type.
	 *
	 * Looks up the organisation object by UUID, reads its type, and maps it
	 * to the appropriate registeredBy value using TYPE_MAP.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param array $objectData The object data to update
	 * @param string $organisationUuid The UUID of the organisation to look up
	 *
	 * @return array The updated object data
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function apply(
		ObjectServiceInterface $objectService,
		array $objectData,
		string $organisationUuid,
	): array {
		try {
			$organisationSchemaId = $this->settingsService->getSchemaIdForObjectType('organization');
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$registerId = $voorzieningenConfig['register'] ?? null;

			if ($organisationSchemaId === null || $registerId === null) {
				return $objectData;
			}

			$organisationObject = $objectService->find(
				id: $organisationUuid,
				options: new ObjectFindOptions(register: (int)$registerId, schema: (int)$organisationSchemaId),
				_rbac: false,
				_multitenancy: false
			);

			if ($organisationObject === null) {
				return $objectData;
			}

			$organisationData = $organisationObject->getObject();
			$orgType = $organisationData['type'] ?? null;

			if ($orgType !== null && isset(self::TYPE_MAP[$orgType]) === true) {
				$objectData['registeredBy'] = self::TYPE_MAP[$orgType];

				$this->logger->info(
					'Updated registeredBy during transfer',
					[
						'organisationUuid' => $organisationUuid,
						'orgType' => $orgType,
						'registeredBy' => self::TYPE_MAP[$orgType],
					]
				);
			}
		} catch (Exception $e) {
			$this->logger->warning(
				'Failed to update registeredBy during transfer',
				[
					'organisationUuid' => $organisationUuid,
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return $objectData;
	}//end apply()
}//end class
