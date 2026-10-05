<?php

/**
 * Maintenance Announcer Check.
 *
 * Decides whether a maintenance window may have the owners of its product
 * notified: only the product's own supplier, or a catalogue administrator,
 * reaches the municipalities that use it.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Whether a maintenance window comes from someone allowed to reach the product's owners.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */
class MaintenanceAnnouncerCheck {

	/**
	 * The groups whose members may announce maintenance on any product.
	 *
	 * @var array<int, string>
	 */
	private const CATALOG_ADMIN_GROUPS = ['admin', 'software-catalog-admins'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The module register and schema lookups.
	 * @param IGroupManager   $groupManager    The group manager, for the catalogue's administrators.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether a window may notify the owners of its product.
	 *
	 * Yes when a catalogue administrator created it, or when the organisation
	 * that owns the window is the product's supplier (`provider`). The
	 * organisation that merely owns the product record does not count: a
	 * product entered by an administrator or an import carries the importer's
	 * organisation, often the default one, which says nothing about who supplies
	 * it. A product that no longer exists refuses; a read that fails for any
	 * other reason is thrown, so the caller writes nothing rather than treating
	 * a real supplier's window as refused.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ObjectEntityInterface  $window        The maintenance window.
	 * @param string                 $moduleId      The product's id.
	 *
	 * @return boolean True when the owners may be notified.
	 *
	 * @throws \Throwable When the product cannot be read for a reason other than that it does not exist.
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public function mayAnnounce(ObjectServiceInterface $objectService, ObjectEntityInterface $window, string $moduleId): bool {
		if ($this->isCatalogAdmin(uid: (string) $window->getOwner()) === true) {
			return true;
		}

		$organisation = (string) $window->getOrganisation();
		if ($organisation === '') {
			return false;
		}

		try {
			$module = $objectService->find(
				id: $moduleId,
				register: $this->settingsService->getRegisterIdForObjectType('module'),
				schema: $this->settingsService->getSchemaIdForObjectType('module'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return false;
		}

		if ($module === null) {
			return false;
		}

		$provider = ($module->getObject()['provider'] ?? null);
		if (is_array($provider) === true) {
			$provider = ($provider['id'] ?? ($provider['uuid'] ?? null));
		}

		return $organisation === $provider;
	}//end mayAnnounce()

	/**
	 * Whether a user is a Nextcloud or catalogue administrator.
	 *
	 * @param string $uid The user id, or an empty string for none.
	 *
	 * @return boolean True for a member of an administrator group.
	 */
	private function isCatalogAdmin(string $uid): bool {
		if ($uid === '') {
			return false;
		}

		foreach (self::CATALOG_ADMIN_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isCatalogAdmin()
}//end class
