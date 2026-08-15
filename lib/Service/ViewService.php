<?php

/**
 * View Service for SoftwareCatalog
 *
 * Handles view-specific operations including querying, enrichment with products,
 * and usage data (gebruik) integration.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
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
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
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
		$this->viewsCache = $cacheFactory->createDistributed(prefix: 'softwarecatalog_views');
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
	 * Results are cached for 30 minutes to prevent cold-start delays.
	 *
	 * @return array Array of view objects
	 */
	private function getViewsFromRegister(): array {
		// Check cache first.
		$cacheKey = 'views_list';
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
			// Get specific view object by ID.
			$view = $objectService->find(
				id: $viewId,
				register: $registerId,
				schema: $viewSchemaId,
				_rbac: false,
				_multitenancy: false
			);

			// Serialize ObjectEntity to array if needed.
			if (is_array(value: $view) === false
				&& $view !== null
				&& method_exists(object_or_class: $view, method: 'jsonSerialize') === true
			) {
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
		$enrichedNodes = [];

		// Get enrichment data upfront if requested.
		$productsData = [];
		$modulesData = [];
		$gebruikData = [];
		$deelnamesGebruikData = [];

		if ($this->shouldIncludeProducts(options: $options) === true) {
			$productsData = $this->getProductsData();
		}

		if ($this->shouldIncludeModules(options: $options) === true) {
			$modulesData = $this->getModulesData();
		}

		if ($this->shouldIncludeGebruik(options: $options) === true) {
			$gebruikData = $this->getGebruikData();
		}

		if ($this->shouldIncludeDeelnamesGebruik(options: $options) === true) {
			$deelnamesGebruikData = $this->getDeelnamesGebruikData();
		}

		// Deduplicate: owned gebruik takes precedence over deelnames for the same elementRef.
		if (empty($gebruikData) === false && empty($deelnamesGebruikData) === false) {
			$deelnamesGebruikData = array_diff_key($deelnamesGebruikData, $gebruikData);
		}

		foreach ($viewNodes as $node) {
			$enrichedNode = $node;

			// Apply enrichments based on the node's modelNodeId (element reference).
			$modelNodeId = $node['modelNodeId'] ?? null;

			if ($modelNodeId !== null) {
				if ($this->shouldIncludeProducts(options: $options) === true) {
					$enrichedNode['products'] = $this->getNodeProducts(
						modelNodeId: $modelNodeId,
						productsData: $productsData
					);
				}

				if ($this->shouldIncludeModules(options: $options) === true) {
					$enrichedNode['modules'] = $this->getNodeModules(
						modelNodeId: $modelNodeId,
						modulesData: $modulesData
					);
				}

				if ($this->shouldIncludeGebruik(options: $options) === true) {
					$enrichedNode['usage'] = $this->getNodeGebruik(
						modelNodeId: $modelNodeId,
						gebruikData: $gebruikData
					);
				}

				if ($this->shouldIncludeDeelnamesGebruik(options: $options) === true) {
					$enrichedNode['deelnamesGebruik'] = $this->getNodeDeelnamesGebruik(
						modelNodeId: $modelNodeId,
						deelnamesGebruikData: $deelnamesGebruikData
					);
				}
			}//end if

			$enrichedNodes[] = $enrichedNode;
		}//end foreach

		return $enrichedNodes;
	}//end enrichViewNodes()

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
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [];
			}

			// Use voorzieningen config for the correct dienst register and schema.
			$voorzConfig = $this->settingsService->getVoorzieningenConfig();
			$registerId = $voorzConfig['register'] ?? null;
			$dienstSchemaId = $voorzConfig['dienst_schema'] ?? null;

			if ($registerId === null || $dienstSchemaId === null || $dienstSchemaId === '') {
				$this->logger->warning(message: 'Voorzieningen register or dienst schema not configured for products');
				return [];
			}

			$currentOrg = $this->getCurrentOrganisation();

			$query = [
				'@self' => [
					'register' => $registerId,
					'schema' => $dienstSchemaId,
				],
				'_limit' => 500,
			];

			// Add organisation filter if current organisation is available.
			if ($currentOrg !== null) {
				$query['@self']['organisation'] = $currentOrg;
			}

			$products = $objectService->searchObjects($query);

			$allProducts = [];
			foreach ($products as $product) {
				// Additional check for organisation in metadata if not caught by query.
				$productOrg = $product['@self']['organisation'] ?? null;
				$hasOrgMismatch = $currentOrg !== null
					&& isset($product['@self']['organisation']) === true
					&& $productOrg !== $currentOrg;
				if ($hasOrgMismatch === true) {
					continue;
				}

				// Index by elementRef or identifier for quick lookup.
				$elementRef = $product['elementRef'] ?? $product['identifier'] ?? null;
				if ($elementRef !== null) {
					$allProducts[$elementRef] = $product;
				}
			}

			$this->logger->debug(
				'Total products retrieved for enrichment',
				['total_products' => count($allProducts)]
			);

			return $allProducts;
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get products data',
				['error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end getProductsData()

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
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [];
			}

			// Get current organisation for filtering.
			$currentOrg = $this->getCurrentOrganisation();

			// Get configuration for modules register/schema.
			$amefConfig = $this->settingsService->getAmefConfig();
			$registerId = $amefConfig['register_id'] ?? null;

			// Fail closed on an unconfigured register. This used to be the one
			// read of `register_id` with no guard at all: the loop below only
			// checks the SCHEMA, so an empty register would have been pinned
			// into `@self` and OpenRegister asked for "any register" — an
			// unpinned query returns rows, which reads exactly like a correct
			// result. `empty()` rather than `=== null` because the legacy
			// config fallback resolves unset ids to `''`, and `'' === null` is
			// false.
			if (empty($registerId) === true) {
				$this->logger->warning(
					'ViewService: AMEF register is not configured; skipping the module lookup rather than issuing an unpinned query'
				);

				return [];
			}

			// Modules could be in various schemas - check common ones.
			$moduleSchemas = [
				$amefConfig['module_schema'] ?? null,
				$amefConfig['component_schema'] ?? null,
				$amefConfig['element_schema'] ?? null,
			];

			$allModules = [];

			foreach ($moduleSchemas as $schemaId) {
				if (empty($schemaId) === true) {
					continue;
				}

				try {
					// Builds a lookup index (O(1) node matching), so this
					// intentionally fetches "all matching" — bounded at a
					// documented safe ceiling rather than left unbounded.
					$query = [
						'@self' => [
							'register' => $registerId,
							'schema' => $schemaId,
						],
						'_limit' => 1000,
					];

					// Add organisation filter if current organisation is available.
					if ($currentOrg !== null) {
						$query['@self']['organisation'] = $currentOrg;
					}

					$modules = $objectService->searchObjects($query);

					foreach ($modules as $module) {
						// Additional check for organisation in metadata if not caught by query.
						$moduleOrg = $module['@self']['organisation'] ?? null;
						$hasOrgMismatch = $currentOrg !== null
							&& isset($module['@self']['organisation']) === true
							&& $moduleOrg !== $currentOrg;
						if ($hasOrgMismatch === true) {
							continue;
						}

						// Index by elementRef or identifier for quick lookup.
						$elementRef = $module['elementRef'] ?? $module['identifier'] ?? null;
						if ($elementRef !== null) {
							$allModules[$elementRef] = $module;
						}
					}

					$this->logger->debug(
						'Retrieved modules from schema',
						[
							'schema_id' => $schemaId,
							'modules_count' => count($modules),
						]
					);
				} catch (\Exception $e) {
					$this->logger->warning(
						'Failed to get modules from schema',
						[
							'schema_id' => $schemaId,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach

			$this->logger->debug(
				'Total modules retrieved for enrichment',
				[
					'total_modules' => count($allModules),
				]
			);

			return $allModules;
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get modules data',
				[
					'error' => $e->getMessage(),
				]
			);
			return [];
		}//end try
	}//end getModulesData()

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

		foreach ($views as &$view) {
			if (isset($view['viewNodes']) === false || is_array(value: $view['viewNodes']) === false) {
				continue;
			}

			$originalNodes = $view['viewNodes'];
			$expandedNodes = $originalNodes;

			// Create a lookup of existing nodes by modelNodeId for quick parent matching.
			$nodesByModelId = [];
			foreach ($originalNodes as $node) {
				$modelNodeId = $node['modelNodeId'] ?? null;
				if ($modelNodeId !== null) {
					$nodesByModelId[$modelNodeId] = $node;
				}
			}

			// Process each module for expansion.
			foreach ($modulesData as $elementRef => $module) {
				$expandedNodes = $this->expandModuleToNodes(
					module: $module,
					existingNodes: $expandedNodes,
					nodesByModelId: $nodesByModelId
				);
			}

			// Update the view with expanded nodes.
			$view['viewNodes'] = $expandedNodes;

			$addedNodesCount = count($expandedNodes) - count($originalNodes);
			if ($addedNodesCount > 0) {
				$this->logger->debug(
					'Added module nodes to view',
					[
						'view_id' => $view['id'] ?? 'unknown',
						'original_nodes' => count($originalNodes),
						'expanded_nodes' => count($expandedNodes),
						'added_nodes' => $addedNodesCount,
					]
				);
			}
		}//end foreach

		// Clean up reference.
		unset($view);
		return $views;
	}//end expandModulesToViewNodes()

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
		$possibleFields = [
			'referentiecomponenten',
			'referenceComponents',
			'relatedComponents',
			'components',
			'relationships',
		];

		foreach ($possibleFields as $field) {
			if (isset($module[$field]) === true) {
				$value = $module[$field];

				if (is_array(value: $value) === true) {
					// Handle array of components.
					foreach ($value as $component) {
						if (is_string(value: $component) === true) {
							$referenceComponents[] = $component;
						} elseif (is_array(value: $component) === true && isset($component['id']) === true) {
							$referenceComponents[] = $component['id'];
						} elseif (is_array(value: $component) === true && isset($component['identifier']) === true) {
							$referenceComponents[] = $component['identifier'];
						}
					}
				} elseif (is_string(value: $value) === true) {
					// Handle single component ID.
					$referenceComponents[] = $value;
				}
			}
		}//end foreach

		return array_unique($referenceComponents);
	}//end extractReferentieComponenten()

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
	 * @return ObjectService|null ObjectService instance or null if not available.
	 */
	private function getObjectService(): ?ObjectService {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			return $this->container->get(ObjectService::class);
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

		// Transform properties from XML to required API format.
		$hasProperties = isset($view['xml']['properties']['property']) === true
			&& is_array(value: $view['xml']['properties']['property']) === true;
		if ($hasProperties === true) {
			$transformedView['properties'] = $this->transformViewProperties(
				properties: $view['xml']['properties']['property']
			);
		}

		// Transform viewNodes to include critical fields.
		$hasViewNodes = isset($view['xml']['viewNodes']) === true
			&& is_array(value: $view['xml']['viewNodes']) === true;
		if ($hasViewNodes === true) {
			$transformedView['viewNodes'] = $this->transformViewNodes(
				viewNodes: $view['xml']['viewNodes']
			);
		}

		// Transform viewRelationships to include critical fields.
		$hasRelationships = isset($view['xml']['viewRelationships']) === true
			&& is_array(value: $view['xml']['viewRelationships']) === true;
		if ($hasRelationships === true) {
			$transformedView['viewRelationships'] = $this->transformViewRelationships(
				viewRelationships: $view['xml']['viewRelationships']
			);
		}

		return $transformedView;
	}//end transformView()

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
