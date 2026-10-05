<?php

/**
 * Module Version Publication Job.
 *
 * Copies a module's publication onto its versions, queued by
 * ModuleVersionPublicationService when a module's publication changes or the
 * module is deleted, so the one save per version runs off the request that
 * saved the module. The module is read when the job runs, so the versions
 * follow the module as it is then, not as it was when the job was queued.
 *
 * @category  BackgroundJob
 * @package   OCA\Stackiq\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */

declare(strict_types=1);

namespace OCA\Stackiq\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * One copy of one module's publication onto its versions.
 *
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */
class ModuleVersionPublicationJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                    $time            The time factory.
	 * @param ModuleVersionPublicationService $publication     The mirror.
	 * @param SettingsService                 $settingsService Resolves the module register and schema.
	 * @param ContainerInterface              $container       Resolves OpenRegister's object service.
	 * @param LoggerInterface                 $logger          The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ModuleVersionPublicationService $publication,
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Copy the publication of the module in the argument onto its versions.
	 *
	 * A deleted module, or one that no longer exists, takes its versions out
	 * of public view. A module that cannot be read leaves its versions as they are.
	 *
	 * @param mixed $argument `{module, deleted}`: the module's id and whether it was deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	protected function run($argument): void {
		$moduleUuid = '';
		if (is_array($argument) === true && is_string($argument['module'] ?? null) === true) {
			$moduleUuid = $argument['module'];
		}

		if ($moduleUuid === '') {
			return;
		}

		if (($argument['deleted'] ?? false) === true) {
			$this->publication->clearVersions(moduleUuid: $moduleUuid);
			return;
		}

		try {
			$module = $this->findModule(moduleUuid: $moduleUuid);
		} catch (Throwable $e) {
			$this->logger->error(
				'ModuleVersionPublicationJob: could not read the module; its versions are left as they are',
				['module' => $moduleUuid, 'error' => $e->getMessage()]
			);
			return;
		}

		if ($module === null) {
			$this->publication->clearVersions(moduleUuid: $moduleUuid);
			return;
		}

		$this->publication->backfillModule(module: $module);
	}//end run()

	/**
	 * Read the module as it is now.
	 *
	 * @param string $moduleUuid The module.
	 *
	 * @return ObjectEntityInterface|null The module, or null when it no longer exists.
	 *
	 * @throws Throwable When OpenRegister is absent or the read fails.
	 */
	private function findModule(string $moduleUuid): ?ObjectEntityInterface {
		$objects = $this->container->get(ObjectServiceInterface::class);
		if (($objects instanceof ObjectServiceInterface) === false) {
			throw new RuntimeException('OpenRegister\'s object service is not available');
		}

		return $objects->find(
			id: $moduleUuid,
			register: $this->settingsService->getRegisterIdForObjectType('module'),
			schema: $this->settingsService->getSchemaIdForObjectType('module'),
			_rbac: false,
			_multitenancy: false
		);
	}//end findModule()
}//end class
