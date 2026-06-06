<?php

/**
 * Organization Settings Handler for SoftwareCatalog
 *
 * Extracted from SettingsService to reduce ExcessiveClassLength and TooManyMethods.
 * Handles organisation-domain configuration operations.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\Settings;

use InvalidArgumentException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Handles organisation-domain settings: user groups and organisation configuration.
 *
 * SettingsService delegates all organisation-config methods to this handler,
 * keeping its own class below ExcessiveClassLength and TooManyMethods thresholds.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */
class OrganizationSettingsHandler
{

    /**
     * The application name used as the config namespace.
     *
     * @var string
     */
    private const APP_NAME = 'softwarecatalog';

    /**
     * Constructor.
     *
     * @param IAppConfig      $config The application configuration service.
     * @param LoggerInterface $logger Logger instance.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Get the generic user groups configuration.
     *
     * @return string[] Array of group names.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function getGenericUserGroups(): array
    {
        $groupsJson = $this->config->getValueString(self::APP_NAME, 'generic_user_groups', '');

        if (empty($groupsJson) === true) {
            return ['software-catalog-users'];
        }

        $groups = json_decode($groupsJson, true);
        if (is_array($groups) === false) {
            return [];
        }

        return $groups;

    }//end getGenericUserGroups()

    /**
     * Set the generic user groups configuration.
     *
     * @param string[] $groups Array of group names to store.
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function setGenericUserGroups(array $groups): void
    {
        $this->validateOrganizationConfig(groups: $groups);
        $this->config->setValueString(self::APP_NAME, 'generic_user_groups', json_encode($groups, JSON_THROW_ON_ERROR));
        $this->logger->info('OrganizationSettingsHandler: Updated generic user groups', ['groups' => $groups]);

    }//end setGenericUserGroups()

    /**
     * Get the organisation admin groups configuration.
     *
     * @return string[] Array of group names.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function getOrganizationAdminGroups(): array
    {
        // DISABLED: No automatic group assignment for organization admins.
        // Users should be assigned groups explicitly via the admin UI.
        return [];

    }//end getOrganizationAdminGroups()

    /**
     * Set the organisation admin groups configuration.
     *
     * @param string[] $groups Array of group names to store.
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function setOrganizationAdminGroups(array $groups): void
    {
        $this->validateOrganizationConfig(groups: $groups);
        $this->config->setValueString(
            self::APP_NAME,
            'organization_admin_groups',
            json_encode($groups, JSON_THROW_ON_ERROR)
        );
        $this->logger->info('OrganizationSettingsHandler: Updated organization admin groups', ['groups' => $groups]);

    }//end setOrganizationAdminGroups()

    /**
     * Get the super user groups configuration.
     *
     * @return string[] Array of group names.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function getSuperUserGroups(): array
    {
        $groupsJson = $this->config->getValueString(self::APP_NAME, 'super_user_groups', '');

        if (empty($groupsJson) === true) {
            return ['admin', 'software-catalog-admins'];
        }

        $groups = json_decode($groupsJson, true);
        if (is_array($groups) === false) {
            return [];
        }

        return $groups;

    }//end getSuperUserGroups()

    /**
     * Set the super user groups configuration.
     *
     * @param string[] $groups Array of group names to store.
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function setSuperUserGroups(array $groups): void
    {
        $this->validateOrganizationConfig(groups: $groups);
        $this->config->setValueString(self::APP_NAME, 'super_user_groups', json_encode($groups, JSON_THROW_ON_ERROR));
        $this->logger->info('OrganizationSettingsHandler: Updated super user groups', ['groups' => $groups]);

    }//end setSuperUserGroups()

    /**
     * Validate group-name arrays.
     *
     * Guard clause: throws when any group name is empty or contains illegal characters.
     *
     * @param mixed[] $groups Group names to validate (each element is expected to be string).
     *
     * @return void
     *
     * @throws \InvalidArgumentException When a group name is invalid.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    private function validateOrganizationConfig(array $groups): void
    {
        foreach ($groups as $groupName) {
            if (is_string($groupName) === false || $groupName === '') {
                throw new InvalidArgumentException('Group names must be non-empty strings.');
            }

            if (preg_match('/[^a-zA-Z0-9._-]/', $groupName) === 1) {
                throw new InvalidArgumentException(
                    sprintf('Group name "%s" contains invalid characters.', $groupName)
                );
            }
        }

    }//end validateOrganizationConfig()
}//end class
