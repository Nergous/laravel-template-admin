<?php

namespace App\Support\Export;

use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * A minimal XLSX writer: one sheet, a bold frozen header row, cells with
 * text or numbers. Rows are streamed into a temporary file, so a large
 * export does not have to fit in memory. Uses the zip and xmlwriter
 * extensions; formulas are never written, so cell values are never
 * evaluated by Excel as formulas.
 */
final class XlsxWriter implements TableWriter
{
    private const MAX_CELL_LENGTH = 32767;

    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** Bold font style index in styles.xml. */
    private const HEADER_STYLE = 1;

    public function write($out, array $header, iterable $rows, string $title): int
    {
        $sheetPath = self::tempFile('xlsx-sheet');
        $bookPath = self::tempFile('xlsx');

        try {
            $count = self::writeSheet($sheetPath, $header, $rows);
            self::writeBook($bookPath, $sheetPath, $title);
            self::copy($bookPath, $out);

            return $count;
        } finally {
            @unlink($sheetPath);
            @unlink($bookPath);
        }
    }

    private static function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный файл для XLSX');
        }

        return $path;
    }

    /** Assembles the workbook from the finished sheet and the service parts. */
    private static function writeBook(string $path, string $sheetPath, string $title): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать архив XLSX');
        }
        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($title));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        if (! $zip->close()) {
            throw new RuntimeException('Не удалось записать архив XLSX');
        }
    }

    /** @param  resource  $out */
    private static function copy(string $path, $out): void
    {
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new RuntimeException('Не удалось прочитать готовый файл XLSX');
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
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

        // The header stays in place while scrolling.
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
        self::writeHeader($xml, $header);
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

    /** @param  list<string>  $header */
    private static function writeHeader(XMLWriter $xml, array $header): void
    {
        self::writeRow($xml, 1, $header, self::HEADER_STYLE);
    }

    /**
     * @param  list<string|int|float|null>  $cells
     * @param  int|null  $style  Cell style index from styles.xml; null — default
     */
    private static function writeRow(XMLWriter $xml, int $number, array $cells, ?int $style = null): void
    {
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $number);

        foreach (array_values($cells) as $index => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $xml->startElement('c');
            $xml->writeAttribute('r', self::column($index).$number);
            if ($style !== null) {
                $xml->writeAttribute('s', (string) $style);
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

    /** Column letters by zero-based index: 0 → A, 26 → AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** Strips characters invalid in XML and trims the text to the Excel cell limit. */
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

    private static function workbook(string $title): string
    {
        // Excel limits a sheet name to 31 characters without []:*?/\.
        $name = htmlspecialchars(mb_substr((string) preg_replace('/[\[\]:*?\/\\\\]/', '', $title), 0, 31) ?: 'Лист1', ENT_XML1);

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
