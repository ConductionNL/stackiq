<?php

/**
 * Stackiq catalogue export gate
 *
 * Whether the caller may export a catalogue list: a schema's `export` grant,
 * else its `read` grant.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Export;

use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether the caller may export a catalogue list.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */
class CatalogExportGate {

	/**
	 * Constructor.
	 *
	 * @param CatalogueLocator   $catalogue    The catalogue schema ids.
	 * @param ContainerInterface $container    Reaches OpenRegister's SchemaMapper.
	 * @param IUserSession       $userSession  The caller.
	 * @param IGroupManager      $groupManager The caller's groups, for a schema's `export` grant.
	 * @param LoggerInterface    $logger       Logs a schema it could not read.
	 */
	public function __construct(
		private readonly CatalogueLocator $catalogue,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the caller may export the schema: when its `authorization` names
	 * `export`, the caller must be in one of those groups (an administrator
	 * always is); otherwise the `read` grant applies, which the RBAC read
	 * enforces row by row. A schema that cannot be read refuses.
	 *
	 * @param string $schema The schema slug, already checked.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function mayExport(string $schema): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$authorization = $this->schemaAuthorization(schema: $schema);
		if ($authorization === null) {
			return false;
		}

		if (array_key_exists('export', $authorization) === false) {
			return true;
		}

		return $this->inExportGroups(uid: $user->getUID(), groups: (array)$authorization['export']);
	}//end mayExport()

	/**
	 * The schema's `authorization` block, or null when the schema cannot be read.
	 *
	 * @param string $schema The schema slug, already checked.
	 *
	 * @return array<string, mixed>|null
	 */
	private function schemaAuthorization(string $schema): ?array {
		try {
			$schemaId = $this->catalogue->getSchemaIdForObjectType(objectType: $schema);
			if ($schemaId === null) {
				return null;
			}

			$entity = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->find($schemaId, _rbac: false, _multitenancy: false);
			return (array)($entity->getAuthorization() ?? []);
		} catch (Throwable $exception) {
			$this->logger->warning('[CatalogExportService] The schema could not be read: {msg}', ['msg' => $exception->getMessage(), 'schema' => $schema]);
			return null;
		}
	}//end schemaAuthorization()

	/**
	 * Whether the user is an administrator or in one of the export groups.
	 *
	 * @param string             $uid    The user id.
	 * @param array<int, mixed>  $groups The groups the `export` grant names.
	 *
	 * @return bool
	 */
	private function inExportGroups(string $uid, array $groups): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach ($groups as $group) {
			if (is_string($group) === true && $this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end inExportGroups()
}//end class
