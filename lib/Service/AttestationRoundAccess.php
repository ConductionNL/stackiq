<?php

/**
 * Who may start a confirmation round for an organisation.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 * @spec      openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\IGroupManager;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A Nextcloud admin, or a maintainer who is a member of the organisation.
 *
 * The same rule as granting organisation access: the `maintainer` group and
 * the caller's own memberships, read from OpenRegister, which must include
 * the organisation. Anything that cannot be checked refuses.
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 */
class AttestationRoundAccess {

	/**
	 * The group of an organisation's maintainers.
	 */
	public const MAINTAINER_GROUP = 'maintainer';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager      $groupManager The group lookups.
	 * @param ContainerInterface $container    The DI container, for OpenRegister's OrganisationService.
	 * @param LoggerInterface    $logger       The logger.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the user may start a round for the organisation.
	 *
	 * @param IUser  $user             The caller.
	 * @param string $organisationUuid The organisation the round covers.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
	 */
	public function mayStart(IUser $user, string $organisationUuid): bool {
		if ($organisationUuid === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		if ($this->groupManager->isInGroup($user->getUID(), self::MAINTAINER_GROUP) === false) {
			return false;
		}

		return $this->isMember(user: $user, organisationUuid: $organisationUuid);
	}//end mayStart()

	/**
	 * Whether the caller's own organisations, from OpenRegister, include the organisation.
	 *
	 * @param IUser  $user             The caller.
	 * @param string $organisationUuid The organisation.
	 *
	 * @return bool False as well when the memberships cannot be read.
	 */
	private function isMember(IUser $user, string $organisationUuid): bool {
		try {
			$organisations = $this->container->get('OCA\OpenRegister\Service\OrganisationService')->getUserOrganisations();
		} catch (Throwable $e) {
			$this->logger->warning('[AttestationRoundAccess] memberships unreadable for ' . $user->getUID() . ': ' . get_class($e));
			return false;
		}

		if (is_iterable($organisations) === false) {
			return false;
		}

		foreach ($organisations as $organisation) {
			if (is_object($organisation) === true && method_exists($organisation, 'getUuid') === true
				&& (string) $organisation->getUuid() === $organisationUuid
			) {
				return true;
			}
		}

		return false;
	}//end isMember()
}//end class
