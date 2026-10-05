<?php

namespace App\Support;

use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * Minimal XLSX writer: one sheet, a bold frozen header row and text or number
 * cells. Rows are streamed to a temporary file, so large exports do not have to
 * fit in memory. Built on the zip and xmlwriter extensions; no formulas are
 * ever written, so cell values cannot run as formulas in Excel.
 */
final class XlsxWriter
{
    private const MAX_CELL_LENGTH = 32767;

    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * Writes the workbook to $path and returns the number of data rows.
     *
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public static function write(string $path, array $header, iterable $rows, string $sheetName = 'Лист1'): int
    {
        $sheetPath = tempnam(sys_get_temp_dir(), 'xlsx-sheet');
        if ($sheetPath === false) {
            throw new RuntimeException('Cannot create a temporary file for the XLSX sheet');
        }

        try {
            $count = self::writeSheet($sheetPath, $header, $rows);

            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the XLSX archive');
            }
            $zip->addFromString('[Content_Types].xml', self::contentTypes());
            $zip->addFromString('_rels/.rels', self::rootRels());
            $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
            $zip->addFromString('xl/styles.xml', self::styles());
            $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
            $zip->close();

            return $count;
        } finally {
            @unlink($sheetPath);
        }
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    private static function writeSheet(string $path, array $header, iterable $rows): int
    {
        $xml = new XMLWriter;
        $xml->openUri($path);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', self::MAIN_NS);

        // Keep the header visible while scrolling.
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('ySplit', '1');
        $xml->writeAttribute('topLeftCell', 'A2');
        $xml->writeAttribute('activePane', 'bottomLeft');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();

        if ($header !== []) {
            $xml->startElement('cols');
            $xml->startElement('col');
            $xml->writeAttribute('min', '1');
            $xml->writeAttribute('max', (string) count($header));
            $xml->writeAttribute('width', '22');
            $xml->writeAttribute('customWidth', '1');
            $xml->endElement();
            $xml->endElement();
        }

        $xml->startElement('sheetData');
        self::writeRow($xml, 1, $header, bold: true);
        $count = 0;
        foreach ($rows as $row) {
            $count++;
            self::writeRow($xml, $count + 1, $row);
            if ($count % 500 === 0) {
                $xml->flush();
            }
        }
        $xml->endElement(); // sheetData

        $xml->endElement(); // worksheet
        $xml->endDocument();
        $xml->flush();

        return $count;
    }

    /** @param  list<string|int|float|null>  $cells */
    private static function writeRow(XMLWriter $xml, int $number, array $cells, bool $bold = false): void
    {
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $number);

        foreach (array_values($cells) as $index => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $xml->startElement('c');
            $xml->writeAttribute('r', self::column($index).$number);
            if ($bold) {
                $xml->writeAttribute('s', '1');
            }

            if (is_int($value) || is_float($value)) {
                $xml->writeElement('v', (string) $value);
            } else {
                $xml->writeAttribute('t', 'inlineStr');
                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space', 'preserve');
                $xml->text(self::clean((string) $value));
                $xml->endElement();
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();
    }

    /** Column letters for a zero-based index: 0 → A, 26 → AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** Drops characters XML cannot hold and trims to Excel's cell limit. */
    private static function clean(string $value): string
    {
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        return mb_strlen($value) > self::MAX_CELL_LENGTH ? mb_substr($value, 0, self::MAX_CELL_LENGTH) : $value;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        // Excel limits sheet names to 31 characters without []:*?/\.
        $name = htmlspecialchars(mb_substr((string) preg_replace('/[\[\]:*?\/\\\\]/', '', $sheetName), 0, 31) ?: 'Лист1', ENT_XML1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::MAIN_NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::MAIN_NS.'">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
