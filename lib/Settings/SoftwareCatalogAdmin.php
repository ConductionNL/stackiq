<?php
/**
 * Settings admin page for SoftwareCatalog.
 *
 * @category  Settings
 * @package   OCA\SoftwareCatalog\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

class SoftwareCatalogAdmin implements ISettings
{

    /**
     * The localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * The application configuration service.
     *
     * @var IAppConfig
     */
    private IAppConfig $config;

    /**
     * Constructor for SoftwareCatalogAdmin settings.
     *
     * @param IAppConfig $config The application configuration service
     * @param IL10N      $l      The localization service
     */
    public function __construct(IAppConfig $config, IL10N $l)
    {
        $this->config = $config;
        $this->l      = $l;
    }//end __construct()

    /**
     * Returns the admin settings form.
     *
     * @return TemplateResponse The template response for the settings form
     */
    public function getForm(): TemplateResponse
    {
        $parameters = [
            'mySetting' => $this->config->getValueString('softwarecatalog', 'software_catalog_setting', 'true') === 'true',
        ];

        return new TemplateResponse('softwarecatalog', 'settings/admin', $parameters, 'admin');
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
}//end class
