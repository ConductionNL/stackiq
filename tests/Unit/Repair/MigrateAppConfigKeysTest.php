<?php

/**
 * Unit tests for MigrateAppConfigKeys.
 *
 * The step exists because the failure it prevents is SILENT: every reader in
 * this app supplies a default, so an appconfig row stranded under the old app
 * id reverts a setting rather than raising. These tests therefore pin the four
 * decisions that decide whether a value survives — exhaustive enumeration,
 * the reserved-key skip, the do-not-clobber rule, and type-preserving copy —
 * plus the requirement that a throw never escapes into the installer.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Stackiq\Repair\MigrateAppConfigKeys
 */
class MigrateAppConfigKeysTest extends TestCase {
	/**
	 * The legacy app id every stored row was written under.
	 *
	 * Spelled out literally rather than read from the class under test: if the
	 * constant ever moved, reading it here would make the test agree with the
	 * change instead of catching it.
	 *
	 * @var string
	 */
	// Mirrors the step's own constant rather than repeating the literal.
	// A bulk rename rewrote this to the NEW id, and the test then asserted
	// that the migration reads from the namespace it writes TO -- which is
	// a migration that does nothing. Binding it to the source constant makes
	// that drift impossible.
	private const LEGACY = MigrateAppConfigKeys::LEGACY_APP_ID;

	/**
	 * The step reports a name that says what it does.
	 *
	 * @return void
	 */
	public function testGetNameDescribesTheMigration(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$step = new MigrateAppConfigKeys($appConfig, new NullLogger());

		$this->assertStringContainsString('stackiq', $step->getName());
		$this->assertStringContainsString('stackiq', $step->getName());
	}//end testGetNameDescribesTheMigration()

	/**
	 * The legacy app id is exactly the pre-rename app id.
	 *
	 * @return void
	 */
	public function testLegacyAppIdIsTheOldAppId(): void {
		$this->assertSame(self::LEGACY, MigrateAppConfigKeys::LEGACY_APP_ID);
		$this->assertNotSame(Application::APP_ID, MigrateAppConfigKeys::LEGACY_APP_ID);
	}//end testLegacyAppIdIsTheOldAppId()

	/**
	 * A plain string value is copied into the new namespace.
	 *
	 * @return void
	 */
	public function testCopiesStringValueToTheNewAppId(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->with(self::LEGACY)->willReturn(['federation_directory_url']);
		$appConfig->method('getAllValues')->with(self::LEGACY)->willReturn(
			['federation_directory_url' => 'https://directory.example.org']
		);
		$appConfig->method('hasKey')->willReturn(false);

		$appConfig->expects($this->once())
			->method('setValueString')
			->with(Application::APP_ID, 'federation_directory_url', 'https://directory.example.org')
			->willReturn(true);

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testCopiesStringValueToTheNewAppId()

	/**
	 * The stored TYPE survives the copy.
	 *
	 * A bool written back as the string "1" reads as a different value through
	 * `getValueBool()` on some drivers and raises a type conflict on others, so
	 * the type is not cosmetic.
	 *
	 * @return void
	 */
	public function testPreservesTheStoredValueType(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['federation_enabled', 'page_size', 'ratio']);
		$appConfig->method('getAllValues')->willReturn(
			[
				'federation_enabled' => true,
				'page_size' => 250,
				'ratio' => 1.5,
			]
		);
		$appConfig->method('hasKey')->willReturn(false);

		$appConfig->expects($this->once())->method('setValueBool')
			->with(Application::APP_ID, 'federation_enabled', true)->willReturn(true);
		$appConfig->expects($this->once())->method('setValueInt')
			->with(Application::APP_ID, 'page_size', 250)->willReturn(true);
		$appConfig->expects($this->once())->method('setValueFloat')
			->with(Application::APP_ID, 'ratio', 1.5)->willReturn(true);
		$appConfig->expects($this->never())->method('setValueString');

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testPreservesTheStoredValueType()

	/**
	 * `false` and `0` are copied — they are chosen values, not absences.
	 *
	 * @return void
	 */
	public function testCopiesFalseAndZeroBecauseTheyAreRealValues(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['federation_enabled', 'retry_count']);
		$appConfig->method('getAllValues')->willReturn(
			[
				'federation_enabled' => false,
				'retry_count' => 0,
			]
		);
		$appConfig->method('hasKey')->willReturn(false);

		$appConfig->expects($this->once())->method('setValueBool')
			->with(Application::APP_ID, 'federation_enabled', false)->willReturn(true);
		$appConfig->expects($this->once())->method('setValueInt')
			->with(Application::APP_ID, 'retry_count', 0)->willReturn(true);

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testCopiesFalseAndZeroBecauseTheyAreRealValues()

	/**
	 * The Nextcloud-reserved keys are never copied.
	 *
	 * This is the arm that matters most. `AppManager::enableApp()` writes
	 * `enabled` as type MIXED; copying it with `setValueString()` stores STRING,
	 * and the next `occ app:enable` then fails permanently with
	 * `AppConfigTypeConflictException` — hit before the app can run anything
	 * that would repair it.
	 *
	 * @return void
	 */
	public function testNeverCopiesTheNextcloudReservedKeys(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['enabled', 'installed_version', 'types']);
		$appConfig->method('getAllValues')->willReturn(
			[
				'enabled' => 'yes',
				'installed_version' => '0.1.141',
				'types' => 'filesystem',
			]
		);
		$appConfig->method('hasKey')->willReturn(false);

		$appConfig->expects($this->never())->method('setValueString');
		$appConfig->expects($this->never())->method('setValueBool');
		$appConfig->expects($this->never())->method('setValueInt');
		$appConfig->expects($this->never())->method('setValueArray');

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testNeverCopiesTheNextcloudReservedKeys()

	/**
	 * A key already present under the new app id is left alone.
	 *
	 * @return void
	 */
	public function testDoesNotClobberAValueTheNewNamespaceAlreadyHolds(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['federation_directory_url']);
		$appConfig->method('getAllValues')->willReturn(['federation_directory_url' => 'https://old.example.org']);
		$appConfig->method('hasKey')
			->with(Application::APP_ID, 'federation_directory_url')
			->willReturn(true);

		$appConfig->expects($this->never())->method('setValueString');

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testDoesNotClobberAValueTheNewNamespaceAlreadyHolds()

	/**
	 * The legacy rows are never deleted.
	 *
	 * @return void
	 */
	public function testIsNonDestructive(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['federation_peers']);
		$appConfig->method('getAllValues')->willReturn(['federation_peers' => ['a', 'b']]);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('setValueArray')->willReturn(true);

		$appConfig->expects($this->never())->method('deleteKey');
		$appConfig->expects($this->never())->method('deleteApp');

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testIsNonDestructive()

	/**
	 * An empty string is not copied — it is indistinguishable from the default.
	 *
	 * @return void
	 */
	public function testSkipsEmptyValues(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['blank', 'empty_list']);
		$appConfig->method('getAllValues')->willReturn(
			[
				'blank' => '',
				'empty_list' => [],
			]
		);
		$appConfig->method('hasKey')->willReturn(false);

		$appConfig->expects($this->never())->method('setValueString');
		$appConfig->expects($this->never())->method('setValueArray');

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testSkipsEmptyValues()

	/**
	 * A throw NEVER escapes into the installer.
	 *
	 * This step runs under `<install>` — the only hook that fires on the fresh
	 * install an app-id rename performs — so an escaping throw aborts the
	 * install and the app never enables at all. A reverted setting is
	 * recoverable; a failed install is not.
	 *
	 * @return void
	 */
	public function testAThrowNeverEscapesTheInstaller(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willThrowException(new RuntimeException('database gone'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($output);
	}//end testAThrowNeverEscapesTheInstaller()

	/**
	 * A throw during the WRITE is caught too, not only during the reads.
	 *
	 * @return void
	 */
	public function testAThrowDuringTheWriteIsAlsoCaught(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['federation_directory_url']);
		$appConfig->method('getAllValues')->willReturn(['federation_directory_url' => 'https://x']);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('setValueString')->willThrowException(new RuntimeException('write failed'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($output);
	}//end testAThrowDuringTheWriteIsAlsoCaught()

	/**
	 * Re-running after a completed migration writes nothing.
	 *
	 * @return void
	 */
	public function testIsIdempotentOnASecondRun(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['a', 'b', 'c']);
		$appConfig->method('getAllValues')->willReturn(['a' => 'x', 'b' => 2, 'c' => true]);
		$appConfig->method('hasKey')->willReturn(true);

		$appConfig->expects($this->never())->method('setValueString');
		$appConfig->expects($this->never())->method('setValueInt');
		$appConfig->expects($this->never())->method('setValueBool');

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testIsIdempotentOnASecondRun()
}//end class
