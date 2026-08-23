<?php

/**
 * Concept Organisaties Dashboard Widget.
 *
 * @category  Dashboard
 * @package   OCA\Stackiq\Dashboard
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

namespace OCA\Stackiq\Dashboard;

use OCA\Stackiq\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

class ConceptOrganisatiesWidget implements IWidget {
	/**
	 * Constructor for ConceptOrganisatiesWidget.
	 *
	 * @param IL10N $l10n The localization service
	 * @param IURLGenerator $url The URL generator service
	 */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
	) {
	}//end __construct()

	/**
	 * Returns the unique widget identifier.
	 *
	 * @return string The widget ID
	 */
	public function getId(): string {
		// FROZEN across the stackiq -> stackiq rename. The Dashboard app
		// stores each user's chosen widgets BY WIDGET ID, in its own `dashboard`
		// appid namespace in `oc_preferences` — data this app's repair steps
		// cannot reach. Renaming this id therefore does not error: the widget
		// simply stops matching the stored selection and silently vanishes from
		// every dashboard that had it.
		return 'stackiq_concept_organisaties_widget';
	}//end getId()

	/**
	 * Returns the widget title.
	 *
	 * @return string The translated widget title
	 */
	public function getTitle(): string {
		return $this->l10n->t('Concept organisaties');
	}//end getTitle()

	/**
	 * Returns the display order for this widget.
	 *
	 * @return int The widget order value
	 */
	public function getOrder(): int {
		return 10;
	}//end getOrder()

	/**
	 * Returns the CSS icon class for this widget.
	 *
	 * @return string The icon CSS class name
	 */
	public function getIconClass(): string {
		return 'icon-stackiq-widget';
	}//end getIconClass()

	/**
	 * Returns the URL for the full widget page.
	 *
	 * @return string|null The URL or null if no dedicated page
	 */
	public function getUrl(): ?string {
		return null;
	}//end getUrl()

	/**
	 * Loads the required scripts and styles for this widget.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/specs/concept-organizations-widget/spec.md
	 */
	public function load(): void {
		$appId = Application::APP_ID;
		// Shared chunks emitted by webpack splitChunks + runtimeChunk (see webpack.config.js).
		// Order: runtime → vendor → nc-vue → widget.
		Util::addScript(application: $appId, file: $appId . '-runtime');
		Util::addScript(application: $appId, file: $appId . '-shared-vendor');
		Util::addScript(application: $appId, file: $appId . '-shared-nc-vue');
		Util::addScript(application: $appId, file: $appId . '-conceptOrganisatiesWidget');
		Util::addStyle(application: $appId, file: 'dashboardWidgets');
	}//end load()
}//end class
