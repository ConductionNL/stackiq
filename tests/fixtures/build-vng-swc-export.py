#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Build vng-swc-export.xlsx and vng-swc-export.csv (landscape-migrate-from-previous-catalogue, Task 1).

A small export in the shape of the previous VNG Softwarecatalogus export the
vng-swc import profile reads: one sheet "Applicaties", one row per application
in use. Every name, number and id is made up. The "Contactpersoon" column is
not in the profile, so the import must never read it.

Python standard library only (zipfile), with inline strings, so the package
stays small and byte-stable. Run from any directory:

    python3 tests/fixtures/build-vng-swc-export.py
"""

import csv
import io
import os
import zipfile
from xml.sax.saxutils import escape

HERE = os.path.dirname(os.path.abspath(__file__))
FIXED_DATE = (2026, 1, 1, 0, 0, 0)

HEADER = [
    "Gebruik ID", "Leverancier ID", "Leverancier", "Leverancier KvK", "Pakket ID", "Pakket",
    "Pakket omschrijving", "Versie", "Status", "Referentiecomponenten", "Koppelingen", "Toelichting", "Contactpersoon",
]
ROWS = [
    ["1001", "L-10", "Voorbeeld Software B.V.", "01234567", "P-100", "Voorbeeld Financien", "Financiele administratie",
     "2026.1", "In gebruik", "Financieel systeem", "", "", "Persoon Een"],
    ["1002", "L-11", "Voorbeeld Leverancier B.V.", "12345678", "P-200", "Voorbeeld Zaken", "",
     "3.4", "Gepland", "Zaaksysteem; Documentbeheersysteem", "Voorbeeld koppeling", "Na de aanbesteding", "Persoon Twee"],
    ["1003", "L-12", "Nieuwe Leverancier", "", "P-300", "Onbekend Pakket", "",
     "", "Uitgefaseerd", "", "", "", ""],
]


def column_letters(index):
    letters = ""
    index += 1
    while index > 0:
        index, rest = divmod(index - 1, 26)
        letters = chr(65 + rest) + letters
    return letters


def sheet_xml(rows):
    out = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
           '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>']
    for number, row in enumerate(rows, start=1):
        out.append('<row r="%d">' % number)
        for index, value in enumerate(row):
            if value == "":
                continue
            ref = "%s%d" % (column_letters(index), number)
            out.append('<c r="%s" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>' % (ref, escape(value)))
        out.append('</row>')
    out.append('</sheetData></worksheet>')
    return "".join(out)


PARTS = {
    "[Content_Types].xml": '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    '<Default Extension="xml" ContentType="application/xml"/>'
    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    '</Types>',
    "_rels/.rels": '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    '</Relationships>',
    "xl/workbook.xml": '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
    'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    '<sheets><sheet name="Applicaties" sheetId="1" r:id="rId1"/></sheets></workbook>',
    "xl/_rels/workbook.xml.rels": '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    '</Relationships>',
    "xl/worksheets/sheet1.xml": sheet_xml([HEADER] + ROWS),
}


def main():
    with zipfile.ZipFile(os.path.join(HERE, "vng-swc-export.xlsx"), "w", zipfile.ZIP_DEFLATED) as package:
        for name, content in PARTS.items():
            info = zipfile.ZipInfo(name, date_time=FIXED_DATE)
            info.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(info, content)

    buffer = io.StringIO()
    writer = csv.writer(buffer, delimiter=";", lineterminator="\r\n")
    writer.writerow(HEADER)
    writer.writerows(ROWS)
    with open(os.path.join(HERE, "vng-swc-export.csv"), "w", encoding="utf-8", newline="") as handle:
        handle.write(buffer.getvalue())


if __name__ == "__main__":
    main()
