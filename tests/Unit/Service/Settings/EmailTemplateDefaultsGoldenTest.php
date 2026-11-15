<?php

/**
 * Golden test for EmailTemplateDefaults.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\Settings\EmailTemplateDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Pins the default content and variables of every email template.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\Settings
 */
final class EmailTemplateDefaultsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../../fixtures/settings/email-template-defaults.json';

	/**
	 * Every template's content and variables match the recorded ones, byte for byte.
	 *
	 * @return void
	 */
	public function testDefaultsMatchGolden(): void {
		$defaults = new EmailTemplateDefaults();
		$actual   = [];
		foreach (['organization_registration', 'organization_activation', 'user_creation', 'password_reset', '', 'unknown'] as $name) {
			$actual[$name] = ['template' => $defaults->template($name), 'variables' => $defaults->variables($name)];
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);

	}//end testDefaultsMatchGolden()
}//end class
