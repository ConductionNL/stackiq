<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\OrganisationReportAccess;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The organisation check the portfolio, data quality and coverage reports share.
 */
final class OrganisationReportAccessTest extends TestCase {
	/**
	 * @return array<string,array{0: array<int,string>, 1: string, 2: bool}>
	 */
	public static function cases(): array {
		return [
			'admin reads any organisation' => [['admin'], '', true],
			'civil servant reads any organisation' => [['ambtenaar'], '', true],
			'own organisation' => [['gebruik-beheerder'], 'zuiddrecht', true],
			'another organisation' => [['gebruik-beheerder'], 'alblasserdam', false],
			'no organisation set' => [[], '', false],
		];
	}//end cases()

	/**
	 * @param array<int,string> $groups The user's groups.
	 *
	 * @dataProvider cases
	 */
	public function testMayRead(array $groups, string $ownOrganisation, bool $expected): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn(array_map(function (string $gid): IGroup {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			return $group;
		}, $groups));
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($ownOrganisation);

		self::assertSame($expected, (new OrganisationReportAccess($groupManager, $config))->mayRead($user, 'zuiddrecht'));
	}//end testMayRead()
}//end class
