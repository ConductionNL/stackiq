# Leverancier-Gemeente Gebruik Workflow

## Overview

The SoftwareCatalog app implements a specialized workflow where leveranciers (suppliers) can register gebruik objects for gemeenten (municipalities), and gemeenten can then claim or deny these suggestions. This enables cross-organisation collaboration while maintaining security.

## Workflow Description

### 1. **Leverancier Creates Suggestion**
- A leverancier creates a gebruik object with `afnemer` set to a gemeente (different from their own organisation)
- The object is owned by the leverancier but assigned to the gemeente as afnemer
- Status is typically set to 'voorgesteld' (suggested)

### 2. **Gemeente Discovers Suggestions**
- Gemeente can view suggestions via the custom endpoint that shows objects where they are the afnemer
- The endpoint bypasses normal RBAC to show cross-organisation objects

### 3. **Gemeente Claims or Denies**
- **Claim**: Sets `@self.organisation` to the gemeente, transferring ownership
- **Deny**: Deletes the suggestion object completely

## API Endpoints

### Base URL
```
/index.php/apps/softwarecatalog/api/aangeboden-gebruik
```

### 1. Get Suggestions for Current Organisation
```http
GET /afnemer
```

**Description**: Returns all gebruik objects where the active organisation is the afnemer.

**Security**: Uses RBAC-disabled search to find cross-organisation objects, then filters by afnemer.

**Response**:
```json
{
  "success": true,
  "gebruiks": [
    {
      "id": "usage-uuid-123",
      "afnemer": "gemeente-uuid",
      "product": "product-uuid", 
      "status": "voorgesteld",
      "beschrijving": "Description of the suggestion",
      "@self": {
        "id": "usage-uuid-123",
        "organisation": "leverancier-uuid",
        "register": "1",
        "schema": "8"
      },
      "_filter_type": "afnemer",
      "_schema_id": "8"
    }
  ],
  "count": 1,
  "filter_type": "afnemer",
  "organisation": "gemeente-uuid"
}
```

### 2. Claim a Suggestion
```http
PUT /{gebruikId}/set-self
```

**Description**: Claims a gebruik suggestion by setting `@self.organisation` to the active organisation.

**Security**: Only allowed if active organisation is the afnemer for the object.

**Parameters**:
- `gebruikId` (path): UUID of the gebruik object to claim

**Success Response**:
```json
{
  "success": true,
  "message": "Gebruik @self property updated successfully",
  "gebruik": {
    "id": "usage-uuid-123",
    "afnemer": "gemeente-uuid",
    "product": "product-uuid",
    "status": "voorgesteld",
    "beschrijving": "Description"
  },
  "updated_fields": ["@self.organisation"]
}
```

**Error Response** (403 Forbidden):
```json
{
  "success": false,
  "error": "Operation not allowed: active organization is not the afnemer",
  "gebruik": null,
  "debug": {
    "afnemer_in_object": "other-org-uuid",
    "resolved_afnemer_id": "other-org-uuid", 
    "current_org": "gemeente-uuid"
  }
}
```

### 3. Deny a Suggestion
```http
DELETE /{gebruikId}/deny
```

**Description**: Denies a gebruik suggestion by deleting the object completely.

**Security**: Only allowed if active organisation is the afnemer for the object.

**Parameters**:
- `gebruikId` (path): UUID of the gebruik object to deny

**Success Response**:
```json
{
  "success": true,
  "message": "Gebruik object deleted successfully",
  "deleted": true,
  "gebruik_id": "usage-uuid-123",
  "organisation": "gemeente-uuid"
}
```

**Error Response** (403 Forbidden):
```json
{
  "success": false,
  "error": "Operation not allowed: active organization is not the afnemer",
  "deleted": false,
  "debug": {
    "afnemer_in_object": "other-org-uuid",
    "resolved_afnemer_id": "other-org-uuid",
    "current_org": "gemeente-uuid"
  }
}
```

## Data Structure

### Gebruik Object Schema
```json
{
  "id": "string (UUID)",
  "afnemer": "string (UUID) - Organisation that will use the product",
  "product": "string (UUID) - Product being used", 
  "status": "string - Usage status (voorgesteld, actief, etc.)",
  "beschrijving": "string - Description of the usage",
  "module": "string (UUID, optional) - Specific module",
  "moduleVersie": "string (UUID, optional) - Module version",
  "@self": {
    "id": "string (UUID) - Object ID",
    "organisation": "string (UUID) - Owning organisation",
    "register": "string - Register ID (1 for voorzieningen)",
    "schema": "string - Schema ID (8 for gebruik)",
    "created": "string (ISO date)",
    "updated": "string (ISO date)"
  }
}
```

### Key Fields
- **`afnemer`**: The organisation that will use the product (gemeente)
- **`@self.organisation`**: The organisation that owns the object (leverancier initially, gemeente after claiming)
- **`product`**: Reference to the product/service being offered
- **`status`**: Current status ('voorgesteld' for suggestions)

## Frontend Implementation Guide

### Required Components

#### 1. **Suggestions List Component**
- Fetches suggestions using `GET /afnemer`
- Displays list of gebruik suggestions
- Shows product details, leverancier info, description
- Provides claim/deny actions for each suggestion

#### 2. **Suggestion Card Component**
- Displays individual suggestion details
- Shows product name, leverancier, description, dates
- Claim button (green) and Deny button (red)
- Loading states during actions

#### 3. **Confirmation Dialogs**
- Claim confirmation: 'Are you sure you want to accept this suggestion?'
- Deny confirmation: 'Are you sure you want to reject this suggestion? This cannot be undone.'

### State Management

#### Suggestions State
```javascript
{
  suggestions: [],
  loading: false,
  error: null,
  actionLoading: {} // Track loading state per suggestion ID
}
```

#### Actions
```javascript
// Fetch suggestions
async fetchSuggestions()

// Claim suggestion  
async claimSuggestion(gebruikId)

// Deny suggestion
async denySuggestion(gebruikId)

// Refresh after action
async refreshSuggestions()
```

### API Integration

#### Fetch Suggestions
```javascript
const response = await fetch('/index.php/apps/softwarecatalog/api/aangeboden-gebruik/afnemer', {
  method: 'GET',
  headers: {
    'Content-Type': 'application/json',
    'requesttoken': OC.requestToken
  }
});
const data = await response.json();
```

#### Claim Suggestion
```javascript
const response = await fetch(`/index.php/apps/softwarecatalog/api/aangeboden-gebruik/${gebruikId}/set-self`, {
  method: 'PUT',
  headers: {
    'Content-Type': 'application/json',
    'requesttoken': OC.requestToken
  }
});
```

#### Deny Suggestion
```javascript
const response = await fetch(`/index.php/apps/softwarecatalog/api/aangeboden-gebruik/${gebruikId}/deny`, {
  method: 'DELETE', 
  headers: {
    'Content-Type': 'application/json',
    'requesttoken': OC.requestToken
  }
});
```

### Error Handling

#### Common Error Scenarios
1. **403 Forbidden**: User is not the afnemer for the object
2. **404 Not Found**: Suggestion object no longer exists
3. **500 Internal Error**: Server-side error

#### Error Display
- Show user-friendly error messages
- For 403 errors: 'You are not authorized to perform this action'
- For 404 errors: 'This suggestion is no longer available'
- For 500 errors: 'An error occurred. Please try again later.'

### UI/UX Guidelines

#### Visual Design
- Use Nextcloud Vue components for consistency
- Green 'Accept' button with checkmark icon
- Red 'Reject' button with X icon
- Loading spinners during actions
- Success/error notifications

#### User Experience
- Auto-refresh suggestions after actions
- Optimistic updates (remove from list immediately)
- Undo functionality for accidental actions (if possible)
- Clear visual feedback for all states

#### Icons (MDI)
- Suggestions list: `mdi-format-list-bulleted`
- Accept action: `mdi-check-circle`
- Reject action: `mdi-close-circle`
- Loading: `mdi-loading`
- Product: `mdi-package-variant`
- Organisation: `mdi-domain`

### Navigation Integration

#### Menu Structure
```
Software Catalog
├── Dashboard
├── Products
├── Usage
│   ├── My Usage
│   └── Suggestions ← New section
└── Settings
```

#### Route Configuration
```javascript
{
  path: '/suggestions',
  name: 'Suggestions',
  component: SuggestionsView
}
```

## Technical Implementation Details

### Security Architecture
- **RBAC Bypass**: Endpoints use `rbac: false, multi: false` to access cross-organisation objects
- **Custom Validation**: Each endpoint validates that the active organisation is the afnemer
- **No Admin Exemption**: Security checks apply to all users, including admins

### Backend Service Methods
- `getGebruiksWhereAfnemer()`: Retrieves suggestions for current organisation
- `setGebruikSelfToActiveOrg()`: Claims a suggestion (transfers ownership)
- `deleteGebruikAsAfnemer()`: Denies a suggestion (deletes object)

### Database Queries
- Uses `searchObjectsPaginated()` with RBAC disabled for cross-organisation access
- Filters by `afnemer` field to find relevant suggestions
- Validates ownership before any modifications

## Testing Scenarios

### Test Cases for Frontend
1. **Load suggestions**: Verify suggestions are displayed correctly
2. **Claim suggestion**: Test successful claim with UI updates
3. **Deny suggestion**: Test successful denial with confirmation
4. **Unauthorized access**: Test error handling for wrong organisation
5. **Network errors**: Test error handling for API failures
6. **Empty state**: Test display when no suggestions available
7. **Loading states**: Verify loading indicators work correctly

### Mock Data for Development
```javascript
const mockSuggestions = [
  {
    id: 'test-uuid-1',
    afnemer: 'gemeente-uuid',
    product: 'product-uuid-1',
    status: 'voorgesteld',
    beschrijving: 'Suggested usage of Document Management System',
    '@self': {
      id: 'test-uuid-1',
      organisation: 'leverancier-uuid',
      register: '1',
      schema: '8'
    }
  }
];
```

## Configuration Requirements

### OpenRegister Setup
- Voorzieningen register (ID: 1) must be configured
- Gebruik schema (ID: 8) must be available
- Organisations must be properly set up in OpenRegister

### SoftwareCatalog Configuration
- App must be connected to OpenRegister
- Voorzieningen configuration must be initialized
- User must have proper organisation association

This documentation provides everything needed to implement the frontend for the leverancier-gemeente gebruik workflow.
