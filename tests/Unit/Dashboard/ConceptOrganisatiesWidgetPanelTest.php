<?php

/**
 * Unit tests for how the concept-organisations dashboard widget presents
 * itself: its title goes through translation, it sits at order 10 with the
 * app's widget icon class, and it links nowhere.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Dashboard
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
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
 * Panel presentation of ConceptOrganisatiesWidget.
 *
 * @covers \OCA\Stackiq\Dashboard\ConceptOrganisatiesWidget
 */
final class ConceptOrganisatiesWidgetPanelTest extends TestCase {

	/**
	 * The title is the translation of "Concept organisaties", asked for once.
	 *
	 * @return void
	 */
	public function testTheTitleIsTranslated(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with('Concept organisaties')->willReturn('Draft organisations');

		$widget = new ConceptOrganisatiesWidget($l10n, $this->createMock(IURLGenerator::class));

		self::assertSame('Draft organisations', $widget->getTitle());
	}//end testTheTitleIsTranslated()

	/**
	 * The panel sits at order 10 with the app's widget icon class, and has no "more" link.
	 *
	 * @return void
	 */
	public function testThePanelPlacementAndIcon(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->never())->method($this->anything());
		$widget = new ConceptOrganisatiesWidget($this->createMock(IL10N::class), $urls);

		self::assertSame(10, $widget->getOrder());
		self::assertSame('icon-stackiq-widget', $widget->getIconClass());
		self::assertNull($widget->getUrl());
	}//end testThePanelPlacementAndIcon()
}//end class
