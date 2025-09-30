# Frontend Development Guide - Leverancier-Gemeente Workflow

## 📋 **Project Overview**

This guide provides everything needed to implement the frontend for the **leverancier-gemeente gebruik workflow** in the SoftwareCatalog Nextcloud app.

### What's Been Built (Backend)
✅ **Complete API Implementation**
- Custom endpoints for cross-organisation access
- Security validation (afnemer-based permissions)
- Comprehensive error handling
- Full CRUD operations for suggestions

✅ **Tested Functionality**
- Leveranciers can create suggestions for gemeenten
- Gemeenten can view suggestions assigned to them
- Claim workflow (transfer ownership)
- Deny workflow (delete suggestion)
- Security prevents unauthorized access

## 🎯 **What Needs to be Built (Frontend)**

### Required Components
1. **Suggestions Dashboard** - Main view showing all suggestions
2. **Suggestion Card** - Individual suggestion display with actions
3. **Confirmation Dialogs** - For claim/deny actions
4. **Loading States** - For all async operations
5. **Error Handling** - User-friendly error messages

### Navigation Integration
- Add "Suggestions" section to Software Catalog menu
- Route: `/suggestions`
- Icon: `mdi-format-list-bulleted`

## 🔗 **API Endpoints Ready to Use**

### 1. Get Suggestions
```http
GET /index.php/apps/softwarecatalog/api/aangeboden-gebruik/afnemer
```
Returns all suggestions where current organisation is the afnemer.

### 2. Claim Suggestion  
```http
PUT /index.php/apps/softwarecatalog/api/aangeboden-gebruik/{id}/set-self
```
Claims a suggestion (transfers ownership to current organisation).

### 3. Deny Suggestion
```http
DELETE /index.php/apps/softwarecatalog/api/aangeboden-gebruik/{id}/deny  
```
Denies a suggestion (deletes it completely).

## 📊 **Data Structure**

### Suggestion Object
```javascript
{
  id: "uuid-string",
  afnemer: "gemeente-uuid", 
  product: "product-uuid",
  status: "voorgesteld",
  beschrijving: "Description text",
  "@self": {
    id: "uuid-string",
    organisation: "leverancier-uuid", // Changes to gemeente after claim
    register: "1",
    schema: "8"
  }
}
```

## 🎨 **UI/UX Requirements**

### Design System
- Use **Nextcloud Vue components** for consistency
- Follow Nextcloud design guidelines
- Use **MDI icons** from pictogrammers.com

### Key UI Elements
- **Green "Accept" button** with `mdi-check-circle` icon
- **Red "Reject" button** with `mdi-close-circle` icon  
- **Loading spinners** during actions
- **Success/error notifications**
- **Confirmation dialogs** for destructive actions

### States to Handle
- **Loading**: Show spinners while fetching/updating
- **Empty**: Message when no suggestions available
- **Error**: User-friendly error messages
- **Success**: Confirmation after successful actions

## 🔒 **Security & Error Handling**

### HTTP Status Codes
- **200**: Success
- **403**: Not authorized (not the afnemer)
- **404**: Suggestion not found
- **500**: Server error

### Error Messages
- **403**: "You are not authorized to perform this action"
- **404**: "This suggestion is no longer available"  
- **500**: "An error occurred. Please try again later"

## 🛠 **Implementation Steps**

### Phase 1: Basic Structure
1. Create suggestions route and view
2. Implement API service methods
3. Create basic suggestions list component
4. Add navigation menu item

### Phase 2: Core Functionality  
1. Implement suggestion cards with data display
2. Add claim/deny buttons with loading states
3. Implement confirmation dialogs
4. Add error handling and notifications

### Phase 3: Polish & Testing
1. Add empty states and loading indicators
2. Implement auto-refresh after actions
3. Add comprehensive error handling
4. Test all user scenarios

## 📁 **File Structure Suggestion**

```
src/
├── views/
│   └── SuggestionsView.vue          # Main suggestions page
├── components/
│   ├── SuggestionCard.vue           # Individual suggestion display
│   ├── SuggestionActions.vue        # Claim/deny buttons
│   └── ConfirmationDialog.vue       # Reusable confirmation dialog
├── services/
│   └── suggestionsApi.js            # API service methods
└── store/
    └── suggestions.js               # Vuex store for suggestions
```

## 🧪 **Testing Scenarios**

### User Stories to Test
1. **As a gemeente, I want to see suggestions assigned to me**
2. **As a gemeente, I want to accept a suggestion and take ownership**
3. **As a gemeente, I want to reject a suggestion I don't need**
4. **As a gemeente, I want clear feedback when actions succeed/fail**
5. **As a gemeente, I want to be prevented from unauthorized actions**

### Mock Data for Development
```javascript
const mockSuggestions = [
  {
    id: 'test-uuid-1',
    afnemer: 'current-gemeente-uuid',
    product: 'product-uuid-1', 
    status: 'voorgesteld',
    beschrijving: 'Document Management System suggestion from Leverancier BV',
    '@self': {
      id: 'test-uuid-1',
      organisation: 'leverancier-uuid',
      register: '1',
      schema: '8'
    }
  }
];
```

## 📚 **Documentation References**

### Detailed Technical Docs
- **`/website/docs/leverancier-gemeente-workflow.md`** - Complete workflow documentation
- **`/website/docs/aangeboden-gebruik-api.md`** - Full API documentation

### Nextcloud Development Resources
- **Nextcloud Vue Components**: https://nextcloud-vue-components.netlify.app/
- **Design Guidelines**: https://docs.nextcloud.com/server/latest/developer_manual/design/
- **MDI Icons**: https://pictogrammers.com/library/mdi/

## 🚀 **Getting Started**

1. **Read the complete workflow documentation** in `/website/docs/leverancier-gemeente-workflow.md`
2. **Test the API endpoints** using the provided curl examples
3. **Set up the basic component structure** following Nextcloud patterns
4. **Implement incrementally** starting with the suggestions list
5. **Test thoroughly** with different user scenarios

## ✅ **Definition of Done**

The frontend is complete when:
- [ ] Gemeenten can view all suggestions assigned to them
- [ ] Gemeenten can claim suggestions with confirmation
- [ ] Gemeenten can deny suggestions with confirmation  
- [ ] All loading states and error handling work correctly
- [ ] UI follows Nextcloud design guidelines
- [ ] Component is integrated into main navigation
- [ ] All user scenarios are tested and working

---

**Backend Status**: ✅ **COMPLETE** - All APIs tested and working
**Frontend Status**: 🔄 **READY FOR DEVELOPMENT** - All requirements documented

The backend implementation is fully complete and tested. The frontend developer has everything needed to build a complete, secure, and user-friendly interface for the leverancier-gemeente workflow!
