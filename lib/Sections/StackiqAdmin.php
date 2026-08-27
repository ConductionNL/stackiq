<?php

/**
 * Settings section for Stackiq admin panel.
 *
 * @category  Sections
 * @package   OCA\Stackiq\Sections
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

namespace OCA\Stackiq\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class StackiqAdmin implements IIconSection {

	/**
	 * The localization service.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * The URL generator service.
	 *
	 * @var IURLGenerator
	 */
	private IURLGenerator $urlGenerator;

	/**
	 * Constructor for StackiqAdmin section.
	 *
	 * @param IL10N $l10n The localization service
	 * @param IURLGenerator $urlGenerator The URL generator service
	 */
	public function __construct(IL10N $l10n, IURLGenerator $urlGenerator) {
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
	}//end __construct()

	/**
	 * Returns the icon URL for this settings section.
	 *
	 * @return string The icon URL
	 */
	public function getIcon(): string {
		// phpcs:ignore -- named parameters unsafe for Nextcloud core methods (param names vary by NC version)
		return $this->urlGenerator->imagePath('stackiq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * Returns the unique identifier for this section.
	 *
	 * @return string The section ID
	 */
	public function getID(): string {
		return 'stackiq';
	}//end getID()

	/**
	 * Returns the human-readable name of this section.
	 *
	 * @return string The translated section name
	 */
	public function getName(): string {
		return $this->l10n->t('Stackiq');
	}//end getName()

	/**
	 * Returns the priority for ordering this section.
	 *
	 * @return int The priority value
	 */
	public function getPriority(): int {
		return 97;
	}//end getPriority()
}//end class
