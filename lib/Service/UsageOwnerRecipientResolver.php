<?php

/**
 * Usage Owner Recipient Resolver.
 *
 * Resolves the recipients of a notification about an application to the
 * owners of every usage of it: the business owner and the technical owner of
 * each organisation's usage, as Nextcloud users. A vulnerability names the
 * applications it affects in `modules`; a module version names its
 * application in `module`. OpenRegister's notification engine calls this for
 * a recipients entry `{"kind": "expression", "resolver": <this class>}`.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The owners of the usages of the application(s) a notification is about.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
 * @SuppressWarnings(PHPMD.StaticAccess) MaintenanceRecipientService::referenceId() is a pure reader of a reference value.
*/
class UsageOwnerRecipientResolver implements RecipientResolverInterface {

	/**
	 * The fields that name the application(s) an object is about, in the
	 * order they are read: a vulnerability's affected applications, a
	 * version's application.
	 */
	public const APPLICATION_FIELDS = ['modules', 'module'];

	/**
	 * Constructor.
	 *
	 * @param MaintenanceRecipientService $owners    Resolves the owners of a product's usages to users.
	 * @param ContainerInterface          $container The DI container, for OpenRegister's ObjectService.
	 * @param LoggerInterface             $logger    The logger.
	 */
	public function __construct(
		private readonly MaintenanceRecipientService $owners,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The Nextcloud users who own a usage of the application(s) the object is about.
	 *
	 * @param ObjectEntity         $object  The vulnerability or module version.
	 * @param array<string, mixed> $context Trigger-specific extras (unused).
	 *
	 * @return array<int, string> Unique user ids, in the order found.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $context is part of the resolver contract.
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		$moduleIds = self::applicationIds(data: (array) ($object->getObject() ?? []));
		if ($moduleIds === []) {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$userIds = [];
		foreach ($moduleIds as $moduleId) {
			foreach ($this->owners->ownerUserIds(objectService: $objectService, moduleId: $moduleId) as $uid) {
				if (in_array($uid, $userIds, true) === false) {
					$userIds[] = $uid;
				}
			}
		}

		return $userIds;
	}//end resolve()

	/**
	 * The ids of the applications an object's data names.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return array<int, string> Unique application ids, in the order found.
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
	 */
	public static function applicationIds(array $data): array {
		$ids = [];
		foreach (self::APPLICATION_FIELDS as $field) {
			$values = [($data[$field] ?? null)];
			if (is_array($values[0]) === true && array_is_list($values[0]) === true) {
				$values = $values[0];
			}

			foreach ($values as $entry) {
				$id = MaintenanceRecipientService::referenceId(value: $entry);
				if ($id !== null && in_array($id, $ids, true) === false) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}//end applicationIds()

	/**
	 * Lazily resolve OpenRegister's ObjectService.
	 *
	 * @return ObjectServiceInterface|null The service, or null when OpenRegister is absent.
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			$service = $this->container->get(ObjectServiceInterface::class);
			if ($service instanceof ObjectServiceInterface) {
				return $service;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('UsageOwnerRecipientResolver: ObjectService not resolvable', ['error' => $e->getMessage()]);
		}

		return null;
	}//end getObjectService()
}//end class
