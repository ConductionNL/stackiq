# ArchiMate Import/Export

This document describes the ArchiMate import/export functionality in the SoftwareCatalog application.

## Overview

The ArchiMate import/export feature allows you to:
- Import ArchiMate files (.archimate or .xml) to create OpenRegister objects
- Export existing OpenRegister data to ArchiMate format
- Test data integrity with round-trip testing

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

## Testing

### Round-Trip Testing
The round-trip test validates data integrity by:
1. Exporting current data to ArchiMate format
2. Re-importing the exported data
3. Comparing original vs. imported data
4. Reporting any differences or data loss

### Running Tests
1. Navigate to the Testing tab
2. Click "Test Round-Trip"
3. Review comparison results
4. Address any differences found

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

### Processing Mode Selection (Latest)
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