#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Build the CMDB import test fixtures (cmdb-export-import, Task 1).

Python standard library only (zipfile, re). openpyxl is deliberately not used:
re-saving through a spreadsheet library would rewrite every part of the
package, and the point of these fixtures is to stay byte-close to a real
TOPdesk export.

Usage:

    python3 build-fixtures.py --source <anonymised export.xlsx>
        Sanitise an already anonymised export into topdesk-export-anonymised.xlsx,
        write the placeholder cached values into the CMDB sheets, then derive
        the variants.

    python3 build-fixtures.py
        Sanitise the committed topdesk-export-anonymised.xlsx again, write the
        placeholder cached values into it (both idempotent) and derive the
        variants.

Sanitising removes document metadata and everything that describes the
workbook's origin rather than its content: custom properties, customXml, the
workbook's absolute save path, xl/connections.xml, the printer settings of
every sheet (they name the printers of the machine the export was handled on),
the author, company and other document properties, the creation and
modification dates (set to FIXED_DATE), the revision and object GUIDs, and the
filter ranges and sheet dimensions that record the size of the original data.

The import reads the two CMDB sheets ("Onbeh Applicaties CMDB",
"Beheerde Applicaties CMDB"). Their cells are formulas that read the "Invoer"
sheets; the import reads the value Excel cached for each formula and never
evaluates one. The anonymised export had empty cached values for several
mapped columns, so CACHED_VALUES below writes a placeholder cached value into
those formula cells (the formula itself is kept). The "Invoer" sheets are not
read and are left as they are.

Never run this on a municipality's original export: the source must already
carry placeholder values only. tests/Unit/Fixtures/CmdbFixtureHygieneTest.php
fails when a fixture holds metadata or person data that is not a placeholder.
"""

import argparse
import os
import re
import sys
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
SANITISED = os.path.join(HERE, 'topdesk-export-anonymised.xlsx')

# Parts that never belong in a fixture.
DROP_PARTS = ('docProps/custom.xml', 'xl/connections.xml')
DROP_PREFIXES = ('customXml/', 'xl/printerSettings/')

# The creation and modification date of every fixture: neutral, not the date of
# any real export or save.
FIXED_DATE = '2026-01-01T00:00:00Z'

# Document properties that are emptied (core.xml and app.xml).
EMPTIED_CORE = ('dc:title', 'dc:subject', 'dc:creator', 'cp:keywords', 'dc:description',
                'cp:lastModifiedBy', 'cp:revision', 'cp:category', 'cp:contentStatus')
EMPTIED_APP = ('Manager', 'Company', 'HyperlinkBase')

ONBEH_SHEET = 'xl/worksheets/sheet2.xml'     # "Onbeh Applicaties CMDB" (from AIA)
BEHEERDE_SHEET = 'xl/worksheets/sheet4.xml'  # "Beheerde Applicaties CMDB" (from APP)
ARCHIVE_SHEET_NAME = 'Gearchiveerde Applicaties'

# Placeholder cached values for formula cells of the CMDB sheets, per sheet and
# cell. A cell that does not exist yet is appended to its row (columns are in
# order: every cell named here lies right of the row's last cell).
CACHED_VALUES = {
    ONBEH_SHEET: {
        'E2': 'Mailen',                 # Roepnaam
        'AE2': 'Herbeoordeling',        # Rappelreden
        'AH2': 'Ja',                    # Locatie BIOToets
        'AI2': 'Geen',                  # Software Suite
    },
    BEHEERDE_SHEET: {
        'E2': 'Naamtest',               # Roepnaam
        'I2': 'Saas',                   # Applicatiesoort
        'AB2': 'Ja',                    # Cloud (IF(Applicatiesoort="Saas","Ja","Nee"))
        'L2': 'Teamleider Applicatiebeheer',  # Applicatie Eigenaar (Functie)
        'M2': 'Teamleider Applicatiebeheer',  # Applicatie Eigenaar (Persoon): no Eigenaar, so the function
        'AF2': 'Herbeoordeling',        # Rappelreden
        'AH2': 'BBN2',                  # BNN Classificatie
        'AI2': 'Ja',                    # Locatie BIOToets
        'AM2': 'NT123',                 # Nickname (no cell in the export; appended)
    },
}


def read_package(path):
    """Return the package as an ordered list of (name, bytes)."""
    with zipfile.ZipFile(path) as package:
        return [(info.filename, package.read(info.filename)) for info in package.infolist()]


def write_package(path, parts):
    """Write (name, bytes) parts as a deflated zip, [Content_Types].xml first."""
    parts = sorted(parts, key=lambda item: item[0] != '[Content_Types].xml')
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as package:
        for name, data in parts:
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(info, data)


def text(data):
    return data.decode('utf-8')


def empty_element(xml, tag):
    """Empty `<tag>` (any content, or self-closing) without dropping it."""
    return re.sub(r'<%s(?: [^>]*)?(?:/>|>.*?</%s>)' % (tag, tag), '<%s></%s>' % (tag, tag), xml, flags=re.S)


def drop_guids(xml):
    """Remove the revision pointer and every revision/object GUID attribute (xr:uid, xr10:uidLastSave, ...)."""
    xml = re.sub(r'<xr:revisionPtr [^>]*/>', '', xml)
    return re.sub(r' xr\d*:uid(?:LastSave)?="\{[^"]*\}"', '', xml)


def used_range(xml):
    """The range the cells of a worksheet span ("A1:AP2"), or None for an empty sheet."""
    refs = re.findall(r'<c r="([A-Z]+)(\d+)"', xml)
    if not refs:
        return None
    width = max(col_to_index(col) for col, _ in refs)
    height = max(int(row) for _, row in refs)
    return 'A1:%s%d' % (index_to_col(width), height)


def sanitise_sheet(xml):
    """Printer settings link, GUIDs, and the dimension/filter ranges of the original data."""
    xml = re.sub(r'(<pageSetup [^>]*?) r:id="[^"]*"', r'\1', xml)
    xml = drop_guids(xml)
    extent = used_range(xml)
    if extent is not None:
        xml = re.sub(r'<dimension ref="[^"]*"/>', '<dimension ref="%s"/>' % extent, xml)

    # An auto filter covers the header row only; its original range recorded how many rows the real data had.
    def header_only(match):
        first, last_col = match.group(1), match.group(2)
        row = re.match(r'[A-Z]+(\d+)$', first).group(1)
        return '<autoFilter ref="%s:%s%s"' % (first, last_col, row)
    xml = re.sub(r'<autoFilter ref="([A-Z]+\d+):([A-Z]+)\d+"', header_only, xml)
    # A saved sort order names the ranges of the original data too.
    return re.sub(r'<sortState[ >].*?</sortState>|<sortState [^>]*/>', '', xml, flags=re.S)


def sanitise(parts):
    """Remove metadata parts and every reference to them; idempotent."""
    kept = []
    for name, data in parts:
        if name in DROP_PARTS or name.startswith(DROP_PREFIXES):
            continue
        if name == 'docProps/core.xml':
            xml = text(data)
            for tag in EMPTIED_CORE:
                xml = empty_element(xml, tag)
            for tag in ('dcterms:created', 'dcterms:modified'):
                xml = re.sub(r'(<%s [^>]*>)[^<]*(</%s>)' % (tag, tag), r'\g<1>%s\g<2>' % FIXED_DATE, xml)
            data = xml.encode('utf-8')
        elif name == 'docProps/app.xml':
            xml = text(data)
            for tag in EMPTIED_APP:
                xml = empty_element(xml, tag)
            data = xml.encode('utf-8')
        elif name == 'xl/workbook.xml':
            # The absolute path of the last save names a user profile directory.
            xml = re.sub(r'<mc:AlternateContent[^>]*>.*?x15ac:absPath.*?</mc:AlternateContent>', '', text(data))
            xml = drop_guids(xml)
            # Hidden filter ranges of the original data; Excel rebuilds them from the sheets' auto filters.
            xml = re.sub(r'<definedName name="_xlnm\._FilterDatabase"[^>]*>[^<]*</definedName>', '', xml)
            xml = xml.replace('<definedNames></definedNames>', '')
            data = xml.encode('utf-8')
        elif re.match(r'xl/worksheets/sheet\d+\.xml$', name):
            data = sanitise_sheet(text(data)).encode('utf-8')
        elif name.endswith('.rels'):
            xml = re.sub(r'<Relationship [^>]*Target="docProps/custom\.xml"[^>]*/>', '', text(data))
            xml = re.sub(r'<Relationship [^>]*Target="(?:\.\./)?customXml/[^"]*"[^>]*/>', '', xml)
            xml = re.sub(r'<Relationship [^>]*Target="connections\.xml"[^>]*/>', '', xml)
            xml = re.sub(r'<Relationship [^>]*Type="[^"]*/printerSettings"[^>]*/>', '', xml)
            data = xml.encode('utf-8')
        kept.append((name, data))

    # Content types: no override for a dropped part, no default for an extension no part has left.
    names = {name for name, _ in kept}

    def content_types(data):
        xml = text(data)
        for part in re.findall(r'<Override PartName="/([^"]*)"[^>]*/>', xml):
            if part not in names:
                xml = re.sub(r'<Override PartName="/%s"[^>]*/>' % re.escape(part), '', xml)
        for ext in re.findall(r'<Default Extension="([^"]*)"[^>]*/>', xml):
            if ext not in ('rels', 'xml') and not any(n.endswith('.' + ext) for n in names):
                xml = re.sub(r'<Default Extension="%s"[^>]*/>' % re.escape(ext), '', xml)
        return xml.encode('utf-8')
    return replace_part(kept, '[Content_Types].xml', content_types)


def replace_part(parts, name, transform):
    return [(n, transform(d) if n == name else d) for n, d in parts]


def xml_escape(value):
    return value.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def set_cached_value(xml, ref, value):
    """Give the formula cell `ref` the cached string `value`; append a plain string cell when it is missing."""
    cell = re.search(r'<c r="%s"([^>]*?)(?:/>|>(.*?)</c>)' % ref, xml, flags=re.S)
    cached = '<v>%s</v>' % xml_escape(value)
    if cell is None:
        row = re.match(r'[A-Z]+(\d+)$', ref).group(1)
        row_match = re.search(r'(<row r="%s"[^>]*>.*?)(</row>)' % row, xml, flags=re.S)
        if row_match is None:
            sys.exit('cached values: row %s not found' % row)
        new_cell = '<c r="%s" t="inlineStr"><is><t>%s</t></is></c>' % (ref, xml_escape(value))
        return xml[:row_match.end(1)] + new_cell + xml[row_match.end(1):]
    attrs, body = cell.group(1), cell.group(2) or ''
    formula = re.search(r'<f[^>]*>.*?</f>|<f[^>]*/>', body, flags=re.S)
    if formula is None:
        # Not a formula (an earlier run may have written it): only the value changes.
        if ' t="inlineStr"' in attrs:
            return xml[:cell.start()] + '<c r="%s"%s><is><t>%s</t></is></c>' % (ref, attrs, xml_escape(value)) + xml[cell.end():]
        sys.exit('cached values: %s holds no formula' % ref)
    attrs = re.sub(r' t="\w+"', '', attrs) + ' t="str"'
    return xml[:cell.start()] + '<c r="%s"%s>%s%s</c>' % (ref, attrs, formula.group(0), cached) + xml[cell.end():]


def cache_values(parts):
    """Write CACHED_VALUES into the CMDB sheets; running it twice changes nothing."""
    for sheet, cells in CACHED_VALUES.items():
        def transform(data, cells=cells):
            xml = text(data)
            for ref, value in cells.items():
                xml = set_cached_value(xml, ref, value)
            return xml.encode('utf-8')
        parts = replace_part(parts, sheet, transform)
    # Excel recalculates from calcChain on open; the cached values are what matter here.
    return parts


def missing_appid(parts):
    """'Beheerde Applicaties CMDB' loses its APPID header (the column gets another name)."""
    def transform(data):
        xml = text(data)
        new, count = re.subn(
            r'<c r="B1"([^>]*?) t="s"([^>]*)><v>\d+</v></c>',
            r'<c r="B1"\1 t="inlineStr"\2><is><t>Applicatienummer</t></is></c>',
            xml,
            count=1,
        )
        if count != 1:
            sys.exit('missing-appid: header cell B1 not found on Beheerde Applicaties CMDB')
        return new.encode('utf-8')
    return replace_part(parts, BEHEERDE_SHEET, transform)


def col_to_index(col):
    index = 0
    for char in col:
        index = index * 26 + (ord(char) - 64)
    return index


def index_to_col(index):
    col = ''
    while index > 0:
        index, rem = divmod(index - 1, 26)
        col = chr(65 + rem) + col
    return col


def reverse_columns(xml):
    """Mirror the column order of a worksheet: the last column becomes A."""
    dim = re.search(r'<dimension ref="A1:([A-Z]+)\d+"/>', xml)
    width = col_to_index(dim.group(1))
    # Elements that address columns or ranges; they are not needed by the reader.
    for tag in ('cols', 'hyperlinks', 'autoFilter', 'conditionalFormatting', 'dataValidations', 'mergeCells'):
        xml = re.sub(r'<%s[ >].*?</%s>' % (tag, tag), '', xml, flags=re.S)
        xml = re.sub(r'<%s [^>]*/>' % tag, '', xml)
    xml = re.sub(r'<dimension [^>]*/>', '', xml)

    def flip_row(match):
        row_open, body = match.group(1), match.group(2)
        row_open = re.sub(r' spans="[^"]*"', '', row_open)
        cells = re.findall(r'<c r="[A-Z]+\d+"[^>]*?(?:/>|>.*?</c>)', body, flags=re.S)
        flipped = []
        for cell in cells:
            ref = re.match(r'<c r="([A-Z]+)(\d+)"', cell)
            new_col = index_to_col(width + 1 - col_to_index(ref.group(1)))
            flipped.append((col_to_index(new_col), cell.replace('r="%s%s"' % (ref.group(1), ref.group(2)), 'r="%s%s"' % (new_col, ref.group(2)), 1)))
        flipped.sort(key=lambda item: item[0])
        return row_open + ''.join(cell for _, cell in flipped) + '</row>'

    return re.sub(r'(<row [^>]*>)(.*?)</row>', flip_row, xml, flags=re.S)


def shuffled_columns(parts):
    """Both CMDB sheets with their columns reversed ("Applicatie Naam" before "APPID")."""
    parts = replace_part(parts, ONBEH_SHEET, lambda d: reverse_columns(text(d)).encode('utf-8'))
    parts = replace_part(parts, BEHEERDE_SHEET, lambda d: reverse_columns(text(d)).encode('utf-8'))

    # A referenced header with the decoration TOPdesk adds to computed fields.
    def decorate(data):
        xml = text(data)
        xml, count = re.subn(r'<si><t>Vendor</t></si>', '<si><t>Vendor⚡</t></si>', xml, count=1)
        if count != 1:
            sys.exit('shuffled-columns: shared string "Vendor" not found')
        return xml.encode('utf-8')
    return replace_part(parts, 'xl/sharedStrings.xml', decorate)


SYNTHETIC_CONNECTION = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
    '<connections xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    '<connection id="1" name="Synthetic web query" type="4" refreshOnLoad="1" refreshedVersion="6" background="1" saveData="1">'
    '<webPr sourceData="1" parsePre="1" consecutive="1" xl2000="1" url="https://example.invalid/cmdb-export"/>'
    '</connection></connections>'
)


def formula_and_connection(parts):
    """On "Beheerde Applicaties CMDB": a formula in "Applicatie Naam" whose cached value
    differs from its result, a "Roepnaam" formula without any cached value, plus an
    external connection."""
    def formula(data):
        xml = text(data)
        new, count = re.subn(
            r'<c r="D2"([^>]*?) t="str"([^>]*)><f>[^<]*</f><v>[^<]*</v></c>',
            r'<c r="D2"\1 t="str"\2><f>"Evaluated"</f><v>Rekenmodel</v></c>',
            xml,
            count=1,
        )
        if count != 1:
            sys.exit('formula-and-connection: formula cell D2 not found on Beheerde Applicaties CMDB')
        new, count = re.subn(
            r'<c r="E2"([^>]*?)><f>([^<]*)</f><v>[^<]*</v></c>',
            r'<c r="E2"\1><f>\2</f></c>',
            new,
            count=1,
        )
        if count != 1:
            sys.exit('formula-and-connection: formula cell E2 not found on Beheerde Applicaties CMDB')
        return new.encode('utf-8')

    parts = replace_part(parts, BEHEERDE_SHEET, formula)
    parts = replace_part(
        parts,
        '[Content_Types].xml',
        lambda d: text(d).replace(
            '</Types>',
            '<Override PartName="/xl/connections.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.connections+xml"/></Types>',
        ).encode('utf-8'),
    )
    parts = replace_part(
        parts,
        'xl/_rels/workbook.xml.rels',
        lambda d: text(d).replace(
            '</Relationships>',
            '<Relationship Id="rId99" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/connections" Target="connections.xml"/></Relationships>',
        ).encode('utf-8'),
    )
    return parts + [('xl/connections.xml', SYNTHETIC_CONNECTION.encode('utf-8'))]


def sheet_part(parts, sheet_name):
    """The worksheet part of a named sheet, through xl/workbook.xml and its relationships."""
    parts_by_name = dict(parts)
    workbook = text(parts_by_name['xl/workbook.xml'])
    sheet = re.search(r'<sheet [^>]*name="%s"[^>]*r:id="([^"]+)"' % re.escape(sheet_name), workbook)
    if sheet is None:
        sys.exit('sheet "%s" not found' % sheet_name)
    rels = text(parts_by_name['xl/_rels/workbook.xml.rels'])
    target = re.search(r'<Relationship [^>]*Id="%s"[^>]*Target="([^"]+)"' % sheet.group(1), rels)
    if target is None:
        target = re.search(r'<Relationship [^>]*Target="([^"]+)"[^>]*Id="%s"' % sheet.group(1), rels)
    return 'xl/' + target.group(1).lstrip('/').replace('xl/', '', 1)


def archived_applications(parts):
    """A later export of the same municipality (cmdb-import-archive-reconciliation):
    APPID 1234 moved from "Onbeh Applicaties CMDB" to "Gearchiveerde Applicaties",
    and APPID 2 is on no sheet any more. Both CMDB sheets keep their header and
    their empty rows but lose their data row; the archive sheet's one row gets
    APPID 1234 as its cached value."""
    def drop_data_row(data):
        xml = text(data)
        new, count = re.subn(r'<row r="2"[^>]*?(?:/>|>.*?</row>)', '', xml, count=1, flags=re.S)
        if count != 1:
            sys.exit('archived-applications: data row 2 not found')
        return new.encode('utf-8')

    def archive_appid(data):
        xml = text(data)
        new, count = re.subn(
            r'(<c r="B2"[^>]*>(?:<f>[^<]*</f>)?<v>)[^<]*(</v>)',
            r'\g<1>1234\g<2>',
            xml,
            count=1,
        )
        if count != 1:
            sys.exit('archived-applications: APPID cell B2 not found on %s' % ARCHIVE_SHEET_NAME)
        return new.encode('utf-8')

    parts = replace_part(parts, ONBEH_SHEET, drop_data_row)
    parts = replace_part(parts, BEHEERDE_SHEET, drop_data_row)
    return replace_part(parts, sheet_part(parts, ARCHIVE_SHEET_NAME), archive_appid)


def no_source_sheet():
    """A minimal workbook with one sheet "Blad1" and neither CMDB sheet."""
    main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
    rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
    pkg = 'http://schemas.openxmlformats.org/package/2006/relationships'
    return [
        ('[Content_Types].xml', (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            '<Default Extension="xml" ContentType="application/xml"/>'
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            '</Types>').encode('utf-8')),
        ('_rels/.rels', (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            '<Relationships xmlns="%s"><Relationship Id="rId1" Type="%s/officeDocument" Target="xl/workbook.xml"/></Relationships>'
            % (pkg, rel)).encode('utf-8')),
        ('xl/workbook.xml', (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            '<workbook xmlns="%s" xmlns:r="%s"><sheets><sheet name="Blad1" sheetId="1" r:id="rId1"/></sheets></workbook>'
            % (main, rel)).encode('utf-8')),
        ('xl/_rels/workbook.xml.rels', (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            '<Relationships xmlns="%s"><Relationship Id="rId1" Type="%s/worksheet" Target="worksheets/sheet1.xml"/></Relationships>'
            % (pkg, rel)).encode('utf-8')),
        ('xl/worksheets/sheet1.xml', (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            '<worksheet xmlns="%s"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Naam</t></is></c></row>'
            '<row r="2"><c r="A2" t="inlineStr"><is><t>Voorbeeld</t></is></c></row></sheetData></worksheet>'
            % main).encode('utf-8')),
    ]


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--source', help='anonymised export to sanitise into topdesk-export-anonymised.xlsx')
    args = parser.parse_args()

    parts = sanitise(read_package(args.source or SANITISED))
    write_package(SANITISED, cache_values(parts))
    print('wrote', os.path.relpath(SANITISED, HERE))

    base = read_package(SANITISED)
    variants = {
        'topdesk-missing-appid.xlsx': missing_appid(base),
        'topdesk-shuffled-columns.xlsx': shuffled_columns(base),
        'topdesk-formula-and-connection.xlsx': formula_and_connection(base),
        'topdesk-no-source-sheet.xlsx': no_source_sheet(),
        'topdesk-archived-applications.xlsx': archived_applications(base),
    }
    for name, parts in variants.items():
        write_package(os.path.join(HERE, name), parts)
        print('wrote', name)


if __name__ == '__main__':
    main()
