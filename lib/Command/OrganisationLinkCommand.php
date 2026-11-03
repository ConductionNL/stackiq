<?php

/**
 * Stackiq Organisation Link Command
 *
 * Links an organisatie to an existing OpenRegister organisation, as an
 * administrator decides.
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

use OCA\Stackiq\Service\OrganizationSyncService;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

/**
 * `occ stackiq:organisation:link <organisatie-uuid> <organisation-uuid>`.
 *
 * The organisation sync never takes over an OpenRegister organisation whose
 * name gives the same slug: it leaves the organisatie unlinked and logs it.
 * This command is how an administrator links the two when they are the same.
 * The organisation takes the organisatie's uuid, so the command first says what
 * keeps the old uuid and asks for confirmation (or --yes).
 *
 * @spec openspec/specs/organisatie-service/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OrganisationLinkCommand extends Command {

	/**
	 * What each outcome means, for the console.
	 */
	private const MESSAGES = [
		'linked'              => '<info>Linked.</info> The organisation now carries the organisatie uuid and owns the organisatie.',
		'no-organisatie'      => '<error>No organisatie has this uuid.</error>',
		'already-linked'      => '<error>This organisatie already has an OpenRegister organisation.</error>',
		'no-organisation'     => '<error>No OpenRegister organisation has this uuid.</error>',
		'organisation-in-use' => '<error>This OpenRegister organisation belongs to another organisatie.</error>',
		'default-organisation' => '<error>This is the OpenRegister default organisation, which is never linked.</error>',
		'not-confirmed'       => '<comment>Not linked.</comment> Confirm, or run again with --yes.',
	];

	/**
	 * What still holds the organisation's old uuid after a link.
	 */
	private const STORED_REFERENCES = [
		'objects in OpenRegister stamped with this organisation (their @self.organisation)',
		'the active organisation of its members',
		'the parent of its child organisations',
		'references in other apps',
	];

	/**
	 * Build the command.
	 *
	 * @param ContainerInterface $container Container, for the lazy sync service resolve.
	 */
	public function __construct(private readonly ContainerInterface $container) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	protected function configure(): void {
		$this->setName('stackiq:organisation:link')
			->setDescription('Link an organisatie to an existing OpenRegister organisation.')
			->addArgument('organisatie', InputArgument::REQUIRED, 'The organisatie object uuid.')
			->addArgument('organisation', InputArgument::REQUIRED, 'The OpenRegister organisation uuid.')
			->addOption('yes', 'y', InputOption::VALUE_NONE, 'Link without asking for confirmation.');
	}//end configure()

	/**
	 * Make the link, or say why not.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when linked, 1 otherwise.
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$organisatie  = (string) $input->getArgument('organisatie');
		$organisation = (string) $input->getArgument('organisation');
		try {
			$sync    = $this->container->get(OrganizationSyncService::class);
			$outcome = $sync->checkOrganisationLink(organisatieUuid: $organisatie, entityUuid: $organisation);
			if ($outcome === null && $this->confirmed(input: $input, output: $output) === false) {
				$outcome = 'not-confirmed';
			}

			if ($outcome === null) {
				$outcome = $sync->linkOrganisationEntity(organisatieUuid: $organisatie, entityUuid: $organisation);
			}
		} catch (Throwable $e) {
			$output->writeln(messages: '<error>Could not link: ' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln(messages: self::MESSAGES[$outcome] ?? $outcome);
		if ($outcome === 'linked') {
			return 0;
		}

		return 1;
	}//end execute()

	/**
	 * Say what keeps the organisation's old uuid, and ask whether to link anyway.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return bool True when --yes is given or the administrator confirms.
	 */
	private function confirmed(InputInterface $input, OutputInterface $output): bool {
		$organisation = (string) $input->getArgument('organisation');
		$organisatie  = (string) $input->getArgument('organisatie');
		$output->writeln(messages: '<comment>The OpenRegister organisation ' . $organisation . ' will take the uuid ' . $organisatie . '.</comment>');
		$output->writeln(messages: 'These keep the old uuid and are not moved:');
		foreach (self::STORED_REFERENCES as $reference) {
			$output->writeln(messages: '  - ' . $reference);
		}

		if ($input->getOption('yes') === true) {
			return true;
		}

		return (new QuestionHelper())->ask($input, $output, new ConfirmationQuestion('Link anyway? [y/N] ', false)) === true;
	}//end confirmed()
}//end class
