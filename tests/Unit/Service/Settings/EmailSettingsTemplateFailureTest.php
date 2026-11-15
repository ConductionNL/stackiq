<?php

/**
 * Unit tests for how EmailSettings lists templates when one cannot be read.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\Settings\EmailSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A template that cannot be read is listed as null and logged; the others still answer.
 *
 * @covers \OCA\Stackiq\Service\Settings\EmailSettings
 * @uses   \OCA\Stackiq\Service\Settings\EmailTemplateDefaults
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class EmailSettingsTemplateFailureTest extends TestCase {

	/**
	 * One unreadable template becomes null with a warning naming it.
	 *
	 * @return void
	 */
	public function testAnUnreadableTemplateIsListedAsNull(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'email_template_user_creation') {
					throw new RuntimeException('config table locked');
				}

				return 'stored ' . $key;
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Failed to get template user_creation',
			['error' => 'config table locked']
		);

		$templates = (new EmailSettings(config: $config, logger: $logger))->getAllEmailTemplates();

		$this->assertSame(
			[
				'organization_registration' => 'stored email_template_organization_registration',
				'organization_activation' => 'stored email_template_organization_activation',
				'user_creation' => null,
				'user_organisation' => 'stored email_template_user_organisation',
			],
			$templates
		);
	}//end testAnUnreadableTemplateIsListedAsNull()
}//end class
