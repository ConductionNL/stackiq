<?php

/**
 * Unit tests for OrganizationSettingsHandler.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Group settings accessor; defaults and validation are pinned here.
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use InvalidArgumentException;
use OCA\Stackiq\Service\Settings\OrganizationSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The three access group lists: their defaults, their stored form and the names they refuse.
 *
 * @covers \OCA\Stackiq\Service\Settings\OrganizationSettingsHandler
 *
 * @spec exclude Group settings accessor; defaults and validation are pinned here.
 */
class OrganizationSettingsHandlerTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * Build the app config double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(originalClassName: IAppConfig::class);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return OrganizationSettingsHandler
	 */
	private function handler(): OrganizationSettingsHandler {
		return new OrganizationSettingsHandler(config: $this->config, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end handler()

	/**
	 * Store one raw value for every key.
	 *
	 * @param string $raw The stored string.
	 *
	 * @return void
	 */
	private function stored(string $raw): void {
		$this->config->method('getValueString')->willReturn($raw);
	}//end stored()

	/**
	 * Unset lists fall back to their defaults; admin groups have none.
	 *
	 * @return void
	 */
	public function testUnsetListsUseTheirDefaults(): void {
		$this->stored(raw: '');
		$handler = $this->handler();

		$this->assertSame(expected: ['software-catalog-users'], actual: $handler->getGenericUserGroups());
		$this->assertSame(expected: [], actual: $handler->getOrganizationAdminGroups());
		$this->assertSame(expected: ['admin', 'software-catalog-admins'], actual: $handler->getSuperUserGroups());
	}//end testUnsetListsUseTheirDefaults()

	/**
	 * A stored list is returned as stored.
	 *
	 * @return void
	 */
	public function testStoredListsAreReturned(): void {
		$this->stored(raw: '["beheer","it.team"]');
		$handler = $this->handler();

		$this->assertSame(expected: ['beheer', 'it.team'], actual: $handler->getGenericUserGroups());
		$this->assertSame(expected: ['beheer', 'it.team'], actual: $handler->getOrganizationAdminGroups());
		$this->assertSame(expected: ['beheer', 'it.team'], actual: $handler->getSuperUserGroups());
	}//end testStoredListsAreReturned()

	/**
	 * A stored value that is not a JSON list grants no groups, not the defaults.
	 *
	 * @return void
	 */
	public function testUnreadableListsGrantNoGroups(): void {
		$this->stored(raw: '{broken');
		$handler = $this->handler();

		$this->assertSame(expected: [], actual: $handler->getGenericUserGroups());
		$this->assertSame(expected: [], actual: $handler->getOrganizationAdminGroups());
		$this->assertSame(expected: [], actual: $handler->getSuperUserGroups());
	}//end testUnreadableListsGrantNoGroups()

	/**
	 * Valid names are stored as JSON under each list's own key.
	 *
	 * @return void
	 */
	public function testValidListsAreStoredUnderTheirKeys(): void {
		$written = [];
		$this->config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = [$app, $value];
				return true;
			}
		);
		$handler = $this->handler();

		$handler->setGenericUserGroups(['users']);
		$handler->setOrganizationAdminGroups(['org-admins', 'org_2']);
		$handler->setSuperUserGroups(['admin']);

		$this->assertSame(
			expected: [
				'generic_user_groups' => ['stackiq', '["users"]'],
				'organization_admin_groups' => ['stackiq', '["org-admins","org_2"]'],
				'super_user_groups' => ['stackiq', '["admin"]'],
			],
			actual: $written
		);
	}//end testValidListsAreStoredUnderTheirKeys()

	/**
	 * Group names the handler refuses, and the message.
	 *
	 * @return array<string, array{0: array<int,string>, 1: string}>
	 */
	public static function invalidGroups(): array {
		return [
			'empty name' => [['ok', ''], 'Group names must be non-empty strings.'],
			'space' => [['bad name'], 'Group name "bad name" contains invalid characters.'],
			'slash' => [['a/b'], 'Group name "a/b" contains invalid characters.'],
			'quote' => [['x"y'], 'Group name "x"y" contains invalid characters.'],
		];
	}//end invalidGroups()

	/**
	 * An invalid name blocks the write for every list.
	 *
	 * @param array<int,string> $groups  The groups.
	 * @param string            $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('invalidGroups')]
	public function testInvalidNamesAreRefusedBeforeWriting(array $groups, string $message): void {
		$this->config->expects($this->never())->method('setValueString');
		$handler = $this->handler();

		foreach (['setGenericUserGroups', 'setOrganizationAdminGroups', 'setSuperUserGroups'] as $setter) {
			try {
				$handler->$setter($groups);
				$this->fail(message: $setter . ' accepted ' . json_encode($groups));
			} catch (InvalidArgumentException $e) {
				$this->assertSame(expected: $message, actual: $e->getMessage());
			}
		}
	}//end testInvalidNamesAreRefusedBeforeWriting()
}//end class
