<?php

/**
 * Stackiq GEMMA Standard Links Command
 *
 * Back-fills the GEMMA standard links on reference components and standard
 * versions that an earlier ArchiMate import saved without them.
 *
 * @category Command
 * @package  OCA\Stackiq\Command
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

namespace OCA\Stackiq\Command;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\Stackiq\Service\ArchiMate\GemmaStandardLinker;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ stackiq:gemma:link-standards [--dry-run]`.
 *
 * Reads the imported GEMMA elements and relationships, derives the links the
 * import writes (see GemmaStandardLinker), and patches only the link properties
 * that differ from what is stored. A second run finds nothing to write. It reads
 * and writes without RBAC and organisation scoping, as the import does.
 *
 * @spec openspec/specs/archimate-import/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class GemmaStandardLinksCommand extends Command {

	/**
	 * The link properties the linker derives.
	 */
	private const LINK_PROPERTIES = ['recommendedStandards', 'mandatoryStandards', 'standards', 'linkedStandardVersions', 'standard'];

	/**
	 * Build the command.
	 *
	 * @param ContainerInterface $container Container, for the lazy ObjectService resolve.
	 * @param LoggerInterface    $logger    The logger the linker reports to.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	protected function configure(): void {
		$this->setName('stackiq:gemma:link-standards')
			->setDescription('Back-fill the GEMMA standard links on imported reference components and standard versions.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written, write nothing.')
			->addOption('register', null, InputOption::VALUE_REQUIRED, 'The GEMMA register slug.', 'vng-gemma')
			->addOption('element-schema', null, InputOption::VALUE_REQUIRED, 'The element schema slug.', 'element')
			->addOption('relation-schema', null, InputOption::VALUE_REQUIRED, 'The relation schema slug.', 'relation');
	}//end configure()

	/**
	 * Run the back-fill.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every needed write landed (or none was needed), 1 otherwise.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objects       = $this->read(objectService: $objectService, input: $input);
		} catch (Throwable $e) {
			$output->writeln('<error>could not read the GEMMA objects: '.$e->getMessage().'</error>');
			return 1;
		}

		$dryRun  = $input->getOption('dry-run') === true;
		$changes = $this->changes(stored: $objects);
		$counts  = ['checked' => count($objects), 'needed' => count($changes), 'written' => 0, 'failed' => 0];
		foreach ($changes as $id => $data) {
			if ($dryRun === true) {
				continue;
			}

			$outcome = 'failed';
			if ($this->write(objectService: $objectService, change: ['id' => $id, 'data' => $data, 'input' => $input]) === true) {
				$outcome = 'written';
			}

			$counts[$outcome]++;
		}

		foreach ($changes as $id => $data) {
			$output->writeln('  '.$id.': '.implode(', ', array_keys($data)), OutputInterface::VERBOSITY_VERBOSE);
		}

		$summary = sprintf(
			'%d GEMMA objects read, %d need links, %d written, %d failed',
			$counts['checked'],
			$counts['needed'],
			$counts['written'],
			$counts['failed']
		);
		if ($dryRun === true) {
			$summary .= ' (dry run)';
		}

		$output->writeln($summary);

		if ($counts['failed'] > 0) {
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * Read the elements and relationships in the linker's import shape.
	 *
	 * @param object         $objectService The OpenRegister ObjectService.
	 * @param InputInterface $input         Console input, for the register and schema slugs.
	 *
	 * @return array<int, array<string, mixed>> The elements, then the relationships.
	 */
	private function read(object $objectService, InputInterface $input): array {
		$pages    = new ObjectPages(objectService: $objectService);
		$register = (string) $input->getOption('register');
		$sections = [
			'element'      => (string) $input->getOption('element-schema'),
			'relationship' => (string) $input->getOption('relation-schema'),
		];

		$objects = [];
		foreach ($sections as $section => $schema) {
			foreach ($pages->allAsSystem(register: $register, schema: $schema) as $payload) {
				$payload['@self']['id'] ??= $payload['id'] ?? null;
				$payload['section'] = $section;
				$objects[] = $payload;
			}
		}

		return $objects;
	}//end read()

	/**
	 * The link properties to write, per object id: only those that differ from what is stored.
	 *
	 * @param array<int, array<string, mixed>> $stored The objects as stored.
	 *
	 * @return array<string, array<string, mixed>> The changed link properties by object id.
	 */
	private function changes(array $stored): array {
		$linked  = (new GemmaStandardLinker(logger: $this->logger))->link(objects: $stored);
		$changes = [];
		foreach ($linked as $position => $object) {
			foreach (self::LINK_PROPERTIES as $property) {
				if (array_key_exists($property, $object) === true
					&& $this->same(stored: $stored[$position][$property] ?? null, derived: $object[$property]) === false
				) {
					$changes[(string) $object['@self']['id']][$property] = $object[$property];
				}
			}
		}

		return $changes;
	}//end changes()

	/**
	 * Whether a stored link value already holds the derived one, in any order.
	 *
	 * @param mixed $stored  The stored value.
	 * @param mixed $derived The derived value.
	 *
	 * @return bool True when nothing needs writing.
	 */
	private function same(mixed $stored, mixed $derived): bool {
		if (is_array($stored) === true && is_array($derived) === true) {
			$stored  = array_values($stored);
			$derived = array_values($derived);
			sort($stored);
			sort($derived);
		}

		return $stored === $derived;
	}//end same()

	/**
	 * Patch one object's link properties.
	 *
	 * @param object                                                         $objectService The OpenRegister ObjectService.
	 * @param array{id: string, data: array<string, mixed>, input: InputInterface} $change The object id, the link properties and the input.
	 *
	 * @return bool True when the write landed.
	 */
	private function write(object $objectService, array $change): bool {
		try {
			// Patch, so the properties the read did not return keep their values.
			$objectService->patchObject(
				patch: new ObjectPatch(
					objectId: $change['id'],
					data: $change['data'],
					target: new ObjectFindTarget(
						register: (string) $change['input']->getOption('register'),
						schema: (string) $change['input']->getOption('element-schema')
					)
				),
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('GEMMA standard link write failed', ['id' => $change['id'], 'error' => $e->getMessage()]);
			return false;
		}
	}//end write()
}//end class
