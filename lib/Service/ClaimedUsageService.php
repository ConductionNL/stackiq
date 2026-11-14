<?php

/**
 * Stackiq ClaimedUsageService
 *
 * The usages other organisations claim of a supplier's products, and the
 * supplier's answer to each: accepted or declined. The usage stays the
 * claiming organisation's record either way; only the answer, its date and
 * the answering user are written on it.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lists and answers the usages claimed of the active organisation's products.
 *
 * A claimed usage has the active organisation as `provider`, is owned
 * (`@self.organisation`) by another organisation, and has no
 * `providerResponse` yet. Only a member of the provider answers, and that
 * check runs before anything is written.
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
class ClaimedUsageService {

	/**
	 * The answers a supplier can give.
	 *
	 * @var array<int, string>
	 */
	public const ANSWERS = ['accepted', 'declined'];

	/**
	 * The relations a listed claim is shown with.
	 *
	 * @var array<int, string>
	 */
	private const EXTEND = ['consumer', 'module', 'moduleVersion'];

	/**
	 * Constructor.
	 *
	 * @param IAppManager                   $appManager App manager, to find OpenRegister.
	 * @param ContainerInterface            $container  Container, for OpenRegister's services.
	 * @param IUserSession                  $userSession The session.
	 * @param LoggerInterface               $logger      The logger.
	 * @param RegisterConfigReaderInterface $settings    The register and the usage schema.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly RegisterConfigReaderInterface $settings,
	) {
	}//end __construct()

	/**
	 * The unanswered usages other organisations claim of the active organisation's products.
	 *
	 * @return array{status: string, organisation: string|null, results: array<int, array<string, mixed>>}
	 *   status `ok`, or `no_organisation` / `unavailable` with no results; `organisation`
	 *   is the active organisation the claims are of, so a page shows them only on its own page.
	 *
	 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
	 */
	public function claimed(): array {
		$supplier = $this->lookup()->activeOrganisationUuid();
		if ($supplier === null) {
			return ['status' => 'no_organisation', 'organisation' => null, 'results' => []];
		}

		$location = $this->usageLocation();
		if ($location === null) {
			return ['status' => 'unavailable', 'organisation' => $supplier, 'results' => []];
		}

		try {
			$found = $this->lookup()->objectService()->searchObjectsPaginated(
				query: [
					'@self'    => $location,
					'provider' => $supplier,
					'_extend'  => self::EXTEND,
					'_limit'   => 500,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Exception $e) {
			$this->logger->warning('Stackiq: claimed usages could not be read', ['exception' => $e->getMessage()]);
			return ['status' => 'unavailable', 'organisation' => $supplier, 'results' => []];
		}

		$claims = [];
		foreach ((array)($found['results'] ?? []) as $row) {
			$claim = $this->claim(row: $row, supplier: $supplier);
			if ($claim !== null) {
				$claims[] = $claim;
			}
		}

		return ['status' => 'ok', 'organisation' => $supplier, 'results' => $claims];
	}//end claimed()

	/**
	 * Record the active organisation's answer on a usage claimed of its product.
	 *
	 * @param string $uuid   The usage.
	 * @param string $answer `accepted` or `declined`.
	 *
	 * @return array{status: string, usage?: array<string, mixed>}
	 *   status `ok`; `invalid` for another answer; `no_organisation`; `not_found`;
	 *   `forbidden` when the active organisation is not the usage's provider,
	 *   or the usage is its own; `unavailable` when OpenRegister fails.
	 *
	 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
	 */
	public function answer(string $uuid, string $answer): array {
		if (in_array($answer, self::ANSWERS, true) === false || trim($uuid) === '') {
			return ['status' => 'invalid'];
		}

		$supplier = $this->lookup()->activeOrganisationUuid();
		$location = $this->usageLocation();
		if ($supplier === null) {
			return ['status' => 'no_organisation'];
		}

		if ($location === null) {
			return ['status' => 'unavailable'];
		}

		try {
			$objectService = $this->lookup()->objectService();
		} catch (Exception $e) {
			return ['status' => 'unavailable'];
		}

		$usage = $this->findUsage(objectService: $objectService, uuid: $uuid, location: $location);
		if ($usage === null) {
			return ['status' => 'not_found'];
		}

		// The access check, before any write: only the provider answers, and never on its own record.
		if ($this->isClaimOf(usage: $usage, supplier: $supplier) === false) {
			$this->logger->warning(
				'Stackiq: refused an answer on a usage by an organisation that is not its provider',
				['usage' => $uuid, 'organisation' => $supplier]
			);
			return ['status' => 'forbidden'];
		}

		return $this->write(objectService: $objectService, usage: $usage, answer: $answer);
	}//end answer()

	/**
	 * The usage, read as the system, or null when it is not found.
	 *
	 * @param ObjectServiceInterface             $objectService OpenRegister's object service.
	 * @param string                             $uuid          The usage.
	 * @param array{register: mixed, schema: mixed} $location   The register and schema of the usages.
	 *
	 * @return ObjectEntityInterface|null
	 */
	private function findUsage(ObjectServiceInterface $objectService, string $uuid, array $location): ?ObjectEntityInterface {
		try {
			return $objectService->find(
				id: $uuid,
				register: $location['register'],
				schema: $location['schema'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Exception $e) {
			return null;
		}
	}//end findUsage()

	/**
	 * Save the answer on the usage, keeping its owner and organisation.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ObjectEntityInterface  $usage         The usage.
	 * @param string                 $answer        The answer.
	 *
	 * @return array{status: string, usage?: array<string, mixed>}
	 */
	private function write(ObjectServiceInterface $objectService, ObjectEntityInterface $usage, string $answer): array {
		$data = $usage->getObject();
		$data['providerResponse']   = $answer;
		$data['providerResponseAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$data['providerResponseBy'] = (string)($this->userSession->getUser()?->getUID() ?? '');
		$data['@self'] = array_filter(
			['organisation' => $usage->getOrganisation(), 'owner' => $usage->getOwner()],
			static fn (?string $value): bool => $value !== null
		);

		try {
			$saved = $objectService->saveObject(
				object: $data,
				register: $usage->getRegister(),
				schema: $usage->getSchema(),
				uuid: $usage->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Exception $e) {
			$this->logger->error('Stackiq: the answer on a claimed usage was not saved', ['usage' => $usage->getUuid(), 'exception' => $e->getMessage()]);
			return ['status' => 'unavailable'];
		}

		return ['status' => 'ok', 'usage' => $saved->getObject()];
	}//end write()

	/**
	 * Whether a usage is a claim of the supplier's product: the supplier is its
	 * provider and another organisation owns it.
	 *
	 * @param ObjectEntityInterface $usage    The usage.
	 * @param string                $supplier The active organisation.
	 *
	 * @return bool
	 */
	private function isClaimOf(ObjectEntityInterface $usage, string $supplier): bool {
		$provider = $this->partyId(party: ($usage->getObject()['provider'] ?? null));
		return $provider === $supplier && $usage->getOrganisation() !== $supplier;
	}//end isClaimOf()

	/**
	 * A found row as a listed claim, or null when it is not an unanswered claim of the supplier.
	 *
	 * @param mixed  $row      The row: an entity or a serialised array.
	 * @param string $supplier The active organisation.
	 *
	 * @return array<string, mixed>|null
	 */
	private function claim(mixed $row, string $supplier): ?array {
		[$data, $self] = $this->rowParts(row: $row);
		$owner         = ($self['organisation'] ?? null);
		$answered      = in_array(($data['providerResponse'] ?? null), self::ANSWERS, true);
		if ($this->partyId(party: ($data['provider'] ?? null)) !== $supplier || $owner === null || $owner === $supplier || $answered === true) {
			return null;
		}

		return [
			'id'            => (string)($self['id'] ?? $data['id'] ?? ''),
			'organisation'  => $this->label(value: ($data['consumer'] ?? null)),
			'module'        => $this->label(value: ($data['module'] ?? null)),
			'moduleVersion' => $this->label(value: ($data['moduleVersion'] ?? null)),
			'claimedAt'     => (string)($self['created'] ?? ''),
		];
	}//end claim()

	/**
	 * The data and @self of a found row.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function rowParts(mixed $row): array {
		if ($row instanceof ObjectEntityInterface) {
			$data = $row->getObject();
			return [$data, ['id' => $row->getUuid(), 'organisation' => $row->getOrganisation(), 'created' => ($data['@self']['created'] ?? null)]];
		}

		if (is_array($row) === true) {
			return [$row, (array)($row['@self'] ?? [])];
		}

		return [[], []];
	}//end rowParts()

	/**
	 * A relation as shown: its name or title when expanded, else its id.
	 *
	 * @param mixed $value The relation.
	 *
	 * @return string
	 */
	private function label(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value['name'] ?? $value['title'] ?? $value['id'] ?? '');
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}//end label()

	/**
	 * A party reference as an id: a uuid string, or an expanded object's id.
	 *
	 * @param mixed $party The reference.
	 *
	 * @return string|null
	 */
	private function partyId(mixed $party): ?string {
		if (is_array($party) === true && isset($party['id']) === true) {
			return (string)$party['id'];
		}

		if (is_string($party) === true && $party !== '') {
			return $party;
		}

		return null;
	}//end partyId()

	/**
	 * The register and schema of the usages, or null when they are not configured.
	 *
	 * @return array{register: mixed, schema: mixed}|null
	 */
	private function usageLocation(): ?array {
		$config   = $this->settings->getVoorzieningenConfig();
		$register = ($config['register'] ?? null);
		$schema   = ($config['gebruik_schema'] ?? null);
		if ($register === null || $schema === null || $register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end usageLocation()

	/**
	 * OpenRegister's lookups for this request.
	 *
	 * @return OpenRegisterLookup
	 */
	private function lookup(): OpenRegisterLookup {
		return new OpenRegisterLookup(
			appManager: $this->appManager,
			container: $this->container,
			userSession: $this->userSession,
			logger: $this->logger
		);
	}//end lookup()
}//end class
