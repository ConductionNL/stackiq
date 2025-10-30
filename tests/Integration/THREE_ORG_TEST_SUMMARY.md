# Three-Organisation Test Matrix Implementation Summary

## Overview

This update expands the integration test suite from **2 organisations** to **3 organisations**, creating a comprehensive test matrix that covers all possible access control scenarios for the Koppelingen-Gebruik API.

## Changes Made

### 1. Organisation Structure Enhancement

**Before (2 orgs)**:
- Organisation A: Owns products/modules
- Organisation B: Different org (uses products)

**After (3 orgs)**:
- **Organisation A**: Owns products/modules (Product Owner/Vendor)
- **Organisation B**: Uses products but doesn't own them (Product Consumer)
- **Organisation C**: Isolated, no involvement with products/modules (Control Group)

### 2. User Structure Enhancement

**Before**:
- Admin (ambtenaar)
- Org Member (Org A)
- Other Org User (Org B)

**After**:
- **Admin (ambtenaar)**: Full cross-org access
- **Org Member (Org A)**: Can see all usage of owned products
- **Other Org User (Org B)**: Standard org member access
- **Third Org User (Org C)**: Isolated org member (new)

### 3. Test Data Structure

**Products & Modules**:
```
Product1 (owned by Org A)
  ├─ Product Gebruik by Org A
  ├─ Product Gebruik by Org B
  └─ Product Koppeling by Org A

Module1 (owned by Org A)
  ├─ Module Gebruik by Org A
  ├─ Module Gebruik by Org B
  └─ Module Koppeling by Org A

Organisation C: No objects (control group)
```

### 4. New Test Files

#### `tests/Integration/TEST_MATRIX.md`
Comprehensive documentation of all test scenarios including:
- User/Organisation/UUID combinations
- Expected access control outcomes
- Edge cases and security validations
- Performance and maintenance notes

### 5. Code Changes

#### `KoppelingenGebruikIntegrationTest.php`

**Properties Added**:
```php
private ?array $organisationC = null;
private ?string $thirdOrgUser = null;
```

**Methods Updated**:
1. `createTestOrganisations()`: Now creates 3 organisations
2. `createTestUsers()`: Now creates user for Org C
3. `createObject()`: Fixed to properly set `@self.organisation` via UPDATE after creation

**New Test Method**:
```php
testThreeOrganisationAccessControlMatrix()
```
Validates:
- Admin sees multiple organisations ✓
- Org C isolation (empty results) ✓
- Organisation filter for ambtenaar ✓
- Cross-org access for product owners ✓

### 6. Critical Fix: @self.organisation Assignment

**Problem Discovered**:
OpenRegister automatically assigns `@self.organisation` to the **creator's organisation** when objects are created, ignoring the `organisation` field in the data payload.

**Solution Implemented**:
```php
private function createObject(array $data, string $registerId, string $schemaId): array
{
    // Extract target organisation
    $targetOrganisation = $data['organisation'] ?? null;
    
    // Create object (auto-assigned to creator's org)
    $response = $this->client->post(...);
    $object = json_decode($response->getBody()->getContents(), true);
    
    // UPDATE object to set correct @self.organisation
    if ($targetOrganisation && isset($object['@self'])) {
        $object['@self']['organisation'] = $targetOrganisation;
        $updateResponse = $this->client->put(...);
        $object = json_decode($updateResponse->getBody()->getContents(), true);
    }
    
    return $object;
}
```

## Test Coverage Matrix

### Total Test Scenarios: 9 Tests

| # | Test Name | Coverage |
|---|-----------|----------|
| 1 | testGetKoppelingenGebruikForProductUuid | Product UUID access |
| 2 | testGetKoppelingenGebruikForModuleUuid | Module UUID access |
| 3 | testGetKoppelingenGebruikForOrganisationUuid | Org UUID filtering |
| 4 | testAmbtenaarAccessToAllOrganisations | Cross-org ambtenaar |
| 5 | testPaginationParameters | Pagination |
| 6 | testResponseFormatConsistency | API structure |
| 7 | testInvalidUuidReturnsEmptyResults | Error handling |
| 8 | testOrganisationOwnerAccessToOwnedProductUsage | Owner cross-org |
| 9 | **testThreeOrganisationAccessControlMatrix** | 3-org comprehensive *(new)* |

### Organisation Coverage

| Organisation | Created By | Objects Created | Use Case |
|--------------|-----------|-----------------|----------|
| **A** | Test Setup | 2 products, 2 modules, 4 gebruiks, 2 koppelingen | Product Owner |
| **B** | Test Setup | 2 gebruiks (uses A's products) | Product Consumer |
| **C** | Test Setup | 0 objects | Isolated/Control |

### UUID Type Coverage

| UUID Type | Test Count | Scenarios Tested |
|-----------|-----------|------------------|
| Organisation UUID | 3 | A, B, C isolation |
| Product UUID | 4 | Owner, consumer, ambtenaar, filter |
| Module UUID | 4 | Owner, consumer, ambtenaar, filter |
| Invalid UUID | 1 | Error handling |

## Access Control Validation

### Scenario Results (Expected)

#### 1. Organisation UUID Requests

| Requester | Org A | Org B | Org C |
|-----------|-------|-------|-------|
| Admin | ✅ See A | ✅ See B | ✅ Empty (C has no objects) |
| Org A Member | ✅ See A | ❌ No access | ❌ No access |
| Org B Member | ❌ No access | ✅ See B | ❌ No access |
| Org C Member | ❌ No access | ❌ No access | ✅ Empty (C has no objects) |

#### 2. Product UUID Requests (Product owned by Org A)

| Requester | Can See |
|-----------|---------|
| Admin (ambtenaar) | ✅ All usage (A + B) |
| Org A Member | ✅ All usage (A + B) - **Owns product** |
| Org B Member | ❌ None - **Not owner** |
| Org C Member | ❌ None - **No relation** |

#### 3. Module UUID Requests (Module owned by Org A)

| Requester | Can See |
|-----------|---------|
| Admin (ambtenaar) | ✅ All usage (A + B) |
| Org A Member | ✅ All usage (A + B) - **Owns module** |
| Org B Member | ❌ None - **Not owner** |
| Org C Member | ❌ None - **No relation** |

## Key Insights from 3-Org Testing

### 1. **Isolation Testing**
Organisation C serves as a **control group** to verify:
- No data leakage between unrelated organisations
- Proper boundary enforcement
- Empty result handling

### 2. **Cross-Organisation Visibility**
The 3-org setup clearly demonstrates:
- **Vendors (Org A)** can track product adoption across municipalities
- **Consumers (Org B)** cannot see other consumers' data
- **Isolated orgs (Org C)** remain completely separate

### 3. **Ambtenaar Oversight**
With 3 organisations, ambtenaar users can:
- View data across all 3 organisations
- Filter by specific organisation
- Monitor system-wide usage patterns

## Benefits of 3-Organisation Testing

### 1. **Comprehensive Coverage**
- Tests not just "owner vs consumer" but also "isolated entities"
- Validates N>2 scenarios that are closer to production reality

### 2. **Edge Case Detection**
- Organisation with zero objects
- Multiple organisations not involved with a product
- Filtering scenarios with 3+ options

### 3. **Real-World Simulation**
Production systems have dozens of organisations:
- Product vendors (like Org A)
- Multiple product consumers (like Org B)
- Organisations without certain products (like Org C)

### 4. **Regression Prevention**
- Ensures logic works with >2 organisations
- Prevents hardcoded assumptions about org count
- Tests array operations with varied data sizes

## Running the Tests

```bash
# Run all Koppelingen-Gebruik tests
docker exec -u 33 master-nextcloud-1 bash -c \
  "cd /var/www/html/apps-extra/softwarecatalog && \
   vendor/bin/phpunit tests/Integration/KoppelingenGebruikIntegrationTest.php --testdox"

# Run only the comprehensive 3-org matrix test
docker exec -u 33 master-nextcloud-1 bash -c \
  "cd /var/www/html/apps-extra/softwarecatalog && \
   vendor/bin/phpunit tests/Integration/KoppelingenGebruikIntegrationTest.php \
   --filter testThreeOrganisationAccessControlMatrix --testdox"
```

## Expected Test Results

**Total: 9 tests**
- ✅ All 9 should pass once `@self.organisation` fix is applied
- 🕐 Estimated runtime: ~3-4 minutes (creates 3 orgs + users + products)
- 📦 Objects created: ~20-25 test objects per run
- 🧹 Cleanup: All objects deleted in `tearDown()`

## Next Steps

1. **Run Tests**: Execute the test suite to validate all scenarios
2. **Document Results**: Add test results to this summary
3. **Performance Testing**: Test with larger datasets (10+ organisations)
4. **User Documentation**: Update API docs to reflect 3-org examples

## Maintenance

### When to Update This Test

- ✏️ **Access rules change**: Update expected outcomes in assertions
- ➕ **New object types added**: Add to test data creation
- 🏢 **Organisation model changes**: Update createTestOrganisations()
- 👤 **User roles change**: Update createTestUsers()

### Code Quality

- ✅ All methods have docblocks
- ✅ Return types specified
- ✅ Type hints used
- ✅ PSR-12 compliant
- ✅ PHPStan level 6 compatible

## Related Files

- `lib/Controller/AangebodenGebruikController.php`: Endpoint handlers
- `lib/Service/AangebodenGebruikService.php`: Business logic
- `appinfo/routes.php`: Route definitions
- `website/docs/aangeboden-gebruik-api.md`: User documentation
- `tests/Integration/TEST_MATRIX.md`: Complete test matrix reference

## Security Considerations

This test suite validates that:
1. ✅ Regular users cannot access other organisations' data
2. ✅ Product owners see cross-org usage (intended feature)
3. ✅ Ambtenaar users have proper oversight access
4. ✅ Organisation boundaries are enforced
5. ✅ Invalid UUIDs don't cause errors or data leaks

## Success Criteria

### Tests Pass When:
- ✅ Admin sees objects from Orgs A and B
- ✅ Org C returns empty results (no objects created)
- ✅ Organisation filter correctly limits results
- ✅ Product owner sees cross-org usage
- ✅ Non-owners cannot access product usage
- ✅ Pagination works correctly with 3 orgs
- ✅ Response format is consistent
- ✅ Invalid UUIDs handle gracefully
- ✅ All objects properly cleaned up

---

**Status**: ⏳ Ready for Testing
**Author**: AI Assistant + User Collaboration
**Date**: 2025-10-29
**Version**: 1.0

