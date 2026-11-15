<?php

/**
 * Unit tests for AmefRegisterId.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\Stackiq\Service\ArchiMate\AmefRegisterId;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the register id lookup order and its positive-int validation.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AmefRegisterIdTest extends TestCase {

	/**
	 * Build the resolver over a key/value app config.
	 *
	 * @param array<string, string> $values The stored legacy values.
	 *
	 * @return AmefRegisterId
	 */
	private function resolver(array $values = []): AmefRegisterId {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values): string {
				return $values[$key] ?? $default;
			}
		);

		return new AmefRegisterId($config);
	}//end resolver()

	/**
	 * The JSON register id wins over the legacy keys.
	 *
	 * @return void
	 */
	public function testJsonRegisterIdWins(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('getValueString');

		$this->assertSame(7, (new AmefRegisterId($config))->resolve(['register_id' => '7', 'register' => '8']));
	}//end testJsonRegisterIdWins()

	/**
	 * The plain register key is used when register_id is missing.
	 *
	 * @return void
	 */
	public function testJsonRegisterKeyIsSecondChoice(): void {
		$this->assertSame(8, $this->resolver(['amef_register' => '99'])->resolve(['register' => 8]));
	}//end testJsonRegisterKeyIsSecondChoice()

	/**
	 * Without JSON ids the legacy amef_register_id key is read.
	 *
	 * @return void
	 */
	public function testLegacyRegisterIdKey(): void {
		$this->assertSame(21, $this->resolver(['amef_register_id' => '21'])->resolve([]));
	}//end testLegacyRegisterIdKey()

	/**
	 * The legacy amef_register key overrides amef_register_id.
	 *
	 * @return void
	 */
	public function testLegacyRegisterKeyOverridesRegisterIdKey(): void {
		$resolver = $this->resolver(['amef_register_id' => '21', 'amef_register' => '22']);

		$this->assertSame(22, $resolver->resolve(['register_id' => '']));
	}//end testLegacyRegisterKeyOverridesRegisterIdKey()

	/**
	 * Values that are not a positive number resolve to null.
	 *
	 * @param array<string, mixed> $amefConfig The AMEF configuration.
	 *
	 * @return void
	 */
	#[DataProvider('invalidIds')]
	public function testInvalidIdsResolveToNull(array $amefConfig): void {
		$this->assertNull($this->resolver()->resolve($amefConfig));
	}//end testInvalidIdsResolveToNull()

	/**
	 * Configurations without a usable register id.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalidIds(): array {
		return [
			'nothing configured' => [[]],
			'zero'               => [['register_id' => '0']],
			'negative'           => [['register_id' => -3]],
			'slug'               => [['register_id' => 'gemma']],
			'empty register'     => [['register' => '']],
		];
	}//end invalidIds()

	/**
	 * A numeric string with a fraction is truncated to its integer part.
	 *
	 * @return void
	 */
	public function testNumericFractionIsTruncated(): void {
		$this->assertSame(3, $this->resolver()->resolve(['register_id' => '3.9']));
	}//end testNumericFractionIsTruncated()
}//end class
