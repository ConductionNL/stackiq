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
        Sanitise an already anonymised export into topdesk-export-anonymised.xlsx
        (strip document metadata, custom properties, customXml, the workbook's
        absolute path and xl/connections.xml), then derive the variants.

    python3 build-fixtures.py
        Derive the variants from the committed topdesk-export-anonymised.xlsx.

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
DROP_PREFIXES = ('customXml/',)

AIA_SHEET = 'xl/worksheets/sheet1.xml'
APP_SHEET = 'xl/worksheets/sheet3.xml'


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


def sanitise(parts):
    """Remove metadata parts and every reference to them."""
    kept = []
    for name, data in parts:
        if name in DROP_PARTS or name.startswith(DROP_PREFIXES):
            continue
        if name == 'docProps/core.xml':
            xml = text(data)
            xml = re.sub(r'<dc:creator>.*?</dc:creator>', '<dc:creator></dc:creator>', xml)
            xml = re.sub(r'<cp:lastModifiedBy>.*?</cp:lastModifiedBy>', '<cp:lastModifiedBy></cp:lastModifiedBy>', xml)
            data = xml.encode('utf-8')
        elif name == 'xl/workbook.xml':
            # The absolute path of the last save names a user profile directory.
            xml = re.sub(r'<mc:AlternateContent[^>]*>.*?x15ac:absPath.*?</mc:AlternateContent>', '', text(data))
            data = xml.encode('utf-8')
        elif name == '[Content_Types].xml':
            xml = text(data)
            xml = re.sub(r'<Override PartName="/docProps/custom\.xml"[^>]*/>', '', xml)
            xml = re.sub(r'<Override PartName="/xl/connections\.xml"[^>]*/>', '', xml)
            xml = re.sub(r'<Override PartName="/customXml/[^"]*"[^>]*/>', '', xml)
            data = xml.encode('utf-8')
        elif name == '_rels/.rels':
            xml = re.sub(r'<Relationship [^>]*Target="docProps/custom\.xml"[^>]*/>', '', text(data))
            xml = re.sub(r'<Relationship [^>]*Target="\.\./customXml/[^"]*"[^>]*/>', '', xml)
            data = xml.encode('utf-8')
        elif name == 'xl/_rels/workbook.xml.rels':
            xml = re.sub(r'<Relationship [^>]*Target="connections\.xml"[^>]*/>', '', text(data))
            xml = re.sub(r'<Relationship [^>]*Target="\.\./customXml/[^"]*"[^>]*/>', '', xml)
            data = xml.encode('utf-8')
        kept.append((name, data))
    return kept


def replace_part(parts, name, transform):
    return [(n, transform(d) if n == name else d) for n, d in parts]


def missing_middel_id(parts):
    """'Invoer APP data' loses its Middel-ID header (the column gets another name)."""
    def transform(data):
        xml = text(data)
        new, count = re.subn(
            r'<c r="B1"([^>]*?) t="s"([^>]*)><v>\d+</v></c>',
            r'<c r="B1"\1 t="inlineStr"\2><is><t>Middelnummer</t></is></c>',
            xml,
            count=1,
        )
        if count != 1:
            sys.exit('missing-middel-id: header cell B1 not found on Invoer APP data')
        return new.encode('utf-8')
    return replace_part(parts, APP_SHEET, transform)


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
    """Both source sheets with their columns reversed ("Naam" before "Middel-ID")."""
    parts = replace_part(parts, AIA_SHEET, lambda d: reverse_columns(text(d)).encode('utf-8'))
    parts = replace_part(parts, APP_SHEET, lambda d: reverse_columns(text(d)).encode('utf-8'))

    # A referenced header with the decoration TOPdesk adds to computed fields.
    def decorate(data):
        xml = text(data)
        xml, count = re.subn(r'<si><t>Eigenaar e-mail</t></si>', '<si><t>Eigenaar e-mail⚡</t></si>', xml, count=1)
        if count != 1:
            sys.exit('shuffled-columns: shared string "Eigenaar e-mail" not found')
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
    """A formula in "Naam" whose cached value differs from its result, plus an external connection."""
    def formula(data):
        xml = text(data)
        new, count = re.subn(
            r'<c r="BB2"([^>]*?) t="s"([^>]*)><v>\d+</v></c>',
            r'<c r="BB2"\1 t="str"\2><f>"Evaluated"</f><v>Rekenmodel</v></c>',
            xml,
            count=1,
        )
        if count != 1:
            sys.exit('formula-and-connection: cell BB2 not found on Invoer APP data')
        return new.encode('utf-8')

    parts = replace_part(parts, APP_SHEET, formula)
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


def no_source_sheet():
    """A minimal workbook with one sheet "Blad1" and neither source sheet."""
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

    if args.source:
        write_package(SANITISED, sanitise(read_package(args.source)))
        print('wrote', os.path.relpath(SANITISED, HERE))

    base = read_package(SANITISED)
    variants = {
        'topdesk-missing-middel-id.xlsx': missing_middel_id(base),
        'topdesk-shuffled-columns.xlsx': shuffled_columns(base),
        'topdesk-formula-and-connection.xlsx': formula_and_connection(base),
        'topdesk-no-source-sheet.xlsx': no_source_sheet(),
    }
    for name, parts in variants.items():
        write_package(os.path.join(HERE, name), parts)
        print('wrote', name)


if __name__ == '__main__':
    main()
