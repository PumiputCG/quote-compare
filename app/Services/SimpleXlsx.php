<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * เขียนไฟล์ Excel (.xlsx) แบบง่ายๆ ด้วย ZipArchive ที่ PHP มีอยู่แล้ว
 *
 * ไฟล์ .xlsx จริงๆ คือไฟล์ zip ที่ข้างในเป็น XML หลายไฟล์ · เราสร้างเองได้
 * จึงไม่ต้องลง PhpSpreadsheet (ตัวใหญ่ ~30MB และเซิร์ฟเวอร์รัน composer ไม่ได้)
 *
 * รองรับเท่าที่รายงานนี้ต้องใช้: 1 ชีต · หัวตารางตัวหนา · ตัดคำในเซลล์ · กำหนดความกว้างคอลัมน์
 * ข้อความทั้งหมดเก็บแบบ inline string จึงไม่ต้องทำตาราง sharedStrings
 */
class SimpleXlsx
{
    /** สไตล์ที่ใช้ได้ในเซลล์ */
    public const PLAIN = 0;

    public const HEADER = 1;

    public const WRAP = 2;

    /** ตัดคำ + จัดกึ่งกลางทั้งแนวนอนและแนวตั้ง (ใช้กับคอลัมน์สถานะ) */
    public const WRAP_CENTER = 3;

    /**
     * @param  array<int,string>  $headers
     * @param  array<int,array<int,string|int|float|null>>  $rows
     * @param  array<int,int>  $widths  ความกว้างคอลัมน์ (จำนวนตัวอักษรโดยประมาณ)
     * @param  array<int,int>  $columnStyles  คอลัมน์ (เริ่มที่ 0) => สไตล์ที่ใช้กับเซลล์ในคอลัมน์นั้น
     */
    public static function write(
        string $path,
        array $headers,
        array $rows,
        array $widths = [],
        array $columnStyles = [],
        string $sheetName = 'Sheet1',
    ): string {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('สร้างไฟล์ Excel ไม่ได้');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($headers, $rows, $widths, $columnStyles));

        $zip->close();

        return $path;
    }

    /** เลขคอลัมน์ (เริ่ม 0) เป็นตัวอักษรแบบ Excel : 0=A · 25=Z · 26=AA */
    private static function column(int $index): string
    {
        $letters = '';

        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $letters = chr(65 + ($i - 1) % 26).$letters;
        }

        return $letters;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * @param  array<int,string>  $headers
     * @param  array<int,array<int,string|int|float|null>>  $rows
     * @param  array<int,int>  $widths
     * @param  array<int,int>  $columnStyles
     */
    private static function sheet(array $headers, array $rows, array $widths, array $columnStyles): string
    {
        $cols = '';

        foreach ($widths as $index => $width) {
            $cols .= sprintf('<col min="%d" max="%d" width="%d" customWidth="1"/>', $index + 1, $index + 1, $width);
        }

        $body = self::row(1, $headers, self::HEADER, []);

        foreach ($rows as $offset => $values) {
            $body .= self::row($offset + 2, $values, self::PLAIN, $columnStyles);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.$body.'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  array<int,string|int|float|null>  $values
     * @param  array<int,int>  $columnStyles
     */
    private static function row(int $number, array $values, int $style, array $columnStyles): string
    {
        $cells = '';

        foreach (array_values($values) as $index => $value) {
            $reference = self::column($index).$number;
            $cellStyle = $style === self::HEADER ? self::HEADER : ($columnStyles[$index] ?? self::PLAIN);

            if ($value === null || $value === '') {
                $cells .= sprintf('<c r="%s" s="%d"/>', $reference, $cellStyle);

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $cells .= sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $cellStyle, $value);

                continue;
            }

            $cells .= sprintf(
                '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                $reference,
                $cellStyle,
                self::escape((string) $value),
            );
        }

        return sprintf('<row r="%d"%s>%s</row>', $number, $number === 1 ? ' ht="22" customHeight="1"' : '', $cells);
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
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape($sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
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
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Tahoma"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF1F3A34"/><name val="Tahoma"/></font>'
            .'</fonts>'
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE7F0ED"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top/><bottom style="thin"><color rgb="FFB9C7C3"/></bottom><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
