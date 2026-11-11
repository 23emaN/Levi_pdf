<?php

namespace App;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Reads chosen columns of an .xlsx workbook straight from its XML (zip + XMLReader), one row at a time, so a sheet of
 * hundreds of columns and thousands of rows never sits in memory. It knows what the VBRP page needs: text, numbers,
 * shared strings and inline strings. Formulas give the value Excel saved with them.
 */
class XlsxReader
{
    private const NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * The sheets of the workbook in the order Excel shows them
     *
     * @return array[] ['name' => sheet name, 'entry' => path of its XML inside the zip]
     */
    public static function sheets(string $path): array
    {
        $zip = self::open($path);
        try {
            $workbook = self::xml($zip, 'xl/workbook.xml');
            $targets = [];
            foreach (self::xml($zip, 'xl/_rels/workbook.xml.rels')->Relationship as $rel) {
                $target = (string)$rel['Target'];
                $targets[(string)$rel['Id']] = $target[0] === '/' ? ltrim($target, '/') : 'xl/' . $target;
            }

            $sheets = [];
            foreach ($workbook->sheets->sheet as $sheet) {
                $id = (string)$sheet->attributes(self::NS_RELATIONSHIPS)['id'];
                if (isset($targets[$id])) {
                    $sheets[] = ['name' => (string)$sheet['name'], 'entry' => $targets[$id]];
                }
            }
            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * The cells of $columns on every row from $startRow down of one sheet. Rows without any of those cells are not
     * given. A cell holds its text (numbers as Excel wrote them) or null when empty.
     *
     * @param string[] $columns column letters, upper case (A, CI, EM)
     * @return \Generator row number => [column letter => string|null]
     */
    public static function rows(string $path, string $entry, array $columns, int $startRow): \Generator
    {
        $zip = self::open($path);
        $tmp = null;
        $xml = null;
        try {
            $strings = self::sharedStrings($zip);
            $tmp = self::extract($zip, $entry);
            $xml = new XMLReader();
            if (!$xml->open($tmp, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                throw new RuntimeException('อ่านชีตใน Excel ไม่ได้');
            }

            $wanted = array_flip($columns);
            $rowNo = 0;
            $cells = [];
            $ok = $xml->read();
            while ($ok) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'row') {
                    $given = (int)$xml->getAttribute('r');
                    $rowNo = $given > 0 ? $given : $rowNo + 1;
                    $cells = [];
                    if ($rowNo < $startRow || $xml->isEmptyElement) {
                        $ok = $xml->next();
                        continue;
                    }
                } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'c') {
                    $column = self::columnOf((string)$xml->getAttribute('r'));
                    if (!isset($wanted[$column])) {
                        $ok = $xml->next();
                        continue;
                    }
                    $cells[$column] = self::cellValue($xml, $strings);
                } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->name === 'row') {
                    if (array_filter($cells, function ($value) {
                        return $value !== null;
                    })) {
                        yield $rowNo => $cells;
                    }
                    $cells = [];
                }
                $ok = $xml->read();
            }
        } finally {
            if ($xml !== null) {
                $xml->close();
            }
            if ($tmp !== null) {
                @unlink($tmp);
            }
            $zip->close();
        }
    }

    /**
     * The value of the <c> element the reader stands on; leaves the reader on its end tag
     */
    private static function cellValue(XMLReader $xml, array $strings): ?string
    {
        $type = (string)$xml->getAttribute('t');
        if ($xml->isEmptyElement) {
            return null;
        }

        $depth = $xml->depth;
        $value = null;
        $inline = '';
        $inPhonetic = false;
        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) {
                break;
            }
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'v') {
                $value = $xml->readString();
            } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'rPh') {
                $inPhonetic = true;
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->name === 'rPh') {
                $inPhonetic = false;
            } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 't' && !$inPhonetic) {
                $inline .= $xml->readString();
            }
        }

        if ($type === 'inlineStr') {
            $value = $inline;
        } elseif ($type === 's') {
            $value = $value === null ? null : ($strings[(int)$value] ?? null);
        } elseif ($type === 'b') {
            $value = $value === null ? null : ($value === '1' ? 'TRUE' : 'FALSE');
        } elseif ($type === 'e') {
            $value = null;
        }

        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Shared strings by index; the phonetic guides of Asian text (<rPh>) are not part of the text
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $tmp = self::extract($zip, 'xl/sharedStrings.xml');
        $xml = new XMLReader();
        $strings = [];
        try {
            if (!$xml->open($tmp, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                throw new RuntimeException('อ่านข้อความใน Excel ไม่ได้');
            }
            while ($xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->name !== 'si') {
                    continue;
                }
                $text = '';
                if (!$xml->isEmptyElement) {
                    $depth = $xml->depth;
                    $inPhonetic = false;
                    while ($xml->read()) {
                        if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) {
                            break;
                        }
                        if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'rPh') {
                            $inPhonetic = true;
                        } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->name === 'rPh') {
                            $inPhonetic = false;
                        } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 't' && !$inPhonetic) {
                            $text .= $xml->readString();
                        }
                    }
                }
                $strings[] = $text;
            }
        } finally {
            $xml->close();
            @unlink($tmp);
        }
        return $strings;
    }

    private static function columnOf(string $reference): string
    {
        return preg_match('/^[A-Za-z]+/', $reference, $m) ? strtoupper($m[0]) : '';
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('ไม่ใช่ไฟล์ Excel (.xlsx) หรือไฟล์เสียหาย');
        }
        return $zip;
    }

    private static function xml(ZipArchive $zip, string $entry): \SimpleXMLElement
    {
        $content = $zip->getFromName($entry);
        $xml = $content === false ? false : @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('ไม่ใช่ไฟล์ Excel (.xlsx) หรือไฟล์เสียหาย');
        }
        return $xml;
    }

    /**
     * Copies one entry of the zip to a temporary file (the path of a file name may hold characters the zip:// wrapper
     * cannot take) and returns its path
     */
    private static function extract(ZipArchive $zip, string $entry): string
    {
        $in = $zip->getStream($entry);
        if ($in === false) {
            throw new RuntimeException('ไม่พบข้อมูลชีตในไฟล์ Excel');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);
        return $tmp;
    }
}

