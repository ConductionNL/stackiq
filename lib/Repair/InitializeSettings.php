<?php

/**
 * Repair step that initializes SoftwareCatalog settings on install/upgrade.
 *
 * @category  Repair
 * @package   OCA\SoftwareCatalog\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Repair;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes SoftwareCatalog settings on install/upgrade.
 *
 * This runs only during app install or upgrade, not on every request.
 *
 * @category Repair
 * @package  OCA\SoftwareCatalog\Repair
 *
 * @spec openspec/specs/repair-init/spec.md
 */
class InitializeSettings implements IRepairStep {
	/**
	 * Constructor for InitializeSettings.
	 *
	 * @param IAppConfig $config The application configuration
	 * @param IAppManager $appManager The application manager
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger instance
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns the name of this repair step.
	 *
	 * @return string The repair step name
	 *
	 * @spec openspec/specs/repair-init/spec.md
	 */
	public function getName(): string {
		return 'Initialize SoftwareCatalog settings';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 * @spec   openspec/specs/repair-init/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complexity 11 (threshold 10). A repair step
	 * runs at install/upgrade time with no user session and must never abort the upgrade, so the
	 * body is a sequence of independent "is this piece already present / still resolvable?"
	 * checks, each with its own tolerated-failure branch that reports to `IOutput` and continues.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) NPath 385 (threshold 200) — the product of those
	 * independent tolerated-failure checks, not nested logic.
	 */
	public function run(IOutput $output): void {
		$output->startProgress(1);

		try {
			$currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);
			$lastInitVersion = $this->config->getValueString(Application::APP_ID, 'last_initialized_version', '');

			// Only initialize if version changed or never initialized.
			if ($lastInitVersion === $currentAppVersion) {
				$output->info('Settings already initialized for version ' . $currentAppVersion);
				$output->advance(1);
				$output->finishProgress();
				return;
			}

			$output->info('Initializing settings for version ' . $currentAppVersion);
			$this->logger->info('SoftwareCatalog repair: Starting initialization for version ' . $currentAppVersion);

			// @spec openspec/specs/contract-administration/spec.md
			// Seed window defaults only when the admin has not set them, so
			// upgrades never clobber an operator's chosen window.
			if ($this->config->hasKey(Application::APP_ID, 'contract_expiry_window_days') === false) {
				$this->config->setValueInt(Application::APP_ID, 'contract_expiry_window_days', 90);
			}

			// @spec openspec/specs/application-lifecycle-tracking/spec.md
			// Seed the EOL warning window default only when unset, so an
			// operator's chosen window survives upgrades.
			if ($this->config->hasKey(Application::APP_ID, 'eol_warning_window_days') === false) {
				$this->config->setValueInt(Application::APP_ID, 'eol_warning_window_days', 180);
			}

			// @spec openspec/changes/portfolio-rationalization-time/specs
			//       /portfolio-rationalization-time/spec.md
			//       #requirement-report-aggregation-queries-are-bounded
			// Seed the portfolio-report page-size ceiling default only when
			// unset, so an operator's chosen bound survives upgrades.
			if ($this->config->hasKey(Application::APP_ID, 'portfolio_report_page_size_ceiling') === false) {
				$this->config->setValueInt(Application::APP_ID, 'portfolio_report_page_size_ceiling', 500);
			}

			// @spec openspec/specs/federated-catalog-sync/spec.md
			// Seed federation defaults only when unset (admin overrides survive).
			if ($this->config->hasKey(Application::APP_ID, 'federation_enabled') === false) {
				$this->config->setValueBool(Application::APP_ID, 'federation_enabled', false);
			}

			if ($this->config->hasKey(Application::APP_ID, 'federation_directory_url') === false) {
				$this->config->setValueString(Application::APP_ID, 'federation_directory_url', 'https://directory.opencatalogi.nl');
			}

			if ($this->config->hasKey(Application::APP_ID, 'federation_sync_interval') === false) {
				$this->config->setValueInt(Application::APP_ID, 'federation_sync_interval', 3600);
			}

			// Get the settings service and initialize.
			$settingsService = $this->container->get(SettingsService::class);
			$result = $settingsService->initialize();

			// Mark this version as initialized regardless of partial failures.
			// This prevents repeated attempts on every request.
			$this->config->setValueString(Application::APP_ID, 'last_initialized_version', $currentAppVersion);

			if (empty($result['errors']) === false) {
				foreach ($result['errors'] as $error) {
					$output->warning('Initialization warning: ' . $error);
					$this->logger->warning('SoftwareCatalog repair: ' . $error);
				}
			}

			$output->info('Settings initialization completed');
			$this->logger->info('SoftwareCatalog repair: Initialization completed', ['result' => $result]);
		} catch (\Exception $e) {
			// Still mark as initialized to prevent repeated failures.
			$currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);
			$this->config->setValueString(Application::APP_ID, 'last_initialized_version', $currentAppVersion);
			$output->warning('Settings initialization failed: ' . $e->getMessage());
			$this->logger->error('SoftwareCatalog repair: Initialization failed', ['exception' => $e->getMessage()]);
		}//end try

		$output->advance(1);
		$output->finishProgress();
	}//end run()
}//end class
