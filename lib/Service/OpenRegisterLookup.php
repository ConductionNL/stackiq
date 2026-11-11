<?php

/**
 * OpenRegister lookups for Stackiq.
 *
 * Resolves OpenRegister's object service and the caller's active
 * organisation from the container, for the services that list and act on
 * offered objects.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use Exception;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves OpenRegister's object service and the active organisation.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class OpenRegisterLookup {
	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager  Tells whether OpenRegister is installed.
	 * @param ContainerInterface $container   Holds the OpenRegister services.
	 * @param IUserSession       $userSession The caller's session.
	 * @param LoggerInterface    $logger      Logs a failed organisation lookup.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uuid of the caller's active organisation.
	 *
	 * @return string|null The uuid, or null without a session, without an active organisation or when the lookup fails.
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md#requirement-the-system-shall-list-offered-usage-records-scoped-to-the-callers-role-req-001
	 */
	public function activeOrganisationUuid(): ?string {
		if ($this->userSession->getUser() === null) {
			return null;
		}

		try {
			// Get the OpenRegister OrganisationService to get the active organisation.
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			return $organisationService->getActiveOrganisation()?->getUuid();
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to get current organisation from OpenRegister',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end activeOrganisationUuid()

	/**
	 * OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface The OpenRegister object service
	 *
	 * @throws Exception When OpenRegister is not installed or its service is not available
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md#requirement-the-system-shall-list-offered-usage-records-scoped-to-the-callers-role-req-001
	 */
	public function objectService(): ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
			throw new Exception('OpenRegister app is not installed');
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Exception $e) {
			throw new Exception('Failed to get OpenRegister service: ' . $e->getMessage());
		}
	}//end objectService()
}//end class
