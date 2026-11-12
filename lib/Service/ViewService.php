<?php

/**
 * View Service for Stackiq
 *
 * Handles view-specific operations including querying, enrichment with products,
 * and usage data (gebruik) integration.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * View Service for managing ArchiMate views with enrichment capabilities
 *
 * This service provides operations for querying and enriching views with additional
 * data such as products, usage information (gebruik), and related data.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.UndefinedVariable)
 */
class ViewService {

	/**
	 * Distributed cache for views responses.
	 *
	 * @var ICache
	 */
	private ICache $viewsCache;

	/**
	 * Cache TTL in seconds (30 minutes).
	 */
	private const CACHE_TTL = 1800;

	/**
	 * The module fields that can hold referentiecomponent relationships, in lookup order.
	 */
	private const REFERENCE_COMPONENT_FIELDS = [
		'referentiecomponenten',
		'referenceComponents',
		'relatedComponents',
		'components',
		'relationships',
	];

	/**
	 * Constructor for ViewService
	 *
	 * @param IAppConfig $config Nextcloud app configuration service
	 * @param IAppManager $appManager App manager service
	 * @param ContainerInterface $container PSR-11 container interface
	 * @param LoggerInterface $logger Logger service
	 * @param SettingsService $settingsService Settings service for configuration
	 * @param IUserSession $userSession User session service for current user context
	 * @param ICacheFactory $cacheFactory Cache factory for distributed caching
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		ICacheFactory $cacheFactory,
	) {
		$this->viewsCache = $cacheFactory->createDistributed(prefix: 'stackiq_views');
	}//end __construct()

	/**
	 * Get all views from the system.
	 *
	 * @param array $options Query options including enrichment flags.
	 *
	 * @return array Array of view objects with optional enrichments.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getAllViews(array $options = []): array {
		$this->logger->info(
			'Getting all views',
			[
				'options' => $options,
			]
		);

		try {
			// Get all view objects from OpenRegister.
			$views = $this->getViewsFromRegister();

			// Transform views to include critical API fields.
			$views = $this->transformViews(views: $views);

			// Apply enrichments based on options.
			if (empty($options) === false) {
				$views = $this->enrichViews(views: $views, options: $options);
			}

			// Apply module-to-viewNode expansion if modules are enabled.
			if (isset($options['include_modules']) === true && $options['include_modules'] === true) {
				$views = $this->expandModulesToViewNodes(views: $views);
			}

			return [
				'success' => true,
				'views' => $views,
				'count' => count($views),
				'enrichments_applied' => $this->getAppliedEnrichments(options: $options),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get all views',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'success' => false,
				'error' => $e->getMessage(),
				'views' => [],
				'count' => 0,
			];
		}//end try
	}//end getAllViews()

	/**
	 * Get a specific view by ID.
	 *
	 * @param string $viewId The view identifier.
	 * @param array $options Query options including enrichment flags.
	 *
	 * @return array View object with optional enrichments or error response.
	 * @spec   openspec/specs/dashboard-views-api/spec.md
	 */
	public function getView(string $viewId, array $options = []): array {
		$this->logger->info(
			'Getting specific view',
			[
				'view_id' => $viewId,
				'options' => $options,
			]
		);

		try {
			// Get specific view from OpenRegister.
			$view = $this->getViewFromRegister(viewId: $viewId);

			if ($view === null) {
				return [
					'success' => false,
					'error' => "View with ID '$viewId' not found",
					'view' => null,
				];
			}

			// Transform view to include critical API fields.
			$view = $this->transformView(view: $view);

			// Apply enrichments based on options.
			if (empty($options) === false) {
				$view = $this->enrichView(view: $view, options: $options);

				// Apply module-to-viewNode expansion if modules are enabled.
				if (isset($options['include_modules']) === true && $options['include_modules'] === true) {
					$views = $this->expandModulesToViewNodes(views: [$view]);
					// Get the expanded view back.
					$view = $views[0] ?? $view;
				}
			}

			return [
				'success' => true,
				'view' => $view,
				'enrichments_applied' => $this->getAppliedEnrichments(options: $options),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get view',
				[
					'view_id' => $viewId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'success' => false,
				'error' => $e->getMessage(),
				'view' => null,
			];
		}//end try
	}//end getView()

	/**
	 * Get views from OpenRegister system with response-level caching.
	 *
	 * Results are cached for 30 minutes to prevent cold-start delays, per
	 * caller (see getViewsCacheKey()).
	 *
	 * @return array Array of view objects
	 */
	private function getViewsFromRegister(): array {
		// Check cache first.
		$cacheKey = $this->getViewsCacheKey();
		$cached = $this->viewsCache->get(key: $cacheKey);
		if ($cached !== null) {
			$this->logger->debug(
				message: 'Returning cached views',
				context: ['views_count' => count(value: $cached)]
			);
			return $cached;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			throw new \RuntimeException(message: 'OpenRegister ObjectService not available');
		}

		// Get AMEF configuration for view schema.
		$amefConfig = $this->settingsService->getAmefConfig();
		$registerId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;
		$viewSchemaId = $amefConfig['view_schema'] ?? null;

		if ($registerId === null || $viewSchemaId === null) {
			throw new \RuntimeException(message: 'AMEF configuration not found for views');
		}

		// Query for all view objects. Bounded — an unset `_limit` means
		// Doctrine's setMaxResults(null), i.e. no LIMIT clause at all
		// (full-table scan). 500 is a safe ceiling for the view index load.
		$query = [
			'@self' => [
				'register' => $registerId,
				'schema' => $viewSchemaId,
			],
			'_limit' => 500,
		];

		$viewEntities = $objectService->searchObjects($query);

		// Serialize ObjectEntity instances to arrays.
		$views = array_map(
			callback: function ($view) {
				if (is_array(value: $view) === false
					&& method_exists(object_or_class: $view, method: 'jsonSerialize') === true
				) {
					return $view->jsonSerialize();
				}

				return $view;
			},
			array: $viewEntities
		);

		// Cache the result.
		$this->viewsCache->set(key: $cacheKey, value: $views, ttl: self::CACHE_TTL);

		$this->logger->debug(
			message: 'Retrieved and cached views from register',
			context: [
				'register_id' => $registerId,
				'view_schema_id' => $viewSchemaId,
				'views_count' => count(value: $views),
			]
		);

		return $views;
	}//end getViewsFromRegister()

	/**
	 * Build the views list cache key for the current caller.
	 *
	 * The list is read with OpenRegister's RBAC and multitenancy on, so what
	 * it holds depends on the user and on their active organisation. The key
	 * carries both: one caller's scoped list is never served to another, and a
	 * user who switches organisation does not keep the list of the old one.
	 *
	 * @return string The cache key.
	 *
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-006-drawn-views-shall-stay-inside-the-organisation-that-drew-them
	 */
	private function getViewsCacheKey(): string {
		$userId = '';
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$organisation = $this->getCurrentOrganisation() ?? '';

		return 'views_list_'.hash(algo: 'sha256', data: $userId."\n".$organisation);
	}//end getViewsCacheKey()

	/**
	 * Get a specific view from OpenRegister system.
	 *
	 * @param string $viewId The view identifier.
	 *
	 * @return array|null View object or null if not found.
	 */
	private function getViewFromRegister(string $viewId): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			throw new \RuntimeException(message: 'OpenRegister ObjectService not available');
		}

		// Get AMEF configuration for view schema.
		$amefConfig = $this->settingsService->getAmefConfig();
		$registerId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;
		$viewSchemaId = $amefConfig['view_schema'] ?? null;

		if ($registerId === null || $viewSchemaId === null) {
			throw new \RuntimeException(message: 'AMEF configuration not found for views');
		}

		try {
			// Get specific view object by ID, with OpenRegister's RBAC and
			// multitenancy checks on (the defaults), the same checks the list
			// reads with. A view the caller may not read comes back as null
			// (or throws), which getView() answers as not found, so a uuid is
			// no way around the list's scope.
			$view = $objectService->find(
				id: $viewId,
				options: new ObjectFindOptions(register: $registerId, schema: $viewSchemaId)
			);

			// Serialise the entity to an array. No `is_array()` / `method_exists()`
			// probes: `find()` returns `?ObjectEntityInterface`, never a bare
			// array, and `ObjectEntityInterface extends JsonSerializable`, so both
			// guards were answers already known at compile time.
			if ($view !== null) {
				$view = $view->jsonSerialize();
			}

			$this->logger->debug(
				message: 'Retrieved specific view from register',
				context: [
					'view_id' => $viewId,
					'register_id' => $registerId,
					'view_schema_id' => $viewSchemaId,
					'found' => $view !== null,
				]
			);

			return $view;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'View not found in register',
				context: [
					'view_id' => $viewId,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end getViewFromRegister()

	/**
	 * Enrich multiple views with additional data.
	 *
	 * @param array $views Array of view objects.
	 * @param array $options Enrichment options.
	 *
	 * @return array Array of enriched view objects.
	 */
	private function enrichViews(array $views, array $options): array {
		$enrichedViews = [];

		foreach ($views as $view) {
			$enrichedViews[] = $this->enrichView(view: $view, options: $options);
		}

		return $enrichedViews;
	}//end enrichViews()

	/**
	 * Enrich a single view with additional data.
	 *
	 * @param array $view View object to enrich.
	 * @param array $options Enrichment options.
	 *
	 * @return array Enriched view object.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function enrichView(array $view, array $options): array {
		$enrichedView = $view;

		// Enrich viewNodes if present.
		if (isset($view['viewNodes']) === true && is_array(value: $view['viewNodes']) === true) {
			$enrichedView['viewNodes'] = $this->enrichViewNodes(
				viewNodes: $view['viewNodes'],
				options: $options
			);
		}

		// TODO: Add view-level enrichments here if needed.
		return $enrichedView;
	}//end enrichView()

	/**
	 * Enrich view nodes with additional data based on options.
	 *
	 * @param array $viewNodes Array of view nodes.
	 * @param array $options Enrichment options.
	 *
	 * @return array Array of enriched view nodes.
	 */
	private function enrichViewNodes(array $viewNodes, array $options): array {
		// Get enrichment data upfront if requested.
		$enrichers = $this->nodeEnrichers(data: $this->enrichmentData(options: $options));

		$enrichedNodes = [];
		foreach ($viewNodes as $node) {
			$enrichedNodes[] = $this->enrichViewNode(node: $node, enrichers: $enrichers);
		}

		return $enrichedNodes;
	}//end enrichViewNodes()

	/**
	 * Load the data of every requested enrichment, keyed by the node field it fills.
	 *
	 * @param array $options Enrichment options.
	 *
	 * @return array The requested enrichment data, in products, modules, usage, deelnames order.
	 */
	private function enrichmentData(array $options): array {
		return array_merge(
			$this->catalogueEnrichmentData(options: $options),
			$this->usageEnrichmentData(options: $options)
		);
	}//end enrichmentData()

	/**
	 * Load the products and modules data when requested.
	 *
	 * @param array $options Enrichment options.
	 *
	 * @return array The products and modules data, keyed by node field.
	 */
	private function catalogueEnrichmentData(array $options): array {
		$data = [];
		if ($this->shouldIncludeProducts(options: $options) === true) {
			$data['products'] = $this->getProductsData();
		}

		if ($this->shouldIncludeModules(options: $options) === true) {
			$data['modules'] = $this->getModulesData();
		}

		return $data;
	}//end catalogueEnrichmentData()

	/**
	 * Load the owned and deelnames gebruik data when requested.
	 *
	 * Owned gebruik takes precedence over deelnames for the same elementRef.
	 *
	 * @param array $options Enrichment options.
	 *
	 * @return array The usage and deelnames gebruik data, keyed by node field.
	 */
	private function usageEnrichmentData(array $options): array {
		$data = [];
		if ($this->shouldIncludeGebruik(options: $options) === true) {
			$data['usage'] = $this->getGebruikData();
		}

		if ($this->shouldIncludeDeelnamesGebruik(options: $options) === true) {
			$data['deelnamesGebruik'] = $this->getDeelnamesGebruikData();
		}

		// Deduplicate: owned gebruik takes precedence over deelnames for the same elementRef.
		if (empty($data['usage'] ?? []) === false && empty($data['deelnamesGebruik'] ?? []) === false) {
			$data['deelnamesGebruik'] = array_diff_key($data['deelnamesGebruik'], $data['usage']);
		}

		return $data;
	}//end usageEnrichmentData()

	/**
	 * Build one node enricher per loaded enrichment, keyed by the node field it fills.
	 *
	 * @param array $data The loaded enrichment data, keyed by node field.
	 *
	 * @return array<string, callable> The enrichers, in products, modules, usage, deelnames order.
	 */
	private function nodeEnrichers(array $data): array {
		$enrichers = [
			'products' => fn ($id): array => $this->getNodeProducts(modelNodeId: $id, productsData: $data['products']),
			'modules' => fn ($id): array => $this->getNodeModules(modelNodeId: $id, modulesData: $data['modules']),
			'usage' => fn ($id): array => $this->getNodeGebruik(modelNodeId: $id, gebruikData: $data['usage']),
			'deelnamesGebruik' => fn ($id): array => $this->getNodeDeelnamesGebruik(
				modelNodeId: $id,
				deelnamesGebruikData: $data['deelnamesGebruik']
			),
		];

		return array_intersect_key($enrichers, $data);
	}//end nodeEnrichers()

	/**
	 * Apply every enricher to a node that references a model element.
	 *
	 * @param array $node The view node.
	 * @param array<string, callable> $enrichers The node enrichers, keyed by node field.
	 *
	 * @return array The enriched node; a node without modelNodeId is returned as is.
	 */
	private function enrichViewNode(array $node, array $enrichers): array {
		// Apply enrichments based on the node's modelNodeId (element reference).
		$modelNodeId = $node['modelNodeId'] ?? null;
		if ($modelNodeId === null) {
			return $node;
		}

		foreach ($enrichers as $field => $enricher) {
			$node[$field] = $enricher($modelNodeId);
		}

		return $node;
	}//end enrichViewNode()

	/**
	 * Check if products should be included in enrichment.
	 *
	 * @param array $options Enrichment options.
	 *
	 * @return bool True if products should be included.
	 */
	private function shouldIncludeProducts(array $options): bool {
		return isset($options['include_products']) === true && $options['include_products'] === true;
	}//end shouldIncludeProducts()

	/**
	 * Check if modules should be included in enrichment.
	 *
	 * @param array $options Enrichment options.
	 *
	 * @return bool True if modules should be included.
	 */
	private function shouldIncludeModules(array $options): bool {
		return isset($options['include_modules']) === true && $options['include_modules'] === true;
	}//end shouldIncludeModules()

	/**
	 * Check if gebruik should be included in enrichment.
	 *
	 * @param array $options Enrichment options.
	 *
	 * @return bool True if gebruik should be included.
	 */
	private function shouldIncludeGebruik(array $options): bool {
		return isset($options['include_gebruik']) === true && $options['include_gebruik'] === true;
	}//end shouldIncludeGebruik()

	/**
	 * Check if deelnames gebruik should be included in enrichment.
	 *
	 * @param array $options Enrichment options.
	 *
	 * @return bool True if deelnames gebruik should be included.
	 */
	private function shouldIncludeDeelnamesGebruik(array $options): bool {
		return isset($options['include_deelnames_gebruik']) === true && $options['include_deelnames_gebruik'] === true;
	}//end shouldIncludeDeelnamesGebruik()

	/**
	 * Get applied enrichments list for response metadata.
	 *
	 * @param array $options Enrichment options.
	 *
	 * @return array List of applied enrichments.
	 */
	private function getAppliedEnrichments(array $options): array {
		$enrichments = [];

		if ($this->shouldIncludeProducts(options: $options) === true) {
			$enrichments[] = 'products';
		}

		if ($this->shouldIncludeModules(options: $options) === true) {
			$enrichments[] = 'modules';
		}

		if ($this->shouldIncludeGebruik(options: $options) === true) {
			$enrichments[] = 'usage';
		}

		if ($this->shouldIncludeDeelnamesGebruik(options: $options) === true) {
			$enrichments[] = 'deelnames_gebruik';
		}

		return $enrichments;
	}//end getAppliedEnrichments()

	/**
	 * Get products data for enrichment based on elementRef linkage.
	 *
	 * "Products" are the catalog's `dienst` (service/product) schema records
	 * — the existing voorzieningen-register schema this catalog domain uses
	 * for services/products (ADR-022 reuse; see this change's design.md for
	 * the full rationale). Retrieves all dienst data from OpenRegister,
	 * filtered by current organisation, indexed by elementRef for O(1) node
	 * matching (mirroring getModulesData()'s established pattern).
	 *
	 * @return array Products data indexed by elementRef.
	 *
	 * @spec openspec/changes/view-products-enrichment/specs/view-enrichment-api/spec.md
	 */
	private function getProductsData(): array {
		$this->logger->debug('Getting products data for enrichment');

		try {
			return $this->loadProductsData();
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get products data',
				['error' => $e->getMessage()]
			);
			return [];
		}
	}//end getProductsData()

	/**
	 * Load the dienst records of the current organisation, indexed by elementRef.
	 *
	 * @return array Products data indexed by elementRef.
	 */
	private function loadProductsData(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		// Use voorzieningen config for the correct dienst register and schema.
		$target = $this->productTarget();
		if ($target === null) {
			return [];
		}

		$query = $this->withOrganisation(
			query: ['@self' => $target, '_limit' => 500],
			currentOrg: $this->getCurrentOrganisation()
		);
		return $this->indexProducts(objectService: $objectService, query: $query);
	}//end loadProductsData()

	/**
	 * Resolve the register and dienst schema the products are read from.
	 *
	 * @return array|null The register and schema, or null when not configured.
	 */
	private function productTarget(): ?array {
		$voorzConfig = $this->settingsService->getVoorzieningenConfig();
		$registerId = $voorzConfig['register'] ?? null;
		$dienstSchemaId = $voorzConfig['dienst_schema'] ?? null;

		if ($registerId === null || $dienstSchemaId === null || $dienstSchemaId === '') {
			$this->logger->warning(message: 'Voorzieningen register or dienst schema not configured for products');
			return null;
		}

		return [
			'register' => $registerId,
			'schema' => $dienstSchemaId,
		];
	}//end productTarget()

	/**
	 * Search the products and index the ones of the query's organisation.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array $query The organisation-scoped products query.
	 *
	 * @return array Products data indexed by elementRef.
	 */
	private function indexProducts(ObjectServiceInterface $objectService, array $query): array {
		$allProducts = $this->indexByElementRef(
			rows: $objectService->searchObjects($query),
			currentOrg: $query['@self']['organisation'] ?? null
		);

		$this->logger->debug(
			'Total products retrieved for enrichment',
			['total_products' => count($allProducts)]
		);

		return $allProducts;
	}//end indexProducts()

	/**
	 * Scope a query to the current organisation when there is one.
	 *
	 * @param array $query The OpenRegister search query.
	 * @param string|null $currentOrg The current organisation uuid.
	 *
	 * @return array The query, with the organisation pinned into `@self` when known.
	 */
	private function withOrganisation(array $query, ?string $currentOrg): array {
		if ($currentOrg !== null) {
			$query['@self']['organisation'] = $currentOrg;
		}

		return $query;
	}//end withOrganisation()

	/**
	 * Index rows by elementRef (or identifier), skipping rows of another organisation.
	 *
	 * This is the additional organisation check for rows the query did not
	 * catch: a row whose `@self.organisation` is set and differs from the
	 * current organisation is dropped; rows without one are kept. A later row
	 * with the same reference replaces an earlier one.
	 *
	 * @param array $rows The rows returned by OpenRegister.
	 * @param string|null $currentOrg The current organisation uuid, or null for no filter.
	 *
	 * @return array The rows indexed by elementRef.
	 */
	private function indexByElementRef(array $rows, ?string $currentOrg): array {
		$indexed = [];
		foreach ($rows as $row) {
			if ($this->belongsToOtherOrganisation(row: $row, currentOrg: $currentOrg) === true) {
				continue;
			}

			// Index by elementRef or identifier for quick lookup.
			$elementRef = $row['elementRef'] ?? $row['identifier'] ?? null;
			if ($elementRef !== null) {
				$indexed[$elementRef] = $row;
			}
		}

		return $indexed;
	}//end indexByElementRef()

	/**
	 * Whether a row is owned by an organisation other than the current one.
	 *
	 * @param array $row The OpenRegister row.
	 * @param string|null $currentOrg The current organisation uuid, or null for no filter.
	 *
	 * @return bool True when the row's organisation is set and differs.
	 */
	private function belongsToOtherOrganisation(array $row, ?string $currentOrg): bool {
		$rowOrg = $row['@self']['organisation'] ?? null;
		return $currentOrg !== null
			&& isset($row['@self']['organisation']) === true
			&& $rowOrg !== $currentOrg;
	}//end belongsToOtherOrganisation()

	/**
	 * Get current active organisation UUID for filtering.
	 *
	 * Uses the OpenRegister OrganisationService to retrieve the active organisation.
	 *
	 * @return string|null Current organisation UUID or null if not available.
	 */
	private function getCurrentOrganisation(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		try {
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			$activeOrg = $organisationService->getActiveOrganisation();
			if ($activeOrg !== null) {
				return $activeOrg->getUuid();
			}

			return null;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'Failed to get current organisation from OpenRegister',
				context: ['error' => $e->getMessage()]
			);
			return null;
		}
	}//end getCurrentOrganisation()

	/**
	 * Get modules data for enrichment based on elementRef linkage.
	 *
	 * Modules are directly linked to nodes based on their elementRef.
	 * This method retrieves all module data from OpenRegister, filtered by current organisation.
	 *
	 * @return array Modules data indexed by elementRef.
	 */
	private function getModulesData(): array {
		$this->logger->debug('Getting modules data for enrichment');

		try {
			return $this->loadModulesData();
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get modules data',
				[
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}//end getModulesData()

	/**
	 * Load the modules of the current organisation from every module schema.
	 *
	 * @return array Modules data indexed by elementRef.
	 */
	private function loadModulesData(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		// Get current organisation for filtering.
		$currentOrg = $this->getCurrentOrganisation();

		// Get configuration for modules register/schema.
		$amefConfig = $this->settingsService->getAmefConfig();
		if ($this->hasModuleRegister(amefConfig: $amefConfig) === false) {
			return [];
		}

		$queries = $this->moduleQueries(amefConfig: $amefConfig, currentOrg: $currentOrg);
		return $this->collectModules(objectService: $objectService, queries: $queries);
	}//end loadModulesData()

	/**
	 * Whether the AMEF register the modules live in is configured.
	 *
	 * @param array $amefConfig The AMEF configuration.
	 *
	 * @return bool True when a register is configured.
	 */
	private function hasModuleRegister(array $amefConfig): bool {
		// Fail closed on an unconfigured register. This used to be the one
		// read of `register_id` with no guard at all: the loop below only
		// checks the SCHEMA, so an empty register would have been pinned
		// into `@self` and OpenRegister asked for "any register" — an
		// unpinned query returns rows, which reads exactly like a correct
		// result. `empty()` rather than `=== null` because the legacy
		// config fallback resolves unset ids to `''`, and `'' === null` is
		// false.
		if (empty($amefConfig['register_id'] ?? null) === true) {
			$this->logger->warning(
				'ViewService: AMEF register is not configured; skipping the module lookup rather than issuing an unpinned query'
			);

			return false;
		}

		return true;
	}//end hasModuleRegister()

	/**
	 * Build one organisation-scoped query per configured module schema.
	 *
	 * @param array $amefConfig The AMEF configuration.
	 * @param string|null $currentOrg The current organisation uuid.
	 *
	 * @return array<int, array> The queries, in schema order.
	 */
	private function moduleQueries(array $amefConfig, ?string $currentOrg): array {
		$queries = [];
		foreach ($this->moduleSchemaIds(amefConfig: $amefConfig) as $schemaId) {
			// Builds a lookup index (O(1) node matching), so this
			// intentionally fetches "all matching" — bounded at a
			// documented safe ceiling rather than left unbounded.
			$query = [
				'@self' => [
					'register' => $amefConfig['register_id'],
					'schema' => $schemaId,
				],
				'_limit' => 1000,
			];
			$queries[] = $this->withOrganisation(query: $query, currentOrg: $currentOrg);
		}

		return $queries;
	}//end moduleQueries()

	/**
	 * The configured schemas modules can live in, skipping unset ones.
	 *
	 * @param array $amefConfig The AMEF configuration.
	 *
	 * @return array The schema ids, in lookup order.
	 */
	private function moduleSchemaIds(array $amefConfig): array {
		// Modules could be in various schemas - check common ones.
		$moduleSchemas = [
			$amefConfig['module_schema'] ?? null,
			$amefConfig['component_schema'] ?? null,
			$amefConfig['element_schema'] ?? null,
		];

		return array_filter($moduleSchemas, static fn ($schemaId): bool => empty($schemaId) === false);
	}//end moduleSchemaIds()

	/**
	 * Run every module query and merge the indexed results in query order.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array<int, array> $queries The module queries.
	 *
	 * @return array Modules data indexed by elementRef.
	 */
	private function collectModules(ObjectServiceInterface $objectService, array $queries): array {
		$allModules = [];
		foreach ($queries as $query) {
			$modules = $this->indexModuleSchema(objectService: $objectService, query: $query);
			$allModules = array_replace($allModules, $modules);
		}

		$this->logger->debug(
			'Total modules retrieved for enrichment',
			[
				'total_modules' => count($allModules),
			]
		);

		return $allModules;
	}//end collectModules()

	/**
	 * Search one module schema and index the modules of the query's organisation.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array $query The module query of one schema.
	 *
	 * @return array The modules of that schema indexed by elementRef.
	 */
	private function indexModuleSchema(ObjectServiceInterface $objectService, array $query): array {
		return $this->indexByElementRef(
			rows: $this->searchModuleSchema(objectService: $objectService, query: $query),
			currentOrg: $query['@self']['organisation'] ?? null
		);
	}//end indexModuleSchema()

	/**
	 * Search one module schema; a failing schema is logged and yields no modules.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array $query The module query of one schema.
	 *
	 * @return array The modules of that schema.
	 */
	private function searchModuleSchema(ObjectServiceInterface $objectService, array $query): array {
		try {
			$modules = $objectService->searchObjects($query);
			$this->logModuleSchemaRead(query: $query, modules: $modules);
			return $modules;
		} catch (\Exception $e) {
			$this->logModuleSchemaFailure(query: $query, error: $e);
			return [];
		}
	}//end searchModuleSchema()

	/**
	 * Log that one module schema could not be searched.
	 *
	 * @param array $query The module query of one schema.
	 * @param \Exception $error The search failure.
	 *
	 * @return void
	 */
	private function logModuleSchemaFailure(array $query, \Exception $error): void {
		$this->logger->warning(
			'Failed to get modules from schema',
			[
				'schema_id' => $query['@self']['schema'],
				'error' => $error->getMessage(),
			]
		);
	}//end logModuleSchemaFailure()

	/**
	 * Log how many modules one schema returned.
	 *
	 * @param array $query The module query of one schema.
	 * @param array $modules The modules it returned.
	 *
	 * @return void
	 */
	private function logModuleSchemaRead(array $query, array $modules): void {
		$this->logger->debug(
			'Retrieved modules from schema',
			[
				'schema_id' => $query['@self']['schema'],
				'modules_count' => count($modules),
			]
		);
	}//end logModuleSchemaRead()

	/**
	 * Get regular owned gebruik data for enrichment based on elementRef linkage.
	 *
	 * Retrieves only organisation-owned gebruik via standard RBAC filtering.
	 * Deelnames gebruik is retrieved separately via getDeelnamesGebruikData().
	 *
	 * The register/schema and organisation scope are resolved from settings and
	 * the current session, so this needs no caller-supplied query options.
	 *
	 * @return array Gebruik data indexed by elementRef.
	 *
	 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
	 */
	private function getGebruikData(): array {
		$this->logger->debug(message: 'Getting regular gebruik data for view enrichment');

		try {
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [];
			}

			$currentOrg = $this->getCurrentOrganisation();

			// Use voorzieningen config for the correct gebruik register and schema.
			$voorzConfig = $this->settingsService->getVoorzieningenConfig();
			$registerId = $voorzConfig['register'] ?? null;
			$gebruikSchemaId = $voorzConfig['gebruik_schema'] ?? null;

			if ($registerId === null || $gebruikSchemaId === null) {
				$this->logger->warning(message: 'Voorzieningen register or gebruik schema not configured');
				return [];
			}

			$allGebruik = [];

			try {
				$query = [
					'@self' => [
						'register' => $registerId,
						'schema' => $gebruikSchemaId,
					],
					'_limit' => 500,
				];

				// Filter by organisation so only owned gebruik is returned.
				if ($currentOrg !== null) {
					$query['@self']['organisation'] = $currentOrg;
				}

				$gebruikItems = $objectService->searchObjects($query);
				$this->processGebruikItems(
					gebruikItems: $gebruikItems,
					allGebruik: $allGebruik,
					currentOrg: $currentOrg ?? '',
					type: 'regular'
				);

				$this->logger->debug(
					message: 'Retrieved regular gebruik from voorzieningen register',
					context: [
						'schema_id' => $gebruikSchemaId,
						'gebruik_count' => count(value: $gebruikItems),
						'organisation' => $currentOrg,
					]
				);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: 'Failed to get regular gebruik',
					context: [
						'schema_id' => $gebruikSchemaId,
						'error' => $e->getMessage(),
					]
				);
			}//end try

			$allGebruik = $this->extendGebruikWithModules(allGebruik: $allGebruik);

			$this->logger->debug(
				message: 'Total regular gebruik retrieved',
				context: [
					'total_element_refs' => count(value: $allGebruik),
					'current_org' => $currentOrg,
				]
			);

			return $allGebruik;
		} catch (\Exception $e) {
			$this->logger->error(
				message: 'Failed to get gebruik data',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end getGebruikData()

	/**
	 * Process gebruik items, group them by elementRef, and attach type metadata.
	 *
	 * For deelnames type, also attaches source organisation metadata from the afnemer field
	 * so the UI can display which organisation owns the shared gebruik.
	 *
	 * @param array $gebruikItems Array of gebruik objects from ObjectService.
	 * @param array $allGebruik Result array indexed by elementRef (passed by reference).
	 * @param string $currentOrg Current organisation UUID.
	 * @param string $type Either 'regular' (owned) or 'deelnames' (shared via deelnemers).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-3
	 */
	private function processGebruikItems(array $gebruikItems, array &$allGebruik, string $currentOrg, string $type): void {
		foreach ($gebruikItems as $usage) {
			// Group by elementRef for quick lookup.
			$elementRef = $usage['elementRef'] ?? $usage['componentRef'] ?? $usage['moduleRef'] ?? null;

			if ($elementRef !== null) {
				if (isset($allGebruik[$elementRef]) === false) {
					$allGebruik[$elementRef] = [];
				}

				$usage['_type'] = $type;
				$usage['_processed_for_org'] = $currentOrg;

				// Attach source org metadata for deelnames so the UI can attribute the shared gebruik.
				if ($type === 'deelnames') {
					$consumer = $usage['consumer'] ?? [];
					$usage['_sourceOrganizationId'] = $consumer['@self']['id'] ?? ($consumer['id'] ?? ($usage['@self']['organisation'] ?? null));
					$usage['_sourceOrganization'] = $consumer['name'] ?? null;
				}

				$allGebruik[$elementRef][] = $usage;
			}//end if
		}//end foreach
	}//end processGebruikItems()

	/**
	 * Extend gebruik data with modules information.
	 *
	 * @param array $allGebruik Gebruik data indexed by elementRef.
	 *
	 * @return array Extended gebruik data with modules.
	 */
	private function extendGebruikWithModules(array $allGebruik): array {
		$this->logger->debug('Extending gebruik data with modules information');

		// Get modules data for extension.
		$modulesData = $this->getModulesData();

		foreach ($allGebruik as $elementRef => &$gebruikList) {
			// Check if we have a module for this elementRef.
			if (isset($modulesData[$elementRef]) === true) {
				$module = $modulesData[$elementRef];

				// Add module data to each gebruik item for this elementRef.
				foreach ($gebruikList as &$usage) {
					$usage['_linked_module'] = $module;

					$this->logger->debug(
						'Linked module to gebruik',
						[
							'element_ref' => $elementRef,
							'gebruik_id' => $usage['id'] ?? $usage['identifier'] ?? 'unknown',
							'module_id' => $module['id'] ?? $module['identifier'] ?? 'unknown',
							'module_name' => $module['name'] ?? 'unnamed',
						]
					);
				}

				// Clean up reference.
				unset($usage);
			}//end if
		}//end foreach

		// Clean up reference.
		unset($gebruikList);
		return $allGebruik;
	}//end extendGebruikWithModules()

	/**
	 * Expand modules to viewNodes based on referentiecomponent relationships.
	 *
	 * For each module that has referentiecomponent relationships, add new nodes
	 * to viewNodes with the module as parent.
	 *
	 * @param array $views Array of views to process.
	 *
	 * @return array Views with expanded module nodes.
	 */
	private function expandModulesToViewNodes(array $views): array {
		$this->logger->debug('Expanding modules to viewNodes');

		// Get modules data for expansion.
		$modulesData = $this->getModulesData();

		return array_map(
			fn (array $view): array => $this->expandViewModules(view: $view, modulesData: $modulesData),
			$views
		);
	}//end expandModulesToViewNodes()

	/**
	 * Add a child node for every module linked to a referentiecomponent node of one view.
	 *
	 * @param array $view The view.
	 * @param array $modulesData Modules data indexed by elementRef.
	 *
	 * @return array The view with expanded module nodes; a view without a node list is returned as is.
	 */
	private function expandViewModules(array $view, array $modulesData): array {
		if (isset($view['viewNodes']) === false || is_array(value: $view['viewNodes']) === false) {
			return $view;
		}

		$originalNodes = $view['viewNodes'];

		// Update the view with expanded nodes.
		$view['viewNodes'] = $this->expandNodesWithModules(nodes: $originalNodes, modulesData: $modulesData);
		$this->logAddedModuleNodes(view: $view, originalNodes: $originalNodes);
		return $view;
	}//end expandViewModules()

	/**
	 * Append a node for every module under its referentiecomponent nodes.
	 *
	 * @param array $nodes The view nodes before the expansion.
	 * @param array $modulesData Modules data indexed by elementRef.
	 *
	 * @return array The nodes followed by the added module nodes.
	 */
	private function expandNodesWithModules(array $nodes, array $modulesData): array {
		$expandedNodes = $nodes;
		$nodesByModelId = $this->nodesByModelId(nodes: $nodes);

		// Process each module for expansion.
		foreach ($modulesData as $module) {
			$expandedNodes = $this->expandModuleToNodes(
				module: $module,
				existingNodes: $expandedNodes,
				nodesByModelId: $nodesByModelId
			);
		}

		return $expandedNodes;
	}//end expandNodesWithModules()

	/**
	 * Create a lookup of existing nodes by modelNodeId for quick parent matching.
	 *
	 * @param array $nodes The view nodes.
	 *
	 * @return array The nodes indexed by modelNodeId.
	 */
	private function nodesByModelId(array $nodes): array {
		$nodesByModelId = [];
		foreach ($nodes as $node) {
			$modelNodeId = $node['modelNodeId'] ?? null;
			if ($modelNodeId !== null) {
				$nodesByModelId[$modelNodeId] = $node;
			}
		}

		return $nodesByModelId;
	}//end nodesByModelId()

	/**
	 * Log how many module nodes were added to a view, when any were.
	 *
	 * @param array $view The expanded view.
	 * @param array $originalNodes The view nodes before the expansion.
	 *
	 * @return void
	 */
	private function logAddedModuleNodes(array $view, array $originalNodes): void {
		$addedNodesCount = count($view['viewNodes']) - count($originalNodes);
		if ($addedNodesCount > 0) {
			$this->logger->debug(
				'Added module nodes to view',
				[
					'view_id' => $view['id'] ?? 'unknown',
					'original_nodes' => count($originalNodes),
					'expanded_nodes' => count($view['viewNodes']),
					'added_nodes' => $addedNodesCount,
				]
			);
		}
	}//end logAddedModuleNodes()

	/**
	 * Expand a single module to nodes based on its referentiecomponent relationships.
	 *
	 * @param array $module Module data.
	 * @param array $existingNodes Current view nodes.
	 * @param array $nodesByModelId Lookup of nodes by modelNodeId.
	 *
	 * @return array Updated nodes array with module expansions.
	 */
	private function expandModuleToNodes(array $module, array $existingNodes, array $nodesByModelId): array {
		$expandedNodes = $existingNodes;

		// Look for referentiecomponent relationships in the module.
		$referenceComponents = $this->extractReferenceComponenten(module: $module);

		foreach ($referenceComponents as $referenceComponentId) {
			// Find if there's an existing node for this referentiecomponent.
			$parentNode = $nodesByModelId[$referenceComponentId] ?? null;

			if ($parentNode !== null) {
				// Create a new node for this module as child of the referentiecomponent.
				$moduleNode = $this->createModuleNode(
					module: $module,
					parentNode: $parentNode,
					referenceComponentId: $referenceComponentId
				);
				$expandedNodes[] = $moduleNode;

				$this->logger->debug(
					'Created module node',
					[
						'module_id' => $module['id'] ?? $module['identifier'] ?? 'unknown',
						'module_name' => $module['name'] ?? 'unnamed',
						'parent_node_id' => $parentNode['viewNodeId'] ?? 'unknown',
						'referentie_component_id' => $referenceComponentId,
					]
				);
			}
		}//end foreach

		return $expandedNodes;
	}//end expandModuleToNodes()

	/**
	 * Extract referentiecomponent IDs from module data.
	 *
	 * @param array $module Module data.
	 *
	 * @return array Array of referentiecomponent identifiers.
	 */
	private function extractReferenceComponenten(array $module): array {
		$referenceComponents = [];

		// Look for referentiecomponent relationships in various possible locations.
		foreach (self::REFERENCE_COMPONENT_FIELDS as $field) {
			if (isset($module[$field]) === true) {
				$fieldIds = $this->referenceComponentIds(value: $module[$field]);
				$referenceComponents = array_merge($referenceComponents, $fieldIds);
			}
		}

		return array_unique($referenceComponents);
	}//end extractReferentieComponenten()

	/**
	 * Read the referentiecomponent ids of one module field.
	 *
	 * @param mixed $value A single component id or a list of components.
	 *
	 * @return array The component ids, in list order.
	 */
	private function referenceComponentIds(mixed $value): array {
		if (is_string(value: $value) === true) {
			// Handle single component ID.
			return [$value];
		}

		if (is_array(value: $value) === false) {
			return [];
		}

		// Handle array of components.
		$ids = array_map(
			fn ($component): mixed => $this->referenceComponentId(component: $component),
			$value
		);

		return array_values(array_filter($ids, static fn ($id): bool => $id !== null));
	}//end referenceComponentIds()

	/**
	 * Read the id of one component: the string itself, or its id or identifier.
	 *
	 * @param mixed $component A component id or component data.
	 *
	 * @return mixed The component id, or null when it has none.
	 */
	private function referenceComponentId(mixed $component): mixed {
		if (is_string(value: $component) === true) {
			return $component;
		}

		if (is_array(value: $component) === false) {
			return null;
		}

		return $component['id'] ?? $component['identifier'] ?? null;
	}//end referenceComponentId()

	/**
	 * Create a module node as child of a referentiecomponent node.
	 *
	 * @param array $module Module data.
	 * @param array $parentNode Parent referentiecomponent node.
	 * @param string $referenceComponentId Referentiecomponent identifier.
	 *
	 * @return array New module node.
	 */
	private function createModuleNode(array $module, array $parentNode, string $referenceComponentId): array {
		$moduleId = $module['id'] ?? $module['identifier'] ?? uniqid('module_');
		$moduleName = $module['name'] ?? 'Unnamed Module';

		// Position the module node relative to parent (slightly offset).
		$parentX = $parentNode['x'] ?? 0;
		$parentY = $parentNode['y'] ?? 0;
		$parentWidth = $parentNode['width'] ?? 100;

		return [
			'modelNodeId' => $moduleId,
			'viewNodeId' => 'module-' . $moduleId . '-' . uniqid(),
			// Position to the right of parent.
			'x' => $parentX + $parentWidth + 20,
			'y' => $parentY,
			'width' => 150,
			'height' => 50,
			'parent' => $parentNode['viewNodeId'] ?? null,
			'name' => $moduleName,
			'type' => 'module',
			// Light green for modules.
			'color' => 'rgb(200, 255, 200)',
			'borderColor' => 'rgb(0, 150, 0)',
			'font' => [
				'name' => 'Segoe UI, Arial',
				'size' => 11,
				'style' => 'normal',
				'color' => 'rgb(0, 0, 0)',
			],
			'description' => $module['description'] ?? $module['omschrijving'] ?? null,
			'elementRef' => $moduleId,
			'_isModuleExpansion' => true,
			'_parentReferentieComponent' => $referenceComponentId,
			'_moduleData' => $module,
		];
	}//end createModuleNode()

	/**
	 * Get deelnames gebruik data for enrichment.
	 *
	 * Queries gebruiksobjecten where the current organisation appears in the deelnemers field.
	 * RBAC and multitenancy are both disabled so objects owned by other organisations are visible.
	 * Source organisation metadata is attached to each result via processGebruikItems().
	 *
	 * @return array Deelnames gebruik data indexed by elementRef.
	 *
	 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
	 */
	private function getDeelnamesGebruikData(): array {
		$this->logger->debug(message: 'Getting deelnames gebruik data for view enrichment');

		try {
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [];
			}

			$currentOrg = $this->getCurrentOrganisation();
			if ($currentOrg === null) {
				return [];
			}

			// Use voorzieningen config for the correct gebruik register and schema.
			$voorzConfig = $this->settingsService->getVoorzieningenConfig();
			$registerId = $voorzConfig['register'] ?? null;
			$gebruikSchemaId = $voorzConfig['gebruik_schema'] ?? null;

			if ($registerId === null || $gebruikSchemaId === null) {
				$this->logger->warning(message: 'Voorzieningen register or gebruik schema not configured for deelnames');
				return [];
			}

			$allDeelnames = [];

			try {
				$query = [
					'@self' => [
						'register' => $registerId,
						'schema' => $gebruikSchemaId,
					],
					// Match objects where this org appears as a deelnemer.
					'participants' => $currentOrg,
					'_limit' => 500,
				];

				// Both _rbac and _multitenancy must be false to find objects owned by other organisations.
				$deelnamesItems = $objectService->searchObjects($query, _rbac: false, _multitenancy: false);
				$this->processGebruikItems(
					gebruikItems: $deelnamesItems,
					allGebruik: $allDeelnames,
					currentOrg: $currentOrg,
					type: 'deelnames'
				);

				$this->logger->debug(
					message: 'Retrieved deelnames gebruik data',
					context: [
						'schema_id' => $gebruikSchemaId,
						'deelnames_count' => count(value: $deelnamesItems),
						'organisation_in_deelnemers' => $currentOrg,
					]
				);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: 'Failed to get deelnames gebruik data; view will render without deelnames',
					context: [
						'schema_id' => $gebruikSchemaId,
						'error' => $e->getMessage(),
					]
				);
			}//end try

			$allDeelnames = $this->extendGebruikWithModules(allGebruik: $allDeelnames);

			$this->logger->debug(
				message: 'Total deelnames gebruik retrieved',
				context: ['total_element_refs' => count(value: $allDeelnames)]
			);

			return $allDeelnames;
		} catch (\Exception $e) {
			$this->logger->error(
				message: 'Failed to get deelnames gebruik data',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end getDeelnamesGebruikData()

	/**
	 * Get products for a specific node based on elementRef linkage.
	 *
	 * @param string $modelNodeId The model node identifier (elementRef).
	 * @param array $productsData Products data indexed by elementRef.
	 *
	 * @return array Products related to the node.
	 *
	 * @spec openspec/changes/view-products-enrichment/specs/view-enrichment-api/spec.md
	 */
	private function getNodeProducts(string $modelNodeId, array $productsData): array {
		$this->logger->debug(
			'Getting products for node',
			[
				'model_node_id' => $modelNodeId,
				'available_products_count' => count($productsData),
			]
		);

		// Direct lookup by elementRef.
		if (isset($productsData[$modelNodeId]) === true) {
			$product = $productsData[$modelNodeId];

			$this->logger->debug(
				'Found product for node',
				[
					'model_node_id' => $modelNodeId,
					'product_id' => $product['id'] ?? $product['identifier'] ?? 'unknown',
					'product_name' => $product['name'] ?? 'unnamed',
				]
			);

			// Return as array for consistency.
			return [$product];
		}

		// No product found for this node.
		return [];
	}//end getNodeProducts()

	/**
	 * Get modules for a specific node based on elementRef linkage.
	 *
	 * @param string $modelNodeId The model node identifier (elementRef).
	 * @param array $modulesData Modules data indexed by elementRef.
	 *
	 * @return array Modules related to the node.
	 */
	private function getNodeModules(string $modelNodeId, array $modulesData): array {
		$this->logger->debug('Getting modules for node', ['model_node_id' => $modelNodeId]);

		// Direct lookup by elementRef.
		if (isset($modulesData[$modelNodeId]) === true) {
			$module = $modulesData[$modelNodeId];

			$this->logger->debug(
				'Found module for node',
				[
					'model_node_id' => $modelNodeId,
					'module_id' => $module['id'] ?? $module['identifier'] ?? 'unknown',
					'module_name' => $module['name'] ?? 'unnamed',
				]
			);

			// Return as array for consistency.
			return [$module];
		}

		// No module found for this node.
		return [];
	}//end getNodeModules()

	/**
	 * Get gebruik for a specific node based on elementRef linkage.
	 *
	 * @param string $modelNodeId The model node identifier (elementRef).
	 * @param array $gebruikData Gebruik data indexed by elementRef.
	 *
	 * @return array Gebruik related to the node.
	 */
	private function getNodeGebruik(string $modelNodeId, array $gebruikData): array {
		$this->logger->debug('Getting gebruik for node', ['model_node_id' => $modelNodeId]);

		// Direct lookup by elementRef.
		if (isset($gebruikData[$modelNodeId]) === true) {
			$gebruikList = $gebruikData[$modelNodeId];

			$this->logger->debug(
				'Found gebruik for node',
				[
					'model_node_id' => $modelNodeId,
					'gebruik_count' => count($gebruikList),
				]
			);

			return $gebruikList;
		}

		// No usage data found for this node.
		return [];
	}//end getNodeGebruik()

	/**
	 * Get deelnames gebruik for a specific node based on elementRef linkage.
	 *
	 * @param string $modelNodeId The model node identifier (elementRef).
	 * @param array $deelnamesGebruikData Deelnames gebruik data indexed by elementRef.
	 *
	 * @return array Deelnames gebruik related to the node.
	 */
	private function getNodeDeelnamesGebruik(string $modelNodeId, array $deelnamesGebruikData): array {
		$this->logger->debug('Getting deelnames gebruik for node', ['model_node_id' => $modelNodeId]);

		if (isset($deelnamesGebruikData[$modelNodeId]) === true) {
			$deelnamesList = $deelnamesGebruikData[$modelNodeId];

			$this->logger->debug(
				'Found deelnames gebruik for node',
				[
					'model_node_id' => $modelNodeId,
					'deelnames_count' => count($deelnamesList),
				]
			);

			return $deelnamesList;
		}

		return [];
	}//end getNodeDeelnamesGebruik()

	/**
	 * Get ObjectService from container.
	 *
	 * @return ObjectServiceInterface|null ObjectService instance or null if not available.
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			// Ask for the CONTRACT, not the concrete class. This method
			// declares `?ObjectServiceInterface`, and resolving the concrete
			// ObjectService returns something that only satisfies it by
			// coincidence — under test it is a double of the concrete class,
			// which does not implement the interface, so the declared return
			// type rejected it.
			return $this->container->get(ObjectServiceInterface::class);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to get ObjectService',
				[
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Transform multiple views to include critical API fields.
	 *
	 * @param array $views Array of view objects.
	 *
	 * @return array Array of transformed view objects.
	 */
	private function transformViews(array $views): array {
		$transformedViews = [];

		foreach ($views as $view) {
			$transformedViews[] = $this->transformView(view: $view);
		}

		return $transformedViews;
	}//end transformViews()

	/**
	 * Transform a single view to include critical API fields.
	 *
	 * @param array $view View object to transform.
	 *
	 * @return array Transformed view object with critical API fields.
	 */
	private function transformView(array $view): array {
		$transformedView = $view;

		// Add view documentation from XML if available.
		if (isset($view['xml']['documentation']) === true) {
			$transformedView['documentation'] = $view['xml']['documentation'];
		}

		return $this->transformXmlSections(view: $transformedView);
	}//end transformView()

	/**
	 * Transform the XML properties, viewNodes and viewRelationships that are lists.
	 *
	 * @param array $view View object to transform.
	 *
	 * @return array The view with the critical API fields of each XML section.
	 */
	private function transformXmlSections(array $view): array {
		// Transform properties from XML to required API format.
		$properties = $view['xml']['properties']['property'] ?? null;
		if (is_array(value: $properties) === true) {
			$view['properties'] = $this->transformViewProperties(properties: $properties);
		}

		// Transform viewNodes to include critical fields.
		$viewNodes = $view['xml']['viewNodes'] ?? null;
		if (is_array(value: $viewNodes) === true) {
			$view['viewNodes'] = $this->transformViewNodes(viewNodes: $viewNodes);
		}

		// Transform viewRelationships to include critical fields.
		$relationships = $view['xml']['viewRelationships'] ?? null;
		if (is_array(value: $relationships) === true) {
			$view['viewRelationships'] = $this->transformViewRelationships(viewRelationships: $relationships);
		}

		return $view;
	}//end transformXmlSections()

	/**
	 * Transform view properties to required API format.
	 *
	 * @param array $properties Array of property objects from XML.
	 *
	 * @return array Array of transformed properties.
	 */
	private function transformViewProperties(array $properties): array {
		$transformedProperties = [];

		foreach ($properties as $property) {
			$propertyRef = $property['_propertyDefinitionRef'] ?? $property['___propertyDefinitionRef'] ?? null;
			$transformedProperty = [
				'propertyDefinitionRef' => $propertyRef,
				'value' => $property['value']['_value'] ?? null,
			];

			if ($transformedProperty['propertyDefinitionRef'] !== null && $transformedProperty['value'] !== null) {
				$transformedProperties[] = $transformedProperty;
			}
		}

		return $transformedProperties;
	}//end transformViewProperties()

	/**
	 * Transform viewNodes to include critical API fields.
	 *
	 * @param array $viewNodes Array of viewNode objects from XML.
	 *
	 * @return array Array of transformed viewNodes.
	 */
	private function transformViewNodes(array $viewNodes): array {
		$transformedNodes = [];

		foreach ($viewNodes as $node) {
			// Keep all existing fields.
			$transformedNode = $node;
			// Add critical API fields.
			$transformedNode['identifier'] = $node['viewNodeId'] ?? null;
			$transformedNode['documentation'] = $node['description'] ?? null;

			// Add position with width and height.
			$transformedNode['position'] = [
				'x' => $node['x'] ?? null,
				'y' => $node['y'] ?? null,
				'w' => $node['width'] ?? null,
				'h' => $node['height'] ?? null,
			];

			// Add style information.
			if (isset($node['color']) === true || isset($node['borderColor']) === true || isset($node['font']) === true) {
				$transformedNode['style'] = [
					'fillColor' => $this->parseColor(colorString: $node['color'] ?? null),
					'lineColor' => $this->parseColor(colorString: $node['borderColor'] ?? null),
					'font' => $node['font'] ?? null,
				];
			}

			$transformedNodes[] = $transformedNode;
		}//end foreach

		return $transformedNodes;
	}//end transformViewNodes()

	/**
	 * Transform viewRelationships to include critical API fields.
	 *
	 * @param array $viewRelationships Array of viewRelationship objects from XML.
	 *
	 * @return array Array of transformed viewRelationships.
	 */
	private function transformViewRelationships(array $viewRelationships): array {
		$transformedRelationships = [];

		foreach ($viewRelationships as $relationship) {
			// Keep all existing fields.
			$transformedRelationship = $relationship;
			// Add critical API fields.
			$transformedRelationship['identifier'] = $relationship['viewRelationshipId'] ?? null;

			// Add properties if available (check for relationship properties).
			// Default: create properties array with relationship name if available.
			$properties = [];
			if (isset($relationship['label']) === true) {
				$properties[] = [
					'propertyDefinitionRef' => 'propid-62',
					'value' => $relationship['label'],
				];
			}

			$transformedRelationship['properties'] = $properties;
			if (isset($relationship['properties']) === true) {
				$transformedRelationship['properties'] = $relationship['properties'];
			}

			// Ensure bendpoints are properly formatted.
			$transformedRelationship['bendpoints'] = $relationship['bendpoints'] ?? [];

			$transformedRelationships[] = $transformedRelationship;
		}//end foreach

		return $transformedRelationships;
	}//end transformViewRelationships()

	/**
	 * Parse color string to extract RGB values.
	 *
	 * @param string|null $colorString Color string in format "rgb(r, g, b)" or null.
	 *
	 * @return array|null Array with r, g, b, a values or null.
	 */
	private function parseColor(?string $colorString): ?array {
		if ($colorString === null) {
			return null;
		}

		// Handle rgb(r, g, b) format.
		if (preg_match(pattern: '/rgb\((\d+),\s*(\d+),\s*(\d+)\)/', subject: $colorString, matches: $matches) === 1) {
			return [
				'r' => (int)$matches[1],
				'g' => (int)$matches[2],
				'b' => (int)$matches[3],
				'a' => 100,
			];
		}

		return null;
	}//end parseColor()
}//end class
