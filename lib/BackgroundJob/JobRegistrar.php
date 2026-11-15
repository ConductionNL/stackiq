<?php

/**
 * Factories for the background jobs
 *
 * @category  BackgroundJob
 * @package   OCA\Stackiq\BackgroundJob
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

namespace OCA\Stackiq\BackgroundJob;

use OCA\Stackiq\Kernel\Contracts\ContractStatusServiceInterface;
use OCA\Stackiq\Kernel\Contracts\CronjobSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\EolSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\FederationConfigInterface;
use OCA\Stackiq\Kernel\Contracts\FederationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\OrganizationSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Stackiq\Kernel\Contracts\StackiqContactSyncServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the background jobs whose constructor arguments are wired by hand.
 *
 * @category BackgroundJob
 * @package  OCA\Stackiq\BackgroundJob
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class JobRegistrar {

	/**
	 * Register the background jobs that need a hand-built factory
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Register background job for organization contact synchronization.
		$context->registerService(
			OrganizationContactSyncJob::class,
			function ($container) {
				return new OrganizationContactSyncJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					orgSyncService: $container->get(OrganizationSyncServiceInterface::class),
					contactSync: $container->get(StackiqContactSyncServiceInterface::class),
					settingsService: $container->get(SettingsServiceInterface::class),
					cronjobSettings: $container->get(CronjobSettingsInterface::class),
					appManager: $container->get(IAppManager::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);

		$context->registerService(
			ContractStatusJob::class,
			function ($container) {
				return new ContractStatusJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					statusService: $container->get(ContractStatusServiceInterface::class),
					appManager: $container->get(IAppManager::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);

		$context->registerService(
			FederationSyncJob::class,
			function ($container) {
				return new FederationSyncJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					federation: $container->get(FederationServiceInterface::class),
					config: $container->get(FederationConfigInterface::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);

		$context->registerService(
			EolSyncJob::class,
			function ($container) {
				return new EolSyncJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					eolSyncService: $container->get(EolSyncServiceInterface::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);
	}//end register()
}//end class
