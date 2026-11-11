<?php

/**
 * Repair step that initializes Stackiq settings on install/upgrade.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Stackiq settings on install/upgrade.
 *
 * This runs only during app install or upgrade, not on every request.
 *
 * @category Repair
 * @package  OCA\Stackiq\Repair
 *
 * @spec openspec/specs/repair-init/spec.md
 */
class InitializeSettings implements IRepairStep {
	/**
	 * Defaults seeded only when unset (admin overrides survive), in seeding order:
	 * - contract_expiry_window_days: openspec/specs/contract-administration/spec.md
	 * - eol_warning_window_days: openspec/specs/application-lifecycle-tracking/spec.md
	 * - portfolio_report_page_size_ceiling: openspec/changes/portfolio-rationalization-time/specs
	 *   /portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
	 * - federation_*: openspec/specs/federated-catalog-sync/spec.md
	 *
	 * @var array<string, int|bool|string>
	 */
	private const SEEDED_DEFAULTS = [
		'contract_expiry_window_days' => 90,
		'eol_warning_window_days' => 180,
		'portfolio_report_page_size_ceiling' => 500,
		'federation_enabled' => false,
		'federation_directory_url' => 'https://directory.opencatalogi.nl',
		'federation_sync_interval' => 3600,
	];

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
		return 'Initialize Stackiq settings';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 * @spec   openspec/specs/repair-init/spec.md
	 */
	public function run(IOutput $output): void {
		$output->startProgress(1);

		try {
			$currentAppVersion = $this->appManager->getAppVersion(AppIdentity::APP_ID);

			// Only initialize if version changed or never initialized.
			if ($this->finishIfInitialized(output: $output, version: $currentAppVersion) === true) {
				return;
			}

			$this->initializeVersion(output: $output, version: $currentAppVersion);
		} catch (\Exception $e) {
			$this->recordFailedInitialization(output: $output, error: $e);
		}

		$output->advance(1);
		$output->finishProgress();
	}//end run()

	/**
	 * Finish the progress when this version was already initialized.
	 *
	 * @param IOutput $output  The output interface for progress reporting
	 * @param string  $version The current app version
	 *
	 * @return bool True when the version was already initialized
	 */
	private function finishIfInitialized(IOutput $output, string $version): bool {
		$lastInitVersion = $this->config->getValueString(AppIdentity::APP_ID, 'last_initialized_version', '');
		if ($lastInitVersion !== $version) {
			return false;
		}

		$output->info('Settings already initialized for version ' . $version);
		$output->advance(1);
		$output->finishProgress();
		return true;
	}//end finishIfInitialized()

	/**
	 * Seed the defaults, initialize the settings and mark the version initialized.
	 *
	 * @param IOutput $output  The output interface for progress reporting
	 * @param string  $version The current app version
	 *
	 * @return void
	 */
	private function initializeVersion(IOutput $output, string $version): void {
		$output->info('Initializing settings for version ' . $version);
		$this->logger->info('Stackiq repair: Starting initialization for version ' . $version);

		$this->seedDefaults();

		// Get the settings service and initialize.
		$settingsService = $this->container->get(SettingsService::class);
		$result = $settingsService->initialize();

		// Mark this version as initialized regardless of partial failures.
		// This prevents repeated attempts on every request.
		$this->config->setValueString(AppIdentity::APP_ID, 'last_initialized_version', $version);

		$this->reportInitializationErrors(output: $output, errors: ($result['errors'] ?? []));

		$output->info('Settings initialization completed');
		$this->logger->info('Stackiq repair: Initialization completed', ['result' => $result]);
	}//end initializeVersion()

	/**
	 * Seed each default only when the admin has not set it, so upgrades never
	 * clobber an operator's chosen value.
	 *
	 * @return void
	 */
	private function seedDefaults(): void {
		foreach (self::SEEDED_DEFAULTS as $key => $value) {
			if ($this->config->hasKey(AppIdentity::APP_ID, $key) === false) {
				$this->seedDefault(key: $key, value: $value);
			}
		}
	}//end seedDefaults()

	/**
	 * Write one default with the setter of its type.
	 *
	 * @param string          $key   The config key
	 * @param int|bool|string $value The default value
	 *
	 * @return void
	 */
	private function seedDefault(string $key, int|bool|string $value): void {
		match (true) {
			is_bool($value) => $this->config->setValueBool(AppIdentity::APP_ID, $key, $value),
			is_int($value) => $this->config->setValueInt(AppIdentity::APP_ID, $key, $value),
			default => $this->config->setValueString(AppIdentity::APP_ID, $key, $value),
		};
	}//end seedDefault()

	/**
	 * Report each initialization error as a warning.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 * @param mixed   $errors The errors the settings service reported
	 *
	 * @return void
	 */
	private function reportInitializationErrors(IOutput $output, mixed $errors): void {
		if (empty($errors) === true) {
			return;
		}

		foreach ($errors as $error) {
			$output->warning('Initialization warning: ' . $error);
			$this->logger->warning('Stackiq repair: ' . $error);
		}
	}//end reportInitializationErrors()

	/**
	 * Still mark the version as initialized to prevent repeated failures, and report the failure.
	 *
	 * @param IOutput    $output The output interface for progress reporting
	 * @param \Exception $error  The failure
	 *
	 * @return void
	 */
	private function recordFailedInitialization(IOutput $output, \Exception $error): void {
		$currentAppVersion = $this->appManager->getAppVersion(AppIdentity::APP_ID);
		$this->config->setValueString(AppIdentity::APP_ID, 'last_initialized_version', $currentAppVersion);
		$output->warning('Settings initialization failed: ' . $error->getMessage());
		$this->logger->error('Stackiq repair: Initialization failed', ['exception' => $error->getMessage()]);
	}//end recordFailedInitialization()
}//end class
