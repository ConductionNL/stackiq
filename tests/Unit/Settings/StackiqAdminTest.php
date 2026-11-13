<?php
/**
 * The stackiq admin settings page: its form hands the app version to the
 * frontend, and it registers in the stackiq section with nothing delegated.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Settings\StackiqAdmin;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Stackiq\Settings\StackiqAdmin
 */
class StackiqAdminTest extends TestCase {
	/**
	 * The form provides the installed version as initial state and renders
	 * the admin template without parameters.
	 *
	 * @return void
	 */
	public function testTheFormProvidesTheVersion(): void {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->expects($this->once())->method('getAppVersion')->with('stackiq')->willReturn('2.4.0');
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->expects($this->once())->method('provideInitialState')->with('version', '2.4.0');

		$form = (new StackiqAdmin(
			config: $this->createMock(originalClassName: IAppConfig::class),
			l10n: $this->createMock(originalClassName: IL10N::class),
			appManager: $appManager,
			initialState: $initialState
		))->getForm();

		$this->assertSame(expected: 'stackiq', actual: $form->getApp());
		$this->assertSame(expected: 'settings/admin', actual: $form->getTemplateName());
		$this->assertSame(expected: [], actual: $form->getParams());
	}//end testTheFormProvidesTheVersion()

	/**
	 * The page sits in the stackiq section at priority 10, is not a named
	 * delegation and delegates no app config keys.
	 *
	 * @return void
	 */
	public function testThePageRegistersInTheStackiqSectionWithNothingDelegated(): void {
		$admin = new StackiqAdmin(
			config: $this->createMock(originalClassName: IAppConfig::class),
			l10n: $this->createMock(originalClassName: IL10N::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			initialState: $this->createMock(originalClassName: IInitialState::class)
		);

		$this->assertSame(
			expected: ['section' => 'stackiq', 'priority' => 10, 'name' => null, 'authorized' => []],
			actual: [
				'section' => $admin->getSection(),
				'priority' => $admin->getPriority(),
				'name' => $admin->getName(),
				'authorized' => $admin->getAuthorizedAppConfig(),
			]
		);
	}//end testThePageRegistersInTheStackiqSectionWithNothingDelegated()
}//end class
