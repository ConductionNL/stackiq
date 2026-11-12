<?php

/**
 * Golden test for SbomController's import and status endpoints.
 *
 * Pins the refused outcomes (anonymous 401, no manage role 403, no ACL on
 * the parent module 403 / 404 for the status read, unknown moduleVersie 404)
 * and the allowed ones, every upload check (missing, oversized, unreadable,
 * invalid JSON, unknown format), and the exception mapping of the import:
 * status, body, the service calls and every log line.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SbomController;
use OCA\Stackiq\Exception\UnsupportedSbomFormatException;
use OCA\Stackiq\Service\SbomImportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Records every log call as [level, message, context].
 */
class SbomGoldenLogSpy extends AbstractLogger {
	/**
	 * Recorded log calls.
	 *
	 * @var array
	 */
	public array $records = [];

	/**
	 * Record one log call.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array              $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		$this->records[] = [(string)$level, (string)$message, $context];
	}
}

/**
 * Golden SBOM endpoints.
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
 */
class SbomControllerGoldenTest extends TestCase {
	/**
	 * Temporary upload files.
	 *
	 * @var string[]
	 */
	private array $tempFiles = [];

	/**
	 * Remove the temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		unset($_FILES['sbomFile']);
	}

	/**
	 * A temporary upload with the given contents.
	 *
	 * @param string $contents The file contents.
	 * @param array  $override Keys to override.
	 *
	 * @return array
	 */
	private function upload(string $contents, array $override=[]): array {
		$path = tempnam(sys_get_temp_dir(), 'sbom-golden-');
		file_put_contents($path, $contents);
		$this->tempFiles[] = $path;
		return $override + ['tmp_name' => $path, 'name' => 'bom.json', 'size' => strlen($contents)];
	}

	/**
	 * Call one endpoint in one scenario and record everything.
	 *
	 * @param string $endpoint 'import' or 'status'.
	 * @param array  $scenario The scenario.
	 *
	 * @return array
	 */
	private function call(string $endpoint, array $scenario): array {
		$calls   = new \ArrayObject();
		$record  = function (string $name, $result) use ($calls) {
			return function (...$args) use ($calls, $name, $result) {
				$calls[] = [$name, $args];
				if ($result instanceof \Throwable) {
					throw $result;
				}

				return $result;
			};
		};
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn($scenario['upload'] ?? null);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default=null) => ($scenario['params'][$key] ?? $default));
		if (isset($scenario['files']) === true) {
			$_FILES['sbomFile'] = $scenario['files'];
		}

		$session = $this->createMock(IUserSession::class);
		$groups  = $this->createMock(IGroupManager::class);
		$user    = null;
		$uid = array_key_exists('user', $scenario) === true ? $scenario['user'] : 'alice';
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups->method('isAdmin')->willReturnCallback($record('isAdmin', ($scenario['admin'] ?? false)));
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $group) use ($calls, $scenario) {
				$calls[] = ['isInGroup', [$uid, $group]];
				return in_array($group, ($scenario['groups'] ?? []), true);
			}
		);
		$service = $this->createMock(SbomImportService::class);
		$service->method('resolveParentModuleUuid')->willReturnCallback($record('resolveParentModuleUuid', (array_key_exists('module', $scenario) === true ? $scenario['module'] : 'module-1')));
		$service->method('userCanReadModule')->willReturnCallback($record('userCanReadModule', ($scenario['canRead'] ?? true)));
		$service->method('importForModuleVersie')->willReturnCallback($record('importForModuleVersie', ($scenario['import'] ?? ['imported' => 3])));
		$service->method('getStatus')->willReturnCallback($record('getStatus', ($scenario['status'] ?? ['sbomFormat' => 'cyclonedx-json'])));
		$log        = new SbomGoldenLogSpy();
		$controller = new SbomController($request, $session, $groups, $service, $log);

		$response = $endpoint === 'import' ? $controller->importSbom('mv-1') : $controller->getSbomImportStatus('mv-1');
		$calls    = array_map(
			function (array $entry): array {
				if ($entry[0] === 'importForModuleVersie') {
					$entry[1] = array_map(fn ($value) => is_string($value) === true ? md5($value) : $value, $entry[1]);
				}

				return $entry;
			},
			$calls->getArrayCopy()
		);
		unset($_FILES['sbomFile']);
		return [$response->getStatus(), $response->getData(), $calls, $log->records];
	}

	/**
	 * Every scenario of both endpoints.
	 *
	 * @return void
	 */
	public function testEndpoints(): void {
		$valid = '{"bomFormat":"CycloneDX","components":[]}';
		$cases = [
			'import, anonymous' => ['import', ['user' => null, 'upload' => $this->upload($valid)]],
			'import, admin' => ['import', ['admin' => true, 'upload' => $this->upload($valid)]],
			'import, no manage group' => ['import', ['upload' => $this->upload($valid)]],
			'import, second manage group' => ['import', ['groups' => ['aanbod-beheerder'], 'upload' => $this->upload($valid)]],
			'import, third manage group' => ['import', ['groups' => ['functioneel-beheerder'], 'upload' => $this->upload($valid), 'params' => ['format' => 'spdx-json']]],
			'import, manage group, no module' => ['import', ['groups' => ['software-catalog-admins'], 'module' => null, 'upload' => $this->upload($valid)]],
			'import, manage group, no module ACL' => ['import', ['groups' => ['software-catalog-admins'], 'canRead' => false, 'upload' => $this->upload($valid)]],
			'import, module lookup throws' => ['import', ['groups' => ['software-catalog-admins'], 'module' => new DoesNotExistException('gone'), 'upload' => $this->upload($valid)]],
			'import, no file' => ['import', ['admin' => true]],
			'import, file via $_FILES' => ['import', ['admin' => true, 'files' => $this->upload($valid, ['name' => 'files.json'])]],
			'import, upload without size' => ['import', ['admin' => true, 'upload' => array_diff_key($this->upload($valid), ['size' => 0])]],
			'import, upload without name' => ['import', ['admin' => true, 'upload' => array_diff_key($this->upload($valid), ['name' => 0])]],
			'import, too large' => ['import', ['admin' => true, 'upload' => $this->upload($valid, ['size' => 10485761])]],
			'import, exactly the limit' => ['import', ['admin' => true, 'upload' => $this->upload($valid, ['size' => 10485760])]],
			'import, unreadable' => ['import', ['admin' => true, 'upload' => ['tmp_name' => '/nonexistent/sbom.json', 'name' => 'x.json', 'size' => 10]]],
			'import, empty tmp name' => ['import', ['admin' => true, 'upload' => ['tmp_name' => '', 'name' => 'x.json']]],
			'import, invalid JSON' => ['import', ['admin' => true, 'upload' => $this->upload('{not json')]],
			'import, unknown format' => ['import', ['admin' => true, 'upload' => $this->upload($valid), 'params' => ['format' => 'xml']]],
			'import, unsupported format' => ['import', ['admin' => true, 'upload' => $this->upload($valid), 'import' => new UnsupportedSbomFormatException('nope')]],
			'import, version not found' => ['import', ['admin' => true, 'upload' => $this->upload($valid), 'import' => new \RuntimeException('moduleVersie not found')]],
			'import, does not exist' => ['import', ['admin' => true, 'upload' => $this->upload($valid), 'import' => new DoesNotExistException('gone')]],
			'import, other failure' => ['import', ['admin' => true, 'upload' => $this->upload($valid), 'import' => new \Exception('disk full')]],
			'status, anonymous' => ['status', ['user' => null]],
			'status, admin' => ['status', ['admin' => true, 'params' => ['operationId' => 'op-1']]],
			'status, admin, non-string operation' => ['status', ['admin' => true, 'params' => ['operationId' => ['x']]]],
			'status, reader' => ['status', []],
			'status, no module' => ['status', ['module' => null]],
			'status, no read ACL' => ['status', ['canRead' => false]],
			'status, module lookup throws' => ['status', ['module' => new DoesNotExistException('gone')]],
			'status, status throws' => ['status', ['admin' => true, 'status' => new DoesNotExistException('gone')]],
		];
		$out   = [];
		foreach ($cases as $name => [$endpoint, $scenario]) {
			$out[$name] = $this->call(endpoint: $endpoint, scenario: $scenario);
		}

		$path = dirname(__DIR__, 2) . '/fixtures/sbom/controller-endpoints.json';
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
	}
}
