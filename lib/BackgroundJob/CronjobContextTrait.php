<?php

/**
 * Cronjob Context Trait
 *
 * This trait provides functionality for background jobs to set and clear
 * user and organisation context during execution. This allows cronjobs
 * to run with proper RBAC permissions based on configured settings.
 *
 * @category Trait
 * @package  OCA\SoftwareCatalog\BackgroundJob
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\BackgroundJob;

use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Trait CronjobContextTrait
 *
 * Provides methods for setting and clearing user/organisation context
 * during cronjob execution to enable proper RBAC authorization.
 *
 * Requirements for using this trait:
 * - The class must have access to container for dependency injection
 * - The class must have access to a logger ($this->logger)
 *
 * @package OCA\SoftwareCatalog\BackgroundJob
 */
trait CronjobContextTrait
{
    /**
     * The user that was set for this cronjob session
     *
     * @var \OCP\IUser|null
     */
    private ?\OCP\IUser $cronjobUser = null;

    /**
     * The organisation UUID that was set for this cronjob session
     *
     * @var string|null
     */
    private ?string $cronjobOrganisationUuid = null;

    /**
     * Whether the context was successfully set
     *
     * @var bool
     */
    private bool $contextSet = false;

    /**
     * Set the cronjob context (user and organisation) based on configuration.
     *
     * This method should be called at the start of the cronjob run() method.
     * It retrieves the configured user and organisation for the job and sets
     * them in the session so that RBAC checks pass correctly.
     *
     * @param string $jobId The identifier of the cronjob (e.g., 'organization_contact_sync')
     *
     * @return bool True if context was successfully set, false otherwise
     */
    protected function setCronjobContext(string $jobId): bool
    {
        try {
            // Get the settings service to retrieve configuration.
            $settingsService = \OC::$server->get(\OCA\SoftwareCatalog\Service\SettingsService::class);
            $context = $settingsService->getCronjobContext($jobId);

            if ($context === null) {
                $this->getLogger()->warning('[CRONJOB] No context configured for cronjob', [
                    'jobId' => $jobId
                ]);
                return false;
            }

            // Check if job is enabled.
            if (!($context['enabled'] ?? true)) {
                $this->getLogger()->info('[CRONJOB] Cronjob is disabled', [
                    'jobId' => $jobId
                ]);
                return false;
            }

            $userId = $context['userId'];
            $organisationUuid = $context['organisationUuid'];

            // Get the user.
            $userManager = \OC::$server->get(IUserManager::class);
            $user = $userManager->get($userId);

            if ($user === null) {
                $this->getLogger()->error('[CRONJOB] Configured user not found', [
                    'jobId' => $jobId,
                    'userId' => $userId
                ]);
                return false;
            }

            // Set the user in the session.
            $userSession = \OC::$server->get(IUserSession::class);
            $userSession->setUser($user);

            $this->cronjobUser = $user;
            $this->cronjobOrganisationUuid = $organisationUuid;

            // Set the active organisation in OpenRegister if available.
            if (class_exists('\OCA\OpenRegister\Service\OrganisationService')) {
                try {
                    $config = \OC::$server->get(IConfig::class);

                    // Set the active organisation in user config (this is what OrganisationService reads).
                    $config->setUserValue(
                        $userId,
                        'openregister',
                        'active_organisation',
                        $organisationUuid
                    );

                    $this->getLogger()->info('[CRONJOB] Context set successfully', [
                        'jobId' => $jobId,
                        'userId' => $userId,
                        'organisationUuid' => $organisationUuid
                    ]);

                } catch (\Exception $e) {
                    $this->getLogger()->warning('[CRONJOB] Failed to set active organisation', [
                        'jobId' => $jobId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->contextSet = true;
            return true;

        } catch (\Exception $e) {
            $this->getLogger()->error('[CRONJOB] Failed to set cronjob context', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Clear the cronjob context after execution.
     *
     * This method should be called at the end of the cronjob run() method
     * (in a finally block) to clean up the session state.
     *
     * @param string $jobId The identifier of the cronjob
     *
     * @return void
     */
    protected function clearCronjobContext(string $jobId): void
    {
        try {
            if (!$this->contextSet) {
                return;
            }

            // Clear the user session.
            $userSession = \OC::$server->get(IUserSession::class);
            $userSession->setUser(null);

            $this->getLogger()->debug('[CRONJOB] Context cleared', [
                'jobId' => $jobId
            ]);

            $this->cronjobUser = null;
            $this->cronjobOrganisationUuid = null;
            $this->contextSet = false;

        } catch (\Exception $e) {
            $this->getLogger()->warning('[CRONJOB] Failed to clear cronjob context', [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if context is set for this cronjob session.
     *
     * @return bool True if context is set
     */
    protected function hasContext(): bool
    {
        return $this->contextSet;
    }

    /**
     * Get the current cronjob user.
     *
     * @return \OCP\IUser|null The user or null if not set
     */
    protected function getCronjobUser(): ?\OCP\IUser
    {
        return $this->cronjobUser;
    }

    /**
     * Get the current cronjob organisation UUID.
     *
     * @return string|null The organisation UUID or null if not set
     */
    protected function getCronjobOrganisationUuid(): ?string
    {
        return $this->cronjobOrganisationUuid;
    }

    /**
     * Get the logger instance.
     *
     * Classes using this trait should implement this method to return their logger.
     *
     * @return LoggerInterface
     */
    abstract protected function getLogger(): LoggerInterface;
}



