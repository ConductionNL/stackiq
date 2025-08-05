# ArchiMate Import/Export Functionality

## Overview

The Software Catalog application provides comprehensive ArchiMate import and export functionality, allowing you to seamlessly integrate architectural models with the OpenRegister system. This feature supports both importing ArchiMate files (.xml, .archimate) and exporting OpenRegister objects back to ArchiMate format.

## Features

### Import Capabilities
- **Large File Support**: Handles files of any size using memory-efficient streaming XML parsing
- **Multiple Formats**: Supports both XML and JSON ArchiMate formats
- **Progress Tracking**: Real-time progress updates during import operations
- **Object Mapping**: Converts ArchiMate elements to OpenRegister objects
- **Update Detection**: Intelligently updates existing objects or creates new ones
- **Skipping Logic**: Automatically skips unchanged objects to improve performance
- **Parallel Processing**: Schema types are processed in parallel for optimal performance

### Export Capabilities
- **Complete Export**: Exports all OpenRegister objects to ArchiMate format
- **Organization Filtering**: Export data specific to particular organizations
- **Multiple Formats**: Export as XML or JSON
- **Proper Structure**: Generates valid ArchiMate XML with proper namespaces

### AMEF Configuration
- **Schema Mapping**: Configure which OpenRegister schemas correspond to ArchiMate types
- **Auto-Configuration**: Automatic detection and setup of AMEF schemas
- **Manual Configuration**: Fine-tune schema mappings as needed

## Configuration

### AMEF Schema Setup

The AMEF (ArchiMate Exchange Format) configuration maps ArchiMate elements to OpenRegister schemas:

#### Default Schema Mappings
- **Elements Schema**: ID 66 (vng-gemma register)
- **Organizations Schema**: ID 66 (same as elements - organizations are elements in ArchiMate)
- **Relationships Schema**: ID 71 (vng-gemma register)
- **Views Schema**: ID 69 (vng-gemma register)
- **Property Definitions Schema**: ID 70 (vng-gemma register)
- **Extended Views Schema**: ID 72 (vng-gemma register)

#### Auto-Configuration
The system can automatically detect and configure AMEF schemas:

1. Navigate to **Settings > Software Catalog**
2. Go to the **AMEF Configuration** section
3. Click **Auto-Configure AMEF**
4. The system will detect available schemas and configure them automatically

#### Manual Configuration
To manually configure AMEF schemas:

1. Navigate to **Settings > Software Catalog**
2. Go to the **AMEF Configuration** section
3. Select the appropriate **Register ID** (typically 15 for vng-gemma)
4. Configure individual schema IDs for each ArchiMate type
5. Click **Save Configuration**

## Usage

### Importing ArchiMate Files

#### Via Web Interface
1. Navigate to **Settings > Software Catalog**
2. Go to the **ArchiMate Import/Export** section
3. Click **Choose File** and select your ArchiMate file (.xml or .archimate)
4. Configure import options:
   - **Update Existing**: Update existing objects if found
   - **Delete Orphaned Objects**: Delete objects no longer present in the imported file
   - **Preserve IDs**: Keep original ArchiMate IDs
   - **Organization Filter**: Limit import to specific organization
5. Click **Import ArchiMate File**
6. Monitor progress in real-time
7. Review import results and statistics

#### Import Options
- **Update Existing**: When enabled, existing objects with the same ArchiMate ID will be updated
- **Delete Orphaned Objects**: When enabled, objects that are no longer present in the imported file will be deleted
- **Preserve IDs**: Maintains original ArchiMate identifiers in OpenRegister objects
- **Organization Filter**: Restricts import to objects belonging to a specific organization

#### Import Results
After import, you'll see detailed statistics:
- **Total Objects Created**: Number of new objects created
- **Total Objects Updated**: Number of existing objects updated
- **Total Objects Skipped**: Number of objects that were unchanged and skipped
- **Total Objects Deleted**: Number of objects deleted (if applicable)
- **Total Errors**: Number of errors encountered
- **Per Schema Breakdown**: Detailed statistics for each schema type

### Exporting to ArchiMate

#### Via Web Interface
1. Navigate to **Settings > Software Catalog**
2. Go to the **ArchiMate Import/Export** section
3. Configure export options:
   - **Format**: Choose XML or JSON
   - **Organization Specific**: Enable to export only from a specific organization ID
   - **Organization ID**: Enter the specific organization ID (when Organization Specific is enabled)
   - **Organization Filter**: Filter by organization name (when Organization Specific is disabled)
   - **Schemas to Export**: Select which schemas to include (defaults to all)
   - **Include Relationships**: Include relationship data
   - **Include Views**: Include view and diagram data
4. Click **Export to ArchiMate**
5. Monitor progress in real-time
6. Download the generated file

#### Export Options
- **Format**: XML (default) or JSON
- **Organization Specific**: When enabled, export only objects from a specific organization ID
- **Organization ID**: Enter the specific organization ID (only shown when Organization Specific is enabled)
- **Organization Filter**: Filter export by organization name (only shown when Organization Specific is disabled)
- **Schemas to Export**: Select which AMEF schemas to include in the export (defaults to all schemas)
- **Include Relationships**: Export relationship data between elements
- **Include Views**: Export view and diagram information

## Technical Details

### File Size Handling
The system automatically chooses the optimal parsing method based on file size:

- **Small Files (< 5MB)**: Uses SimpleXML for fast parsing
- **Large Files (≥ 5MB)**: Uses XMLReader for memory-efficient streaming parsing

### Performance Optimizations
- **Parallel Processing**: Schema types (elements, organizations, relationships, views) are processed simultaneously
- **Batch Processing**: Items are processed in configurable batches (default: 100 items)
- **Object Caching**: Existing objects are preloaded to avoid database queries during processing
- **Skipping Logic**: Unchanged objects are automatically skipped to improve performance
- **Memory Management**: Streaming parsing for large files prevents memory exhaustion

### Supported ArchiMate Elements
The system processes the following ArchiMate element types:

#### Business Layer
- BusinessActor
- BusinessRole
- BusinessProcess
- BusinessService
- BusinessInterface

#### Application Layer
- ApplicationComponent
- ApplicationService
- ApplicationInterface

#### Technology Layer
- Node
- TechnologyService
- TechnologyInterface

#### Relationships
- Association
- Composition
- Aggregation
- Realization
- Assignment
- Influence

#### Views
- View
- Viewpoint
- Extended View

### Data Mapping
ArchiMate elements are mapped to OpenRegister objects as follows:

| ArchiMate Element | OpenRegister Type | Schema ID |
|-------------------|-------------------|-----------|
| BusinessActor | Organization | 66 |
| BusinessRole | Role | 66 |
| ApplicationComponent | Application | 66 |
| TechnologyService | Technical Service | 66 |
| Relationship | Relation | 71 |
| View | View | 69 |

## Troubleshooting

### Common Issues

#### Import Shows "0 Objects Created/Updated"
**Cause**: AMEF schema configuration is missing or incorrect
**Solution**: 
1. Check AMEF configuration in Settings
2. Run Auto-Configure AMEF
3. Verify schema IDs match your OpenRegister setup

#### Memory Errors with Large Files
**Cause**: File too large for memory-based parsing
**Solution**: The system automatically uses streaming parsing for files > 5MB

#### Import Fails with 500 Error
**Cause**: File format issues or missing dependencies
**Solution**:
1. Verify file is valid ArchiMate XML/JSON
2. Check file permissions
3. Review server logs for specific error details

#### Export Returns Empty Data
**Cause**: No objects found or incorrect schema configuration
**Solution**:
1. Verify objects exist in OpenRegister
2. Check AMEF schema configuration
3. Ensure proper organization filtering

#### Objects Being Updated When They Should Be Skipped
**Cause**: Object comparison logic may not be working correctly
**Solution**:
1. Check the logs for "Object unchanged, skipping update" messages
2. Verify that the `areObjectsEqual` method is working correctly
3. Review the object data structure for comparison issues

### Logging and Debugging
Enable detailed logging to troubleshoot issues:

```bash
# Check application logs
docker-compose exec nextcloud tail -n 100 /var/www/html/data/nextcloud.log | grep softwarecatalog

# Check specific import/export operations
docker-compose exec nextcloud tail -n 50 /var/www/html/data/nextcloud.log | grep -i "archimate"

# Check for skipped objects
docker-compose exec nextcloud tail -n 100 /var/www/html/data/nextcloud.log | grep -i "skipping update"

# Check performance metrics
docker-compose exec nextcloud tail -n 100 /var/www/html/data/nextcloud.log | grep -i "performance\|timing"
```

## API Reference

### Import Endpoint
```bash
POST /api/archimate/import
Content-Type: multipart/form-data

Parameters:
- archiMateFile: File upload
- updateExisting: boolean (default: true)
- preserveIds: boolean (default: true)
- organizationFilter: string (optional)
```

### Export Endpoint
```bash
POST /api/archimate/export
Content-Type: application/json

Body:
{
  "format": "xml|json",
  "includeRelationships": boolean,
  "includeViews": boolean,
  "organizationSpecific": boolean,
  "organizationId": string (optional)
}
```

### Progress Tracking
```bash
GET /api/progress/{operationId}
GET /api/progress/{operationId}/stream
```

### AMEF Configuration
```bash
GET /api/settings/amef
POST /api/settings/amef
POST /api/settings/amef/auto-configure
```

### Consolidated Configuration
The consolidated configuration endpoint (`GET /api/settings`) now includes AMEF object counts in the `archimate` section:

```json
{
  "archimate": {
    "import": {...},
    "export": {...},
    "totalElementObjects": 150,
    "totalOrganizationObjects": 25,
    "totalViewObjects": 10,
    "totalRelationshipsObjects": 300
  }
}
```

These counts provide real-time information about the number of objects in each AMEF schema type, helping you monitor the current state of your architectural data.

## Best Practices

### Import Best Practices
1. **Backup Data**: Always backup existing data before large imports
2. **Test with Small Files**: Test import process with smaller files first
3. **Review Configuration**: Verify AMEF schema configuration before import
4. **Monitor Progress**: Use progress tracking for large imports
5. **Check Results**: Review import statistics and error logs
6. **Monitor Skipped Objects**: Check skipped object counts to ensure comparison logic is working

### Export Best Practices
1. **Filter Appropriately**: Use organization filters to limit export scope
2. **Include Relationships**: Enable relationship export for complete data
3. **Choose Format**: Use XML for compatibility, JSON for processing
4. **Validate Output**: Verify exported files in ArchiMate tools

### Configuration Best Practices
1. **Auto-Configure First**: Use auto-configuration before manual setup
2. **Verify Schema IDs**: Ensure schema IDs match your OpenRegister setup
3. **Test Configuration**: Test with small imports after configuration changes
4. **Document Settings**: Keep records of your AMEF configuration

## Performance Considerations

### Memory Usage
- **Small Files**: Minimal memory impact with SimpleXML
- **Large Files**: Memory-efficient streaming with XMLReader
- **Progress Tracking**: Lightweight session-based storage

### Processing Speed
- **Streaming Parser**: Processes large files without loading into memory
- **Batch Processing**: Handles large datasets efficiently
- **Async Processing**: Non-blocking operations for better user experience
- **Parallel Processing**: Schema types processed simultaneously
- **Skipping Logic**: Avoids unnecessary database operations for unchanged objects

### Scalability
- **Horizontal Scaling**: Progress tracking works across multiple instances
- **Resource Management**: Automatic cleanup of temporary data
- **Error Recovery**: Graceful handling of partial failures

## Future Enhancements

### Planned Features
- **Batch Import**: Support for importing multiple files simultaneously
- **Advanced Filtering**: More granular filtering options for imports/exports
- **Schema Validation**: Automatic validation of ArchiMate schema compliance
- **Performance Monitoring**: Enhanced monitoring and reporting capabilities
- **Integration APIs**: REST APIs for external system integration

### Customization Options
- **Custom Mappings**: User-defined element type mappings
- **Template Support**: Import/export templates for common scenarios
- **Workflow Integration**: Integration with approval workflows
- **Notification System**: Email notifications for import/export completion

---

**Version**: 1.0.0  
**Last Updated**: July 31, 2025  
**Compatibility**: Nextcloud 30+, OpenRegister 1.0+ 