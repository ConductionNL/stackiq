<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Service\SettingsService;

/**
 * Repair step that initializes SoftwareCatalog settings on install/upgrade.
 *
 * This runs only during app install or upgrade, not on every request.
 */
class InitializeSettings implements IRepairStep
{
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    public function getName(): string
    {
        return 'Initialize SoftwareCatalog settings';
    }//end getName()

    public function run(IOutput $output): void
    {
        $output->startProgress(1);

        try {
            $currentAppVersion      = $this->appManager->getAppVersion(Application::APP_ID);
            $lastInitializedVersion = $this->config->getValueString(Application::APP_ID, 'last_initialized_version', '');

            // Only initialize if version changed or never initialized
            if ($lastInitializedVersion === $currentAppVersion) {
                $output->info('Settings already initialized for version '.$currentAppVersion);
                $output->advance(1);
                $output->finishProgress();
                return;
            }

            $output->info('Initializing settings for version '.$currentAppVersion);
            $this->logger->info('SoftwareCatalog repair: Starting initialization for version '.$currentAppVersion);

            // Get the settings service and initialize
            $settingsService = $this->container->get(SettingsService::class);
            $result          = $settingsService->initialize();

            // Mark this version as initialized regardless of partial failures
            // This prevents repeated attempts on every request
            $this->config->setValueString(Application::APP_ID, 'last_initialized_version', $currentAppVersion);

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $output->warning('Initialization warning: '.$error);
                    $this->logger->warning('SoftwareCatalog repair: '.$error);
                }
            }

            $output->info('Settings initialization completed');
            $this->logger->info('SoftwareCatalog repair: Initialization completed', ['result' => $result]);
        } catch (\Exception $e) {
            // Still mark as initialized to prevent repeated failures
            $currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);
            $this->config->setValueString(Application::APP_ID, 'last_initialized_version', $currentAppVersion);
            $output->warning('Settings initialization failed: '.$e->getMessage());
            $this->logger->error('SoftwareCatalog repair: Initialization failed', ['exception' => $e->getMessage()]);
        }//end try

        $output->advance(1);
        $output->finishProgress();
    }//end run()
}//end class
