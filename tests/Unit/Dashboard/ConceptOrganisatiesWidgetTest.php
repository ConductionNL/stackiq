<?php

/**
 * The concept organisations dashboard widget keeps its pre-rename id.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-id-rename/spec.md#requirement-externally-owned-identifiers-stay-frozen
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Dashboard;

use OCA\Stackiq\Dashboard\ConceptOrganisatiesWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The widget id is not ours to rename.
 *
 * Nextcloud's Dashboard app stores each user's chosen widgets by widget id in
 * its own `dashboard` namespace in `oc_preferences`, which this app's repair
 * steps cannot reach. A renamed id does not error: the widget silently drops
 * off every dashboard that had it. That is what #708's rename sweep did, so
 * these tests pin the literal on both sides of the registration.
 *
 * @covers \OCA\Stackiq\Dashboard\ConceptOrganisatiesWidget
 */
class ConceptOrganisatiesWidgetTest extends TestCase {

	/**
	 * The id users' stored dashboard layouts refer to.
	 */
	private const FROZEN_ID = 'softwarecatalog_concept_organisaties_widget';

	/**
	 * getId() returns the pre-rename id, not one derived from the app id.
	 *
	 * @return void
	 */
	public function testGetIdReturnsTheFrozenPreRenameId(): void {
		$widget = new ConceptOrganisatiesWidget(
			$this->createMock(IL10N::class),
			$this->createMock(IURLGenerator::class),
		);

		$this->assertSame(self::FROZEN_ID, $widget->getId());
	}//end testGetIdReturnsTheFrozenPreRenameId()

	/**
	 * The frontend registers its render callback under the same id.
	 *
	 * OCA.Dashboard.register() is matched against IWidget::getId(); if the two
	 * differ the widget shows on the dashboard but never renders.
	 *
	 * @return void
	 */
	public function testFrontendRegistersUnderTheSameId(): void {
		$source = file_get_contents(__DIR__.'/../../../src/conceptOrganisatiesWidget.js');

		$this->assertIsString($source);
		$this->assertStringContainsString('OCA.Dashboard.register(', $source);
		$this->assertStringContainsString("'".self::FROZEN_ID."'", $source);
		$this->assertStringNotContainsString('stackiq_concept_organisaties_widget', $source);
	}//end testFrontendRegistersUnderTheSameId()
}//end class
