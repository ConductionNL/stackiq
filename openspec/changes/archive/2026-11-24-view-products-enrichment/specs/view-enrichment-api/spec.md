## MODIFIED Requirements

### Requirement: Product filter enrichment MUST return real product data
When `include_products=true` is set on a view fetch, the response's per-node `products` field MUST reflect actual product entities linked to that node's ArchiMate model element — not an always-empty placeholder.
`ViewService::getProductsData()` MUST query the organisation-scoped product data via OpenRegister's `ObjectService` (ADR-022), and `ViewService::getNodeProducts()` MUST match that data against the node's linkage field before returning it.

#### Scenario: Product filter is enabled and linked products exist
- GIVEN a view whose model contains a node with one or more linked product
  entities
- AND the user enables the "Product" filter toggle
- WHEN `GET /stackiq/api/views/{viewId}?include_products=true` is
  called
- THEN the response MUST include `products` on that node
- AND `products` MUST contain the actual linked product entities (not an
  empty array)
- AND `available_products_count` MUST equal the number of matched products

#### Scenario: Product filter is enabled and no products are linked
- GIVEN a view whose model contains a node with no linked product entities
- AND the user enables the "Product" filter toggle
- WHEN the view is fetched with `include_products=true`
- THEN the response MUST include `products` on that node as an empty array
- AND `available_products_count` MUST be 0

#### Scenario: Product filter is enabled but no product schema is configured
- GIVEN the instance has no product-equivalent register/schema configured
- WHEN the view is fetched with `include_products=true`
- THEN the response MUST still succeed (200)
- AND every node's `products` MUST be an empty array
- AND the condition MUST be logged, not thrown, as a graceful degradation
