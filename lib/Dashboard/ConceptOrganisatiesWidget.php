<?php
/**
 * Concept Organisaties Dashboard Widget.
 *
 * @category  Dashboard
 * @package   OCA\SoftwareCatalog\Dashboard
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use OCA\SoftwareCatalog\AppInfo\Application;

class ConceptOrganisatiesWidget implements IWidget
{
    /**
     * Constructor for ConceptOrganisatiesWidget.
     *
     * @param IL10N         $l10n The localization service
     * @param IURLGenerator $url  The URL generator service
     */
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $url
    ) {
    }//end __construct()

    /**
     * Returns the unique widget identifier.
     *
     * @return string The widget ID
     */
    public function getId(): string
    {
        return 'softwarecatalog_concept_organisaties_widget';
    }//end getId()

    /**
     * Returns the widget title.
     *
     * @return string The translated widget title
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Concept organisaties');
    }//end getTitle()

    /**
     * Returns the display order for this widget.
     *
     * @return int The widget order value
     */
    public function getOrder(): int
    {
        return 10;
    }//end getOrder()

    /**
     * Returns the CSS icon class for this widget.
     *
     * @return string The icon CSS class name
     */
    public function getIconClass(): string
    {
        return 'icon-softwarecatalog-widget';
    }//end getIconClass()

    /**
     * Returns the URL for the full widget page.
     *
     * @return string|null The URL or null if no dedicated page
     */
    public function getUrl(): ?string
    {
        return null;
    }//end getUrl()

    /**
     * Loads the required scripts and styles for this widget.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
     */
    public function load(): void
    {
        $appId      = Application::APP_ID;
        $scriptName = $appId.'-conceptOrganisatiesWidget';
        Util::addScript(application: $appId, file: $scriptName);
        Util::addStyle(application: $appId, file: 'dashboardWidgets');
    }//end load()
}//end class
