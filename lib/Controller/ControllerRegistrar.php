<?php

/**
 * Factories for the controllers
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
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

namespace OCA\Stackiq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\OrganisationService as OpenRegisterOrganisationService;
use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Kernel\Contracts\ContactPersonHandlerInterface;
use OCA\Stackiq\Kernel\Contracts\ContactPersonOrganisationGuardInterface;
use OCA\Stackiq\Kernel\Contracts\ContactpersoonServiceInterface;
use OCA\Stackiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the controllers whose constructor arguments are wired by hand.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class ControllerRegistrar {

	/**
	 * Register the controllers that need a hand-built factory
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Register ContactpersonenController with explicit dependencies for /me endpoint.
		$context->registerService(
			ContactpersonenController::class,
			function ($container) {
				return new ContactpersonenController(
					appName: AppIdentity::APP_ID,
					request: $container->get('OCP\IRequest'),
					settingsService: $container->get(SettingsServiceInterface::class),
					contactPersonHandler: $container->get(ContactPersonHandlerInterface::class),
					contactSvc: $container->get(ContactpersoonServiceInterface::class),
					userManager: $container->get('OCP\IUserManager'),
					groupManager: $container->get('OCP\IGroupManager'),
					userSession: $container->get('OCP\IUserSession'),
					secureRandom: $container->get('OCP\Security\ISecureRandom'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					// ADR-084 added these two to the constructor; this hand-written
					// factory bypasses Nextcloud's autowiring, so it does not gain
					// them automatically. Omitting them is an ArgumentCountError at
					// request time and nothing catches it before then — see
					// tests/Unit/AppInfo/CompositionRootArgumentsTest.php.
					objectService: $container->get(ObjectServiceInterface::class),
					organisationService: $container->get(OpenRegisterOrganisationService::class),
					organisationGuard: $container->get(ContactPersonOrganisationGuardInterface::class)
				);
			}
		);
	}//end register()
}//end class
