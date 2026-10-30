<?php
/**
 * อ่านไฟล์ Excel (.xlsx) แผ่นงานแรกเป็นแถวของข้อความ — ไม่ต้องใช้ไลบรารีภายนอก (ใช้ ext-zip + XMLReader)
 * ป้องกัน: จำกัดขนาดไฟล์/ขนาดหลังแตกไฟล์/จำนวนแถว, ไม่โหลดเอนทิตีภายนอก (XXE), ไม่รันมาโคร (xlsx ไม่มีมาโคร)
 */

const XLSX_MAX_FILE = 10 * 1024 * 1024;
const XLSX_MAX_PART = 60 * 1024 * 1024;
const XLSX_MAX_ROWS = 50000;
const XLSX_MAX_COLS = 60;

function xlsx_available(): bool
{
    return class_exists('ZipArchive') && class_exists('XMLReader');
}

function xlsx_col_index(string $ref): int
{
    preg_match('/^[A-Z]+/', strtoupper($ref), $m);
    $n = 0;
    foreach (str_split($m[0] ?? 'A') as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** อ่านข้อความทั้งก้อนของส่วนใน zip ตามขนาดที่จำกัด */
function xlsx_part(ZipArchive $zip, string $name): ?string
{
    $st = $zip->statName($name);
    if ($st === false) {
        return null;
    }
    if ($st['size'] > XLSX_MAX_PART) {
        throw new RuntimeException('ไฟล์ Excel มีส่วนที่ใหญ่ผิดปกติ');
    }
    $data = $zip->getFromName($name);
    return $data === false ? null : $data;
}

function xlsx_xml_reader(string $xml): XMLReader
{
    $r = new XMLReader();
    if (!$r->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE * 0)) {
        throw new RuntimeException('ไฟล์ Excel อ่านโครงสร้างไม่ได้');
    }
    return $r;
}

/** ข้อความของ <si> / <is> (รวมทุก <t> ยกเว้นส่วนอ่านออกเสียง rPh) */
function xlsx_read_text(XMLReader $r, string $endTag): string
{
    $out = '';
    $depth = $r->depth;
    $inPh = false;
    while ($r->read()) {
        if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === $endTag && $r->depth === $depth) {
            break;
        }
        if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'rPh') {
            $inPh = true;
        } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'rPh') {
            $inPh = false;
        } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 't' && !$inPh && !$r->isEmptyElement) {
            $out .= $r->readString();
        }
    }
    return $out;
}

/**
 * @return string[][] แถวของเซลล์ (index 0 = คอลัมน์ A) แถวว่างทั้งแถวถูกข้าม
 * @throws RuntimeException เมื่อไฟล์ไม่ใช่ .xlsx ที่อ่านได้
 */
function xlsx_read_rows(string $path): array
{
    if (!xlsx_available()) {
        throw new RuntimeException('เซิร์ฟเวอร์ยังไม่ได้เปิดส่วนขยาย PHP zip จึงอ่านไฟล์ Excel ไม่ได้ (ติดตั้ง php-zip หรือแปลงเป็น CSV)');
    }
    if (!is_file($path) || filesize($path) > XLSX_MAX_FILE) {
        throw new RuntimeException('ไฟล์ใหญ่เกินไป (ไม่เกิน 10 MB)');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true || $zip->statName('xl/workbook.xml') === false) {
        throw new RuntimeException('ไม่ใช่ไฟล์ Excel (.xlsx) ที่ถูกต้อง');
    }
    try {
        // ชื่อไฟล์แผ่นงานแรก
        $sheetPath = null;
        $wb = xlsx_part($zip, 'xl/workbook.xml');
        $rels = xlsx_part($zip, 'xl/_rels/workbook.xml.rels');
        if ($wb !== null && $rels !== null) {
            $rid = null;
            $r = xlsx_xml_reader($wb);
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'sheet') {
                    $rid = $r->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    break;
                }
            }
            $r->close();
            if ($rid) {
                $r = xlsx_xml_reader($rels);
                while ($r->read()) {
                    if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship' && $r->getAttribute('Id') === $rid) {
                        $t = (string)$r->getAttribute('Target');
                        $sheetPath = ltrim(str_starts_with($t, '/') ? $t : 'xl/' . $t, '/');
                        break;
                    }
                }
                $r->close();
            }
        }
        if (!$sheetPath || $zip->statName($sheetPath) === false) {
            $sheetPath = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) {
                    $sheetPath = $n;
                    break;
                }
            }
        }
        if (!$sheetPath) {
            throw new RuntimeException('ไม่พบแผ่นงานในไฟล์ Excel');
        }

        // ตารางข้อความร่วม
        $shared = [];
        $ss = xlsx_part($zip, 'xl/sharedStrings.xml');
        if ($ss !== null) {
            $r = xlsx_xml_reader($ss);
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
                    $shared[] = $r->isEmptyElement ? '' : xlsx_read_text($r, 'si');
                }
            }
            $r->close();
        }

        $sheet = xlsx_part($zip, $sheetPath);
        if ($sheet === null) {
            throw new RuntimeException('อ่านแผ่นงานไม่ได้');
        }
        $r = xlsx_xml_reader($sheet);
        $rows = [];
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'row') {
                continue;
            }
            $row = [];
            $rowDepth = $r->depth;
            $next = 0;
            while ($r->read()) {
                if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row' && $r->depth === $rowDepth) {
                    break;
                }
                if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'c') {
                    continue;
                }
                $ref = (string)$r->getAttribute('r');
                $type = (string)$r->getAttribute('t');
                $col = $ref !== '' ? xlsx_col_index($ref) : $next;
                $next = $col + 1;
                $val = '';
                if (!$r->isEmptyElement) {
                    $cd = $r->depth;
                    while ($r->read()) {
                        if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'c' && $r->depth === $cd) {
                            break;
                        }
                        if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'v') {
                            $val = $r->readString();
                        } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'is') {
                            $val = xlsx_read_text($r, 'is');
                        }
                    }
                }
                if ($val === '') {
                    // เซลล์ว่าง
                } elseif ($type === 's') {
                    $val = $shared[(int)$val] ?? '';
                } elseif ($type === 'b') {
                    $val = $val === '1' ? 'TRUE' : 'FALSE';
                } elseif ($type === '' && $val !== '' && is_numeric($val) && (float)$val == (int)(float)$val && abs((float)$val) < 1e15) {
                    $val = (string)(int)(float)$val;   // 5.0 -> 5 (เลขจำนวนเต็มที่ Excel เก็บเป็นทศนิยม)
                }
                if ($col < XLSX_MAX_COLS) {
                    $row[$col] = $val;
                }
            }
            if (array_filter($row, fn($v) => trim((string)$v) !== '')) {
                $max = $row ? max(array_keys($row)) : -1;
                $full = [];
                for ($i = 0; $i <= $max; $i++) {
                    $full[$i] = (string)($row[$i] ?? '');
                }
                $rows[] = $full;
                if (count($rows) > XLSX_MAX_ROWS) {
                    throw new RuntimeException('ไฟล์มีแถวมากเกินไป (เกิน ' . number_format(XLSX_MAX_ROWS) . ' แถว)');
                }
            }
        }
        $r->close();
        return $rows;
    } finally {
        $zip->close();
    }
}
