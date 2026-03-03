<?php
/**
 * Settings section for SoftwareCatalog admin panel.
 *
 * @category  Sections
 * @package   OCA\SoftwareCatalog\Sections
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class SoftwareCatalogAdmin implements IIconSection
{

    /**
     * The localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * The URL generator service.
     *
     * @var IURLGenerator
     */
    private IURLGenerator $urlGenerator;

    /**
     * Constructor for SoftwareCatalogAdmin section.
     *
     * @param IL10N         $l            The localization service
     * @param IURLGenerator $urlGenerator The URL generator service
     */
    public function __construct(IL10N $l, IURLGenerator $urlGenerator)
    {
        $this->l            = $l;
        $this->urlGenerator = $urlGenerator;
    }//end __construct()

    /**
     * Returns the icon URL for this settings section.
     *
     * @return string The icon URL
     */
    public function getIcon(): string
    {
        // phpcs:ignore -- named parameters unsafe for Nextcloud core methods (param names vary by NC version)
        return $this->urlGenerator->imagePath('core', 'actions/settings-dark.svg');
    }//end getIcon()

    /**
     * Returns the unique identifier for this section.
     *
     * @return string The section ID
     */
    public function getID(): string
    {
        return 'softwarecatalog';
    }//end getID()

    /**
     * Returns the human-readable name of this section.
     *
     * @return string The translated section name
     */
    public function getName(): string
    {
        return $this->l->t('Software Catalog');
    }//end getName()

    /**
     * Returns the priority for ordering this section.
     *
     * @return int The priority value
     */
    public function getPriority(): int
    {
        return 97;
    }//end getPriority()
}//end class
