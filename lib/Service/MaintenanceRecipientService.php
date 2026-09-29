<?php

/**
 * Maintenance Recipient Service.
 *
 * Resolves who hears about planned maintenance on a product: the business
 * owner and the technical owner of every usage of that product, as Nextcloud
 * users. The service only computes who; OpenRegister's notification rules on
 * the maintenanceWindow schema deliver the messages (ADR-031).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the owners of every usage of a product to Nextcloud user ids and
 * records them on a maintenance window.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */
class MaintenanceRecipientService {

	/**
	 * The usage fields that name an owner.
	 *
	 * @var array<int, string>
	 */
	public const OWNER_FIELDS = ['businessOwner', 'technicalOwner'];

	/**
	 * Upper bound on the usages read for one product.
	 */
	private const USAGE_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService The register and schema lookups.
	 * @param StackiqContactSyncService $contacts        The Nextcloud Contacts lookups.
	 * @param IUserManager              $userManager     The Nextcloud user manager.
	 * @param ContainerInterface        $container       The DI container, for OpenRegister's ObjectService.
	 * @param LoggerInterface           $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StackiqContactSyncService $contacts,
		private readonly IUserManager $userManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an object belongs to the maintenanceWindow schema.
	 *
	 * @param ObjectEntityInterface $object The object from the event.
	 *
	 * @return boolean True for a maintenance window.
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public function isMaintenanceWindow(ObjectEntityInterface $object): bool {
		$schemaId = $this->settingsService->getSchemaIdForObjectType('maintenanceWindow');
		return $schemaId !== null && (string) $schemaId === (string) $object->getSchema();
	}//end isMaintenanceWindow()

	/**
	 * Load a maintenance window and record the owners to notify on it.
	 *
	 * @param string          $uuid     The window's id.
	 * @param string|int|null $register The register it lives in.
	 * @param string|int|null $schema   Its schema.
	 *
	 * @return array<int, string>|null The user ids written, or null when nothing was written.
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public function recordRecipientsFor(string $uuid, string|int|null $register, string|int|null $schema): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$window = $objectService->find(id: $uuid, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MaintenanceRecipientService: could not read the maintenance window',
				['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($window === null) {
			return null;
		}

		return $this->recordRecipients(window: $window);
	}//end recordRecipientsFor()

	/**
	 * Record the owners to notify on a newly announced maintenance window.
	 *
	 * Writes `notifyUserIds` and `recipientsResolvedAt`; the rule
	 * `maintenance-announced` fires on the change of the latter. A window that
	 * already carries a resolved time is left alone, so the write this method
	 * makes cannot start it again.
	 *
	 * @param ObjectEntityInterface  $window The maintenance window.
	 * @param DateTimeImmutable|null $now    The moment of resolution (defaults to now).
	 *
	 * @return array<int, string>|null The user ids written, or null when nothing was written.
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public function recordRecipients(ObjectEntityInterface $window, ?DateTimeImmutable $now=null): ?array {
		$data = $window->getObject();
		if (empty($data['recipientsResolvedAt']) === false) {
			return null;
		}

		$moduleId = self::referenceId(value: ($data['module'] ?? null));
		$objectService = $this->getObjectService();
		if ($moduleId === null || $objectService === null) {
			return null;
		}

		$userIds = $this->ownerUserIds(objectService: $objectService, moduleId: $moduleId);

		$data['notifyUserIds']        = $userIds;
		$data['recipientsResolvedAt'] = ($now ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		try {
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $window->getRegister(),
				schema: $window->getSchema(),
				uuid: $window->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MaintenanceRecipientService: could not record the owners to notify',
				['uuid' => $window->getUuid(), 'error' => $e->getMessage()]
			);
			return null;
		}

		return $userIds;
	}//end recordRecipients()

	/**
	 * The Nextcloud user ids of the owners of every usage of a product.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $moduleId      The product's id.
	 *
	 * @return array<int, string> Unique user ids, in the order found.
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public function ownerUserIds(ObjectServiceInterface $objectService, string $moduleId): array {
		$contactIds = $this->ownerContactIds(objectService: $objectService, moduleId: $moduleId);
		if ($contactIds === []) {
			return [];
		}

		$register = $this->settingsService->getRegisterIdForObjectType('contactPerson');
		$schema   = $this->settingsService->getSchemaIdForObjectType('contactPerson');
		if ($register === null || $schema === null) {
			return [];
		}

		try {
			$people = $objectService->searchObjects(
				query: ['register' => $register, 'schema' => $schema, '_limit' => count($contactIds)],
				_rbac: false,
				_multitenancy: false,
				ids: $contactIds
			);
		} catch (\Throwable $e) {
			$this->logger->error('MaintenanceRecipientService: could not read the owners', ['error' => $e->getMessage()]);
			return [];
		}

		$userIds = [];
		foreach ((array) $people as $person) {
			$uid = $this->userIdForContactPerson(person: $person->getObject());
			if ($uid !== null && in_array($uid, $userIds, true) === false) {
				$userIds[] = $uid;
			}
		}

		return $userIds;
	}//end ownerUserIds()

	/**
	 * The contact person ids named as owner on the usages of a product.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $moduleId      The product's id.
	 *
	 * @return array<int, string> Unique contact person ids.
	 */
	private function ownerContactIds(ObjectServiceInterface $objectService, string $moduleId): array {
		$register = $this->settingsService->getRegisterIdForObjectType('usage');
		$schema   = $this->settingsService->getSchemaIdForObjectType('usage');
		if ($register === null || $schema === null) {
			return [];
		}

		try {
			$usages = $objectService->searchObjects(
				query: ['register' => $register, 'schema' => $schema, 'module' => $moduleId, '_limit' => self::USAGE_LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('MaintenanceRecipientService: could not read the usages', ['error' => $e->getMessage()]);
			return [];
		}

		$ids = [];
		foreach ((array) $usages as $usage) {
			$data = $usage->getObject();
			foreach (self::OWNER_FIELDS as $field) {
				$id = self::referenceId(value: ($data[$field] ?? null));
				if ($id !== null && in_array($id, $ids, true) === false) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}//end ownerContactIds()

	/**
	 * The Nextcloud user a contact person stands for.
	 *
	 * A contact in the system address book carries the user id as its UID;
	 * any other contact is matched to a user by e-mail address.
	 *
	 * @param array<string, mixed> $person The contact person object.
	 *
	 * @return string|null The user id, or null when no user matches.
	 */
	private function userIdForContactPerson(array $person): ?string {
		$contact = $this->contacts->findContactByUid((string) ($person['contactsUid'] ?? ''));
		if ($contact === null) {
			return null;
		}

		if (($contact['isLocalSystemBook'] ?? false) === true && $this->userManager->userExists((string) ($contact['UID'] ?? '')) === true) {
			return (string) $contact['UID'];
		}

		foreach ((array) ($contact['EMAIL'] ?? []) as $email) {
			if (is_array($email) === true) {
				$email = ($email['value'] ?? '');
			}

			if (is_string($email) === false || $email === '') {
				continue;
			}

			$users = $this->userManager->getByEmail($email);
			if (count($users) === 1) {
				return $users[0]->getUID();
			}
		}

		return null;
	}//end userIdForContactPerson()

	/**
	 * The id a relation value points at: a plain id, or an object carrying one.
	 *
	 * @param mixed $value The stored relation value.
	 *
	 * @return string|null The id, or null when the value names none.
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public static function referenceId(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		if (is_array($value) === true) {
			foreach (['id', 'uuid'] as $key) {
				if (is_string($value[$key] ?? null) === true && $value[$key] !== '') {
					return $value[$key];
				}
			}
		}

		return null;
	}//end referenceId()

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
			$this->logger->debug('MaintenanceRecipientService: ObjectService not resolvable', ['error' => $e->getMessage()]);
		}

		return null;
	}//end getObjectService()
}//end class
