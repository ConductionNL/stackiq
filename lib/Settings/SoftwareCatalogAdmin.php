<?php
/**
 * Settings admin page for SoftwareCatalog.
 *
 * @category  Settings
 * @package   OCA\SoftwareCatalog\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Settings\IDelegatedSettings;

class SoftwareCatalogAdmin implements IDelegatedSettings
{

    /**
     * The localization service.
     *
     * @var IL10N
     */
    private IL10N $l10n;

    /**
     * The application configuration service.
     *
     * @var IAppConfig
     */
    private IAppConfig $config;

    /**
     * The app manager service.
     *
     * @var IAppManager
     */
    private IAppManager $appManager;

    /**
     * The initial state service.
     *
     * @var IInitialState
     */
    private IInitialState $initialState;

    /**
     * Constructor for SoftwareCatalogAdmin settings.
     *
     * @param IAppConfig    $config       The application configuration service
     * @param IL10N         $l10n         The localization service
     * @param IAppManager   $appManager   The app manager service
     * @param IInitialState $initialState The initial state service
     */
    public function __construct(IAppConfig $config, IL10N $l10n, IAppManager $appManager, IInitialState $initialState)
    {
        $this->config       = $config;
        $this->l10n         = $l10n;
        $this->appManager   = $appManager;
        $this->initialState = $initialState;
    }//end __construct()

    /**
     * Returns the admin settings form.
     *
     * @return TemplateResponse The template response for the settings form
     */
    public function getForm(): TemplateResponse
    {
        $this->initialState->provideInitialState('version', $this->appManager->getAppVersion('softwarecatalog'));

        return new TemplateResponse('softwarecatalog', 'settings/admin', []);
    }//end getForm()

    /**
     * Returns the settings section identifier.
     *
     * @return string The settings section name
     */
    public function getSection(): string
    {
        // Name of the previously created section.
        $sectionName = 'softwarecatalog';
        return $sectionName;
    }//end getSection()

    /**
     * Returns the priority for ordering this settings form.
     *
     * The forms are arranged in ascending order of the priority values.
     * It is required to return a value between 0 and 100.
     *
     * @return int The priority value (0-100)
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()

    /**
     * The human-readable name of this delegated settings section.
     *
     * Required by IDelegatedSettings so an admin can authorize a non-admin group
     * to manage this section (and so `#[AuthorizedAdminSetting]` can gate the
     * moderation REST endpoints against it). Returns null to inherit the section
     * label.
     *
     * @return string|null The settings name, or null to use the section name.
     */
    public function getName(): ?string
    {
        return null;
    }//end getName()

    /**
     * App config keys an authorized (delegated) admin may manage.
     *
     * Returned as a map of appId => list of allowed config keys. SoftwareCatalog
     * exposes no delegatable sub-keys, so this is intentionally empty; the
     * `#[AuthorizedAdminSetting]` attribute still scopes the endpoints to full
     * admins (fail-closed). Required by IDelegatedSettings — its absence is a
     * fatal class-loading error that blanks every Nextcloud settings page.
     *
     * @return array<string, string[]> Map of appId to allowed config keys.
     */
    public function getAuthorizedAppConfig(): array
    {
        return [];
    }//end getAuthorizedAppConfig()
}//end class
