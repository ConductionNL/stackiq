# Koppelingen-Gebruik API Test Matrix

## Test Coverage Overview

This document outlines the comprehensive test matrix for the Koppelingen-Gebruik API endpoints, covering all possible scenario combinations across 3 organisations.

## Test Organisations

| Organisation | Role | Owns Products | Uses Products | Expected Access |
|--------------|------|---------------|---------------|-----------------|
| **A** | Product Owner | Yes (Product1, Module1) | Yes | Full access to all usage of owned products |
| **B** | Product User | No | Yes (uses A's products) | No special access (unless ambtenaar) |
| **C** | Isolated | No | No | No special access (unless ambtenaar) |

## Test Users

| User | Role | Organisation | Group | Expected Access Level |
|------|------|--------------|-------|----------------------|
| **Admin** | Ambtenaar | N/A | `ambtenaar` | Full access to all objects across all orgs |
| **OrgA Member** | Regular User | A | - | Can see all usage of A's products (cross-org) |
| **OrgB Member** | Regular User | B | - | Can only see B's own objects |
| **OrgC Member** | Regular User | C | - | Can only see C's own objects |

## Test Objects Created

### Products & Modules
- **Product1**: Owned by Org A
- **Module1**: Owned by Org A

### Gebruiks (Usage Objects)
- **Product1 Gebruik by Org A**: `@self.organisation = A`
- **Product1 Gebruik by Org B**: `@self.organisation = B`
- **Module1 Gebruik by Org A**: `@self.organisation = A`
- **Module1 Gebruik by Org B**: `@self.organisation = B`

### Koppelingen (Connection Objects)
- **Product1 Koppeling by Org A**: `@self.organisation = A`
- **Module1 Koppeling by Org A**: `@self.organisation = A`

## Comprehensive Test Matrix

### Scenario 1: Organisation UUID Requests

#### Test: GET /api/koppelingen-gebruik/{orgA-uuid}

| User | Expected Result | Count | Organisations Visible |
|------|----------------|-------|----------------------|
| Admin (ambtenaar) | All Org A objects | 4 | A only |
| OrgA Member | All Org A objects | 4 | A only |
| OrgB Member | Empty (no access) | 0 | None |
| OrgC Member | Empty (no access) | 0 | None |

#### Test: GET /api/koppelingen-gebruik/{orgB-uuid}

| User | Expected Result | Count | Organisations Visible |
|------|----------------|-------|----------------------|
| Admin (ambtenaar) | All Org B objects | 2 | B only |
| OrgA Member | Empty (not B owner) | 0 | None |
| OrgB Member | All Org B objects | 2 | B only |
| OrgC Member | Empty (no access) | 0 | None |

#### Test: GET /api/koppelingen-gebruik/{orgC-uuid}

| User | Expected Result | Count | Organisations Visible |
|------|----------------|-------|----------------------|
| Admin (ambtenaar) | All Org C objects | 0 | None (C has no objects) |
| OrgA Member | Empty (not C owner) | 0 | None |
| OrgB Member | Empty (not C owner) | 0 | None |
| OrgC Member | All Org C objects | 0 | None (C has no objects) |

### Scenario 2: Product UUID Requests

#### Test: GET /api/koppelingen-gebruik/{product1-uuid}

| User | Expected Result | Count | Organisations Visible | Reason |
|------|----------------|-------|----------------------|---------|
| Admin (ambtenaar) | All usage of Product1 | 3 | A, B | Ambtenaar sees everything |
| OrgA Member | All usage of Product1 | 3 | A, B | **A owns Product1** → cross-org access |
| OrgB Member | Empty (not owner) | 0 | None | B doesn't own Product1 |
| OrgC Member | Empty (no access) | 0 | None | C has no relation to Product1 |

**Objects Returned**:
- Product1 Gebruik by Org A
- Product1 Gebruik by Org B
- Product1 Koppeling by Org A

### Scenario 3: Module UUID Requests

#### Test: GET /api/koppelingen-gebruik/{module1-uuid}

| User | Expected Result | Count | Organisations Visible | Reason |
|------|----------------|-------|----------------------|---------|
| Admin (ambtenaar) | All usage of Module1 | 3 | A, B | Ambtenaar sees everything |
| OrgA Member | All usage of Module1 | 3 | A, B | **A owns Module1** → cross-org access |
| OrgB Member | Empty (not owner) | 0 | None | B doesn't own Module1 |
| OrgC Member | Empty (no access) | 0 | None | C has no relation to Module1 |

**Objects Returned**:
- Module1 Gebruik by Org A
- Module1 Gebruik by Org B
- Module1 Koppeling by Org A

### Scenario 4: Ambtenaar with Organisation Filter

#### Test: GET /api/koppelingen-gebruik/{product1-uuid}?organisation={orgB-uuid}

| User | Expected Result | Count | Organisations Visible |
|------|----------------|-------|----------------------|
| Admin (ambtenaar) | Only Org B usage of Product1 | 1 | B only |
| OrgA Member | Ignored (not ambtenaar) | 3 | A, B (filter ignored) |
| OrgB Member | Empty (not owner) | 0 | None |

**Objects Returned** (for ambtenaar):
- Product1 Gebruik by Org B

### Scenario 5: Pagination

#### Test: GET /api/koppelingen-gebruik/{product1-uuid}?_limit=2

| User | Expected Result | Count | Limit Applied |
|------|----------------|-------|---------------|
| Admin (ambtenaar) | First 2 of all usage | 2 | Yes |
| OrgA Member | First 2 of all usage | 2 | Yes |
| OrgB Member | Empty | 0 | N/A |

### Scenario 6: Invalid/Non-existent UUID

#### Test: GET /api/koppelingen-gebruik/00000000-0000-0000-0000-000000000000

| User | Expected Result | Count | HTTP Status |
|------|----------------|-------|-------------|
| All users | Empty results | 0 | 200 OK |

## Access Control Summary

### Key Access Rules

1. **Ambtenaar Users** (admin, ambtenaar group):
   - ✅ See ALL objects regardless of organisation
   - ✅ Can filter by organisation parameter
   - ✅ Full cross-organisation visibility

2. **Product/Module Owners**:
   - ✅ See ALL usage of their owned products/modules
   - ✅ Includes usage by OTHER organisations
   - ❌ Cannot see usage of products they don't own
   - 🎯 **Key Feature**: Vendors can monitor adoption across municipalities

3. **Regular Organisation Members**:
   - ✅ Can request their own organisation's UUID
   - ❌ Cannot see other organisations' data
   - ❌ Cannot see usage of products unless they own the product

4. **Organisation Filter Parameter**:
   - ✅ Only works for ambtenaar users
   - ❌ Ignored for regular users

## Implementation Coverage

### Existing Tests (8 tests)
1. ✅ `testGetKoppelingenGebruikForProductUuid` - Product UUID basic access
2. ✅ `testGetKoppelingenGebruikForModuleUuid` - Module UUID basic access
3. ⚠️ `testGetKoppelingenGebruikForOrganisationUuid` - Organisation UUID (needs 3 org coverage)
4. ⚠️ `testAmbtenaarAccessToAllOrganisations` - Cross-org ambtenaar (needs 3 org coverage)
5. ✅ `testPaginationParameters` - Pagination
6. ✅ `testResponseFormatConsistency` - Response structure
7. ✅ `testInvalidUuidReturnsEmptyResults` - Error handling
8. ✅ `testOrganisationOwnerAccessToOwnedProductUsage` - Owner cross-org access

### Recommended Additional Tests
1. `testOrganisationCIsolation` - Verify Org C has no access to A/B data
2. `testAmbtenaarOrganisationFilter` - Test organisation filter parameter
3. `testNonOwnerCannotAccessProductUsage` - Verify Org B member cannot see Product1 usage
4. `testThreeOrganisationScenarios` - Comprehensive 3-org matrix test

## Edge Cases

### Edge Case 1: Organisation owns product but has no usage objects
- **Scenario**: Org A owns Product1 but hasn't created any gebruiks for it
- **Expected**: Org A member can still query Product1 UUID (returns empty or only other org's usage)

### Edge Case 2: Product used by multiple organisations
- **Scenario**: Product1 used by both A and B
- **Expected**: Product owner (A) sees all usage from A and B

### Edge Case 3: Ambtenaar filtering by non-existent organisation
- **Scenario**: Admin queries with `?organisation=invalid-uuid`
- **Expected**: Empty results (no error)

### Edge Case 4: User not belonging to any organisation
- **Scenario**: User without organisation requests any UUID
- **Expected**: Empty results (unless ambtenaar)

## Test Data Consistency Requirements

1. **@self.organisation must be set correctly**:
   - OpenRegister auto-assigns to creator's org
   - Tests must UPDATE objects after creation to set correct org

2. **Relation fields must reference valid UUIDs**:
   - `product` field → Product UUID
   - `module` field → Module UUID
   - `afnemer` field → Organisation UUID

3. **Schema verification**:
   - Gebruik objects → schema 16
   - Koppeling objects → schema 18
   - Product objects → schema 11
   - Module objects → schema 25
   - Organisation objects → schema 15

## Performance Considerations

- **Single Query**: Both gebruiks and koppelingen retrieved in one `searchObjectsPaginated` call
- **Efficient Filtering**: Multi-schema array notation `[@self.schema: [16, 18]]`
- **Pagination**: Proper limit/offset prevents memory issues with large datasets
- **Organisation Detection**: One `find()` call to check if UUID is an organisation

## Security Validation

- ✅ RBAC bypassed intentionally (with `rbac: false`)
- ✅ Custom access control implemented in service layer
- ✅ Organisation boundaries respected for regular users
- ✅ Cross-org visibility only for product owners and ambtenaar
- ✅ No data leakage to unauthorised users

## Maintenance Notes

- **When adding new object types**: Update test data creation
- **When changing access rules**: Update test assertions
- **When adding organisations**: Update test matrix
- **Before production**: Run full test suite with real-world data volumes

