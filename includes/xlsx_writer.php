<?php
/**
 * A small writer for real Excel files (.xlsx), used by reports/export.php.
 *
 * Why not the old "HTML saved as .xls": Excel warned that the file did not match its extension, wrote
 * contact numbers without their leading zero, and could only export one table. Here every table is its own
 * sheet, text stays text (09171234567 keeps its 0), and amounts are numbers so Excel can add them up.
 *
 * No library needed: an .xlsx is a zip of a few XML files. The zip is built by hand (deflate when zlib is there,
 * stored otherwise), because the PHP "zip" extension is not on in XAMPP by default.
 *
 *   $x = new XlsxWriter();
 *   $x->addSheet('Medical', [
 *       [['Title', XlsxWriter::S_TITLE]],
 *       ['Name', ['Amount', XlsxWriter::S_HEADER]],
 *       ['Juan Dela Cruz', [5000, XlsxWriter::S_MONEY]],
 *   ]);
 *   echo $x->build();
 *
 * A cell is a plain value (text, int, float; null or '' leaves it empty) or [value, style].
 */
final class XlsxWriter
{
    public const S_TEXT = 0;
    public const S_HEADER = 1;
    public const S_MONEY = 2;
    public const S_TITLE = 3;
    public const S_BOLD = 4;
    public const S_INT = 5;
    public const S_MONEY_BOLD = 6;
    public const S_NOTE = 7;

    /** @var array<int, array{name: string, rows: array, widths: array}> */
    private $sheets = [];

    /**
     * @param array $rows   rows of cells
     * @param array $widths optional column widths (characters); missing ones are worked out from the content
     */
    public function addSheet(string $name, array $rows, array $widths = []): void
    {
        $this->sheets[] = ['name' => $this->uniqueName($name), 'rows' => $rows, 'widths' => $widths];
    }

    public function sheetCount(): int
    {
        return count($this->sheets);
    }

    /** The finished file as a string. */
    public function build(): string
    {
        if (!$this->sheets) {
            $this->addSheet('Report', [['No data']]);
        }
        $n = count($this->sheets);

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $types .= '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sheet) {
            $id = $i + 1;
            $workbook .= '<sheet name="' . $this->xml($sheet['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $workbookRels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
        }
        $workbook .= '</sheets></workbook>';
        $workbookRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

        $files = [
            '[Content_Types].xml' => $types,
            '_rels/.rels' => $rootRels,
            'xl/workbook.xml' => $workbook,
            'xl/_rels/workbook.xml.rels' => $workbookRels,
            'xl/styles.xml' => $this->stylesXml(),
        ];
        foreach ($this->sheets as $i => $sheet) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheetXml($sheet);
        }
        return $this->zip($files);
    }

    /* ---------- sheets ---------- */

    private function sheetXml(array $sheet): string
    {
        $widths = $sheet['widths'];
        $auto = [];
        $rowsXml = '';
        foreach (array_values($sheet['rows']) as $r => $row) {
            $rowNo = $r + 1;
            $cells = '';
            foreach (array_values((array)$row) as $c => $cell) {
                [$value, $style] = is_array($cell) ? [$cell[0] ?? null, (int)($cell[1] ?? 0)] : [$cell, 0];
                if ($value === null || $value === '') continue;
                $ref = $this->column($c) . $rowNo;
                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . $this->number($value) . '</v></c>';
                    $len = strlen($this->number($value)) + ($style === self::S_MONEY || $style === self::S_MONEY_BOLD ? 3 : 0);
                } else {
                    $text = $this->clean((string)$value);
                    $cells .= '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . $this->xml($text) . '</t></is></c>';
                    // Titles and notes run across the empty cells next to them; they do not set the column width
                    $len = in_array($style, [self::S_TITLE, self::S_NOTE], true) ? 0 : mb_strlen($text);
                }
                if ($r < 400) $auto[$c] = max($auto[$c] ?? 0, $len);   // the first rows are enough to size a column
            }
            $rowsXml .= '<row r="' . $rowNo . '">' . $cells . '</row>';
        }

        $cols = '';
        $count = $auto ? max(array_keys($auto)) + 1 : 0;
        for ($c = 0; $c < $count; $c++) {
            $w = $widths[$c] ?? min(60, max(10, ($auto[$c] ?? 0) + 2));
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $rowsXml . '</sheetData></worksheet>';
    }

    /** Fonts, fills and number formats the S_* styles point at (index = position in cellXfs). */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF595959"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E2F3"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="8">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                       // 0 text
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>' // 1 header
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                                               // 2 money
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                         // 3 title
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                         // 4 bold
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                                                 // 5 whole number
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'                                 // 6 money, bold (totals)
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                         // 7 note
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /* ---------- small helpers ---------- */

    /** 0 -> A, 25 -> Z, 26 -> AA */
    private function column(int $i): string
    {
        $name = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26) . $name;
        }
        return $name;
    }

    /** A number in the plain form XML wants, whatever the server's language setting. */
    private function number($v): string
    {
        if (is_int($v)) return (string)$v;
        $s = rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
        return $s === '' || $s === '-' || $s === '-0' ? '0' : $s;
    }

    /** Text with the characters XML cannot hold taken out, and cut to Excel's cell limit. */
    private function clean(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        return mb_substr($text, 0, 32000);
    }

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** A sheet name Excel accepts: 31 characters at most, none of [ ] : * ? / \, and different from the others. */
    private function uniqueName(string $name): string
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]+/u', '-', $name) ?? '', " '");
        $name = mb_substr($name, 0, 31);
        if ($name === '') $name = 'Sheet';
        $taken = array_map('mb_strtolower', array_column($this->sheets, 'name'));
        $candidate = $name;
        for ($i = 2; in_array(mb_strtolower($candidate), $taken, true); $i++) {
            $suffix = ' (' . $i . ')';
            $candidate = mb_substr($name, 0, 31 - mb_strlen($suffix)) . $suffix;
        }
        return $candidate;
    }

    /* ---------- the zip ---------- */

    /** @param array<string, string> $files path => content */
    private function zip(array $files): string
    {
        $deflate = function_exists('gzdeflate');
        $now = getdate();
        $dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $dosDate = ((max(1980, $now['year']) - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

        $body = '';
        $central = '';
        $count = 0;
        foreach ($files as $path => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $data = $content;
            $method = 0;
            if ($deflate && $size > 64) {
                $packed = gzdeflate($content, 6);
                if ($packed !== false && strlen($packed) < $size) {
                    $data = $packed;
                    $method = 8;
                }
            }
            $offset = strlen($body);
            $name = (string)$path;
            // flags 0x0800: the file names are UTF-8 (they are plain ASCII here)
            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($data), $size, strlen($name), 0)
                . $name . $data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($data), $size,
                    strlen($name), 0, 0, 0, 0, 0, $offset)
                . $name;
            $count++;
        }
        return $body . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
    }
}
