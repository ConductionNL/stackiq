<?php
declare(strict_types=1);
namespace OCA\Stackiq\Tests\Unit\Probe;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/** @coversNothing */
class CrossOrgProbeTest extends TestCase {
	private function build(ObjectServiceInterface $os, string $activeOrg): AangebodenGebruikService {
		$am = $this->createMock(IAppManager::class);
		$am->method('getInstalledApps')->willReturn(['openregister']);
		$org = new class($activeOrg) { public function __construct(private string $u) {} public function getUuid() { return $this->u; } };
		$orgSvc = new class($org) { public function __construct(private $o) {} public function getActiveOrganisation() { return $this->o; } };
		$c = $this->createMock(ContainerInterface::class);
		$c->method('get')->willReturnCallback(fn ($id) => str_contains($id, 'OrganisationService') ? $orgSvc : $os);
		$s = $this->createMock(SettingsService::class);
		$s->method('getVoorzieningenConfig')->willReturn(['register' => '1', 'gebruik_schema' => '2', 'koppeling_schema' => '3', 'organisatie_schema' => '4']);
		$u = $this->createMock(IUser::class); $u->method('getUID')->willReturn('alice');
		$us = $this->createMock(IUserSession::class); $us->method('getUser')->willReturn($u);
		return new AangebodenGebruikService($this->createMock(IAppConfig::class), $am, $c, new NullLogger(), $s, $us);
	}
	private function entity(array $data): ObjectEntityInterface {
		$e = $this->createMock(ObjectEntityInterface::class);
		$e->method('getObject')->willReturn($data);
		$e->method('getRegister')->willReturn('1');
		$e->method('getSchema')->willReturn('2');
		return $e;
	}
	public function testSetSelfOtherOrgsRecordDenied(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('find')->willReturn($this->entity(['consumer' => 'org-B', 'provider' => ['id' => 'org-C']]));
		$os->expects($this->never())->method('saveObject');
		$r = $this->build($os, 'org-A')->setGebruikSelfToActiveOrg('g-1');
		$this->assertFalse($r['success']);
		$this->assertStringContainsString('Operation not allowed', $r['error']);
	}
	public function testDenyOtherOrgsRecordDenied(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('find')->willReturn($this->entity(['consumer' => 'org-B', 'provider' => 'org-C']));
		$os->expects($this->never())->method('deleteObject');
		$r = $this->build($os, 'org-A')->deleteGebruikAsAfnemer('g-1');
		$this->assertFalse($r['success']);
		$this->assertStringContainsString('Operation not allowed', $r['error']);
	}
	public function testKoppelingenOtherOrgsUuidReturnsNothing(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('find')->willReturn($this->entity(['@self' => ['organisation' => 'org-B', 'schema' => '4']]));
		$os->expects($this->never())->method('searchObjectsPaginated');
		$r = $this->build($os, 'org-A')->getKoppelingenGebruikByUuid('org-B', [], false);
		$this->assertSame(0, $r['total']);
	}
	public function testKoppelingenOwnUuidSearches(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('find')->willReturn($this->entity(['@self' => ['organisation' => 'org-A', 'schema' => '4']]));
		$os->method('buildSearchQuery')->willReturn([]);
		$os->expects($this->once())->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 0]);
		$this->build($os, 'org-A')->getKoppelingenGebruikByUuid('org-A', [], false);
	}
}
