# ArchiMate Import/Export

This document describes the ArchiMate import/export functionality in the SoftwareCatalog application.

## Overview

The ArchiMate import/export feature provides **perfect round-trip fidelity** by:
- Importing ArchiMate files (.archimate or .xml) with complete XML data capture
- Storing all XML data as JSON blobs in the database for exact preservation
- Exporting data back to XML with identical structure to the original import
- Creating OpenRegister objects with proper '@self' structure for database persistence

## Key Features

### 🔄 Round-Trip Fidelity
- **Complete XML Preservation**: All XML attributes, namespaces, and text content are captured
- **Exact Export**: Exported XML matches the imported XML structure perfectly
- **No Data Loss**: Every XML element, attribute, and namespace is preserved

### 🏗️ Clean Architecture
- **Modular Services**: Separate `ArchiMateImportService` and `ArchiMateExportService`
- **XML to Array Conversion**: Comprehensive parsing capturing all possible XML values
- **Array to XML Conversion**: Precise reconstruction of original XML structure
- **JSON Blob Storage**: Complete XML data stored as JSON for perfect preservation

## Processing Modes

The import functionality now supports two processing modes to optimize for different use cases:

### 🚀 High Performance Mode (Recommended)
- **Optimized for speed** with parallel processing
- Uses larger batch sizes (100 items per batch)
- Processes 4 batches concurrently
- Best for large files and when you have sufficient memory (up to 2GB available)
- Recommended for most use cases

### 💾 Memory Efficient Mode
- **Optimized for memory usage** with streaming processing
- Uses smaller batch sizes (50 items per batch)
- Processes 2 batches concurrently
- Best for limited memory environments
- Slower but more memory-friendly

### How to Choose
- **Use High Performance** if you have 2GB+ memory available and want faster imports
- **Use Memory Efficient** if you're working with limited memory or very large files that might cause memory issues

## Technical Implementation

### XML Processing Pipeline

#### Import Flow (XML → Database)
1. **XML Parsing**: `ArchiMateImportService.xmlToArray()` converts XML to comprehensive array
   - Attributes prefixed with `_` (e.g., `_id`, `_name`)
   - Namespaced attributes as `prefix__name` (e.g., `archimate__type`)
   - Text content as `_value` or `_text`
   - Nested elements preserved with full structure

2. **Model Detection**: Extract model identifier and check if model already exists
   - Searches root attributes, model element, and archimate:model namespace
   - Generates fallback identifier if none found
   - Enables create-or-update behavior

3. **Data Normalization**: Structure data for JSON blob storage
   - Add `model_identifier` to all items for linking
   - Preserve complete original XML structure
   - Prepare for OpenRegister object conversion

4. **Object Creation**: Convert to OpenRegister objects with '@self' structure
   - Each object has `@self` with `register`, `schema`, and `id`
   - Store complete XML data as `xml_data` JSON blob
   - Link all items to parent model via `model_identifier`

5. **Database Persistence**: Use `ObjectService::saveObjects()` for bulk saving
   - Proper validation and error handling
   - RBAC and multi-organization support
   - Transaction safety

#### Export Flow (Database → XML)
1. **Data Retrieval**: Query database for objects matching criteria
2. **Data Reconstruction**: Convert OpenRegister objects back to ArchiMate format
3. **XML Generation**: Use `ArchiMateExportService` to recreate exact XML structure
4. **Round-Trip Validation**: Ensure exported XML matches imported structure

### Data Storage Structure

```json
{
  "@self": {
    "register": 6,
    "schema": 100,
    "id": "model-uuid-123",
    "owner": "admin",
    "organisation": "default",
    "created": "2024-01-01 12:00:00",
    "updated": "2024-01-01 12:00:00"
  },
  "identifier": "model-uuid-123",
  "name": "Enterprise Architecture Model",
  "model_identifier": "model-uuid-123",
  "xml_data": {
    "_attributes": {
      "identifier": "model-uuid-123",
      "name": "Enterprise Architecture Model"
    },
    "elements": [...],
    "relationships": [...],
    "views": [...]
  }
}
```

## Import Process

### Step 1: File Selection
1. Navigate to Settings → ArchiMate Import/Export
2. Click "Select ArchiMate File"
3. Choose your .archimate or .xml file

### Step 2: Processing Mode Selection
After selecting a file, you'll see the processing mode options:
- Choose between High Performance and Memory Efficient modes
- The interface shows the benefits and trade-offs of each mode

### Step 3: Import Execution
1. Click "Import ArchiMate File"
2. Monitor progress in real-time
3. View detailed statistics upon completion

## Performance Monitoring

The import process provides detailed performance metrics:

### Processing Times
- **Total Time**: Complete import duration
- **Validation**: File format and structure validation
- **Parsing**: XML parsing and data extraction
- **Conversion**: Object creation and database operations

### Memory Usage
- **Start Memory**: Memory usage at import start
- **Peak Memory**: Maximum memory usage during import
- **End Memory**: Memory usage after import completion

### Schema Statistics
For each schema type (elements, organizations, relationships, views, property_definitions):
- **Found**: Number of objects found in the file
- **Created**: New objects created
- **Updated**: Existing objects updated
- **Skipped**: Objects that were unchanged
- **Errors**: Any errors encountered

## API Usage

### Import Endpoint
```bash
POST /index.php/apps/softwarecatalog/api/archimate/import
Content-Type: multipart/form-data

Parameters:
- archiMateFile: File upload
- processingMode: 'speed' or 'memory' (default: 'speed')
- updateExisting: boolean (default: true)
- preserveIds: boolean (default: true)
- deleteOrphaned: boolean (default: false)
```

### Export Endpoint
```bash
POST /index.php/apps/softwarecatalog/api/archimate/export
Content-Type: application/json

Body:
{
  "criteria": {
    "model_identifier": "model-uuid-123"
  },
  "options": {
    "format": "xml",
    "include_relationships": true
  }
}
```

### Response Format

#### Import Response
```json
{
  "success": true,
  "message": "ArchiMate XML imported successfully",
  "model_info": {
    "identifier": "model-uuid-123",
    "exists": false,
    "action": "created"
  },
  "imported_objects": 150,
  "file_info": {
    "name": "enterprise_model.archimate",
    "size": 1024000,
    "path": "/tmp/upload_12345"
  }
}
```

#### Export Response
```json
{
  "success": true,
  "xml": "<?xml version='1.0' encoding='UTF-8'?>...",
  "exported_count": 150
}
```

### Example cURL Request
```bash
# High Performance Mode
curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/import" \
  -H "Content-Type: multipart/form-data" \
  -u admin:admin \
  -F "archiMateFile=@your_file.archimate" \
  -F "processingMode=speed"

# Memory Efficient Mode
curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/import" \
  -H "Content-Type: multipart/form-data" \
  -u admin:admin \
  -F "archiMateFile=@your_file.archimate" \
  -F "processingMode=memory"
```

## Troubleshooting

### Common Issues

#### Import Fails with Memory Error
- **Solution**: Switch to Memory Efficient mode
- **Alternative**: Reduce file size or split into smaller files

#### Slow Import Performance
- **Solution**: Switch to High Performance mode if you have sufficient memory
- **Check**: Ensure you have at least 2GB of available memory

#### Processing Mode Not Available
- **Check**: Ensure you're using the latest version of SoftwareCatalog
- **Verify**: The processing mode selection appears after file selection

### Performance Tips

1. **For Large Files (>1000 objects)**:
   - Use High Performance mode
   - Ensure sufficient memory (2GB+)
   - Monitor system resources during import

2. **For Limited Memory Environments**:
   - Use Memory Efficient mode
   - Close other applications to free memory
   - Consider splitting large files

3. **For Optimal Performance**:
   - Use SSD storage for faster file I/O
   - Ensure stable network connection for database operations
   - Monitor database performance during large imports

## Export Functionality

### Export Options
- **Format**: ArchiMate (.archimate), XML, or JSON
- **Content**: Organizations, elements, relationships, and views
- **Relationships**: Include or exclude relationship data

### Export Process
1. Select export format
2. Choose content options
3. Click "Export to ArchiMate"
4. Download the generated file

## Testing and Validation

### Round-Trip Testing
The round-trip test validates **perfect data integrity** by:
1. Exporting current data to ArchiMate format
2. Re-importing the exported data
3. Comparing original vs. imported data
4. Reporting any differences or data loss

### Running Tests
1. Navigate to the Testing tab
2. Click "Test Round-Trip"
3. Review comparison results
4. Address any differences found

### Validation Features
- **XML Structure Comparison**: Ensures identical element hierarchy
- **Attribute Preservation**: Verifies all attributes are maintained
- **Namespace Handling**: Confirms namespace declarations are preserved
- **Text Content**: Validates text content is unchanged
- **Data Integrity**: Checks that no information is lost during import/export cycle

## Configuration

### AMEF Schema Mapping
The system automatically maps ArchiMate elements to AMEF schemas (register independent, based on schema slugs):
- **Elements** → `element`
- **Organizations** → `organization`
- **Relationships** → `relation`
- **Views** → `view`
- **Models** → `model`
- **Property definitions** → `property-definition`

### Batch Processing Configuration
Batch sizes and concurrency can be configured based on processing mode:

#### High Performance Mode
- Batch Size: 100 items
- Parallel Batches: 4
- Memory Usage: Up to 2GB

#### Memory Efficient Mode
- Batch Size: 50 items
- Parallel Batches: 2
- Memory Usage: Optimized for low memory

## Recent Improvements

### Round-Trip Fidelity (Latest)
- **Perfect XML Preservation**: Complete capture of all XML attributes, namespaces, and content
- **JSON Blob Storage**: Store complete XML data as JSON for exact reconstruction
- **Model Detection**: Automatic detection of existing models for create-or-update behavior
- **Clean Architecture**: Modular services for import and export operations

### Processing Mode Selection
- Added user choice between High Performance and Memory Efficient modes
- Automatic configuration based on selected mode
- Real-time performance monitoring
- Detailed statistics for each processing mode

### Performance Optimizations
- Parallel processing with ReactPHP
- Streaming XML parsing for large files
- Optimized database operations
- Memory management improvements

### Error Handling
- Comprehensive error reporting
- Graceful failure recovery
- Detailed logging for debugging
- User-friendly error messages

### Database Integration
- **ObjectService Integration**: Uses `ObjectService::saveObjects()` for proper persistence
- **@self Structure**: Each object has proper register, schema, and id metadata
- **RBAC Support**: Role-based access control for security
- **Multi-Organization**: Support for multiple organizational contexts 