<?php

/**
 * Unit tests for the Stackiq admin settings section: its icon is the app's
 * dark icon from the URL generator, its name goes through translation, and it
 * registers under the app id.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Sections
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Sections;

use OCA\Stackiq\Sections\StackiqAdmin;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the StackiqAdmin settings section.
 *
 * @covers \OCA\Stackiq\Sections\StackiqAdmin
 */
final class StackiqAdminSectionTest extends TestCase {

	/**
	 * The icon is the app's dark icon, resolved through the URL generator.
	 *
	 * @return void
	 */
	public function testTheIconIsTheAppsDarkIcon(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('imagePath')->with('stackiq', 'app-dark.svg')
			->willReturn('/apps/stackiq/img/app-dark.svg');

		$section = new StackiqAdmin($this->createMock(IL10N::class), $urls);

		self::assertSame('/apps/stackiq/img/app-dark.svg', $section->getIcon());
	}//end testTheIconIsTheAppsDarkIcon()

	/**
	 * The name is the translated app name, and the section registers under the app id at priority 97.
	 *
	 * @return void
	 */
	public function testTheSectionIsTheTranslatedAppSection(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with('Stackiq')->willReturn('Stackiq (nl)');

		$section = new StackiqAdmin($l10n, $this->createMock(IURLGenerator::class));

		self::assertSame('Stackiq (nl)', $section->getName());
		self::assertSame('stackiq', $section->getID());
		self::assertSame(97, $section->getPriority());
	}//end testTheSectionIsTheTranslatedAppSection()
}//end class
