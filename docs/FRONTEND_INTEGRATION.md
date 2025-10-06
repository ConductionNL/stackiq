# Frontend Integration: Contactpersonen with User Details

## Overview

The frontend has been enhanced to automatically fetch contact persons with user details when switching to the contactpersonen tab in the organization card. This integration calls our new API endpoint alongside the existing `available-groups` endpoint.

## Implementation Details

### Store Enhancement (`organisatie.js`)

Added a new method `fetchContactPersonsWithUserDetails()` to the Pinia store:

```javascript
async fetchContactPersonsWithUserDetails(organizationUuid) {
    try {
        const url = generateUrl(`/apps/softwarecatalog/api/contactpersonen/organisation/${organizationUuid}/with-user-details`)
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                requesttoken: OC.requestToken
            }
        })
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`)
        }
        
        const data = await response.json()
        
        if (data.success) {
            console.log('Successfully fetched contact persons with user details:', data)
            return data.data || []
        } else {
            throw new Error(data.message || 'Failed to fetch contact persons with user details')
        }
    } catch (error) {
        console.error('Error fetching contact persons with user details:', error)
        this.error = error.message
        return []
    }
}
```

### Component Enhancement (`ContactpersonenList.vue`)

#### Parallel API Calls

The `loadData()` method now makes parallel calls to both endpoints:

```javascript
async loadData() {
    try {
        // Fetch both available groups and contact persons with user details in parallel
        console.log('Loading contactpersonen data with user details...')
        
        const promises = [
            this.organisatieStore.fetchAvailableGroups(),
            this.organisatieStore.fetchContactPersonsWithUserDetails(this.organisationId)
        ]
        
        const [, contactPersonsWithUserDetails] = await Promise.all(promises)
        
        console.log('Loaded contact persons with user details:', contactPersonsWithUserDetails)
        
        // If we got contact persons with user details, we can use them to enhance the existing data
        if (contactPersonsWithUserDetails && contactPersonsWithUserDetails.length > 0) {
            this.enhanceContactpersonenWithUserDetails(contactPersonsWithUserDetails)
        }
        
        // If no organisation data provided, fall back to fetching contactpersonen separately
        if (!this.organisationData.contactpersonen) {
            await this.organisatieStore.fetchContactpersonen(this.organisationId)
        }
    } catch (error) {
        console.error('Error loading contactpersonen data:', error)
    }
}
```

#### Data Enhancement

Added `enhanceContactpersonenWithUserDetails()` method to merge user details into existing contactpersonen data:

```javascript
enhanceContactpersonenWithUserDetails(contactPersonsWithUserDetails) {
    if (!this.organisationData.contactpersonen) return
    
    console.log('Enhancing contactpersonen with user details:', contactPersonsWithUserDetails)
    
    // Create a map for quick lookup by UUID
    const userDetailsMap = new Map()
    contactPersonsWithUserDetails.forEach(cp => {
        userDetailsMap.set(cp.uuid, cp.object.userDetails)
    })
    
    // Enhance existing contactpersonen with user details
    this.organisationData.contactpersonen.forEach(contactpersoon => {
        const contactUuid = contactpersoon.uuid || contactpersoon.id
        const userDetails = userDetailsMap.get(contactUuid)
        
        if (userDetails) {
            console.log(`Enhancing contactpersoon ${contactUuid} with user details:`, userDetails)
            
            // Add user details to the contactpersoon object
            if (!contactpersoon.user) {
                contactpersoon.user = {}
            }
            
            // Enhance user object with detailed information
            contactpersoon.user.hasUser = true
            contactpersoon.user.username = userDetails.uid
            contactpersoon.user.enabled = userDetails.enabled
            contactpersoon.user.displayName = userDetails.displayName
            contactpersoon.user.lastLogin = userDetails.lastLogin
            contactpersoon.user.backend = userDetails.backend
            contactpersoon.user.home = userDetails.home
            contactpersoon.user.avatarImage = userDetails.avatarImage
            contactpersoon.user.quota = userDetails.quota
            contactpersoon.user.freeQuota = userDetails.freeQuota
            
            // Keep existing groups if they exist, otherwise initialize empty array
            if (!contactpersoon.user.groups) {
                contactpersoon.user.groups = []
            }
        } else {
            console.log(`No user details found for contactpersoon ${contactUuid}`)
            
            // Ensure user object exists even without details
            if (!contactpersoon.user) {
                contactpersoon.user = {
                    hasUser: false,
                    username: '',
                    groups: []
                }
            }
        }
    })
    
    console.log('Enhanced contactpersonen:', this.organisationData.contactpersonen)
}
```

## How It Works

1. **Component Mount**: When the `ContactpersonenList` component is mounted, it calls `loadData()`
2. **Parallel API Calls**: Two API calls are made simultaneously:
   - `fetchAvailableGroups()` - Gets available groups for user assignment
   - `fetchContactPersonsWithUserDetails()` - Gets contact persons with user details
3. **Data Enhancement**: The user details are merged into the existing contactpersonen data
4. **UI Update**: The enhanced data is automatically reflected in the UI

## Benefits

- **Performance**: Parallel API calls reduce loading time
- **Rich Data**: Contact persons now have comprehensive user information
- **Seamless Integration**: Works with existing data flow and UI components
- **Error Resilience**: Continues to work even if one API call fails

## User Details Available

The enhanced contactpersonen objects now include:

- `user.hasUser` - Boolean indicating if user account exists
- `user.username` - User ID/username
- `user.enabled` - Whether user account is enabled
- `user.displayName` - User's display name
- `user.lastLogin` - Last login timestamp
- `user.backend` - User backend class name
- `user.home` - User home directory
- `user.avatarImage` - Base64 encoded avatar image
- `user.quota` - User quota
- `user.freeQuota` - User free quota
- `user.groups` - User's group memberships

## Console Logging

The implementation includes comprehensive console logging for debugging:

- API call initiation and completion
- Data enhancement process
- Individual contactpersoon enhancement
- Error handling and fallbacks

## Error Handling

- **API Failures**: Individual API call failures don't break the entire process
- **Missing Data**: Graceful handling of missing user details
- **Fallback Behavior**: Continues to work with existing data if enhancement fails

## Testing

To test the integration:

1. Open the organization card in the OrganisatieIndex page
2. Switch to the "Contactpersonen" tab
3. Check the browser console for logging messages
4. Verify that contact persons show enhanced user information
5. Check the Network tab to see both API calls being made

## Related Files

- `softwarecatalog/src/store/modules/organisatie.js` - Store with new API method
- `softwarecatalog/src/components/ContactpersonenList.vue` - Component with enhanced data loading
- `softwarecatalog/lib/Service/ContactpersoonService.php` - Backend service
- `softwarecatalog/lib/Controller/ContactpersonenController.php` - API controller
