<?php

// Config
$categoryFile = __DIR__ . '/.selected_category';
$category = file_exists($categoryFile) ? trim(file_get_contents($categoryFile)) : 'uncategorized';
$hardcodedDocument = '';
$debug = true;

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'knowledge_based');
define('DEFAULT_STATUS', 'extracted');
define('TABLES_INSIDE_SECTION', true);

$columns = ['id', 'document_name', 'section', 'chunk_text', 'keywords', 'category', 'version', 'status'];
$folder = __DIR__;
const EXCEL_CELL_LIMIT = 32000;

function detectVersion(string $filename): string
{
    if (preg_match('/(20\d{2})[-_]?(\d{2})[-_]?(\d{2})/', $filename, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/(20\d{2})/', $filename, $m)) return $m[1];
    return date('Y-m-d');
}

// DB setup
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) die("❌ DB: {$conn->connect_error}\n");
$conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
$conn->select_db(DB_NAME);
$conn->query("CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_name VARCHAR(255),
    section VARCHAR(255),
    chunk_text MEDIUMTEXT,
    keywords TEXT,
    category VARCHAR(100),
    version VARCHAR(255),
    status VARCHAR(50)
)");
$conn->close();
echo "✅ Database ready\n";

// Docx Parser
class DocxParser
{
    const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    // Any of these wrappers can appear between any two logical elements
    // (paragraph, table, row, cell).It always look "through" them.
    const WRAPPERS = [
        'sdt', 'sdtContent',
        'customXml', 'ins', 'smartTag',
        'drawing', 'pict', 'txbx', 'txbxContent', 'shape', 'textbox',
        'AlternateContent', 'Choice', 'Fallback',
    ];

    // Words to ignore when generating keywords
    private $stopwords;

    public function __construct()
    {
        $this->stopwords = array_flip([
            'the','and','for','shall','this','that','with','from','all','any',
            'may','will','must','have','has','been','are','were','is','was',
            'be','to','of','in','on','at','by','as','or','a','an','it','its',
            'their','them','they','such','which','who','where','when','how','but',
            'not','no','yes','include','including','included','set','out','under',
            'over','per','one','two','three','first','second','third','new','old',
        ]);
    }

    public function parse(string $path, string $docName, string $category, string $version): array
    {
        //docx zip file
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return [];

        $chunks = [];
        $mainXml = $zip->getFromName('word/document.xml');
        if ($mainXml !== false) {
            $chunks = $this->parseXml($mainXml, $docName, $category, $version);
        }
        $zip->close();

        // Post-process each chunk: generate keywords + set status
        foreach ($chunks as &$c) {
            $c['keywords'] = $this->keywords($c['chunk_text'], $c['section']);
            $c['status'] = DEFAULT_STATUS;
        }
        return $chunks;
    }

    private function parseXml(string $xml, string $docName, string $category, string $version): array
    {
         // Load the Word XML into a DOM
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        libxml_clear_errors();

        // XPath with the "w:" namespace registered
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);

        // Find the <w:body> element (top-level container)
        $root = $xp->query('//w:body')->item(0);
        if ($root === null) return [];

        $chunks = [];
        $section = 'General';
        $buffer = [];
        $termsStarted = false; //true if jumpa first heading
        $sectionHasHeading = false;

        $flush = function () use (&$chunks, &$buffer, &$section, &$sectionHasHeading, $docName, $category, $version) {
            $text = $this->cleanText(implode("\n", $buffer));
            if ($text !== '' || $sectionHasHeading) {
                $chunks[] = [
                    'document_name' => $docName,
                    'section' => $section,
                    'chunk_text' => $text,
                    'category' => $category,
                    'version' => $version,
                ];
            }
            $buffer = [];
            $sectionHasHeading = false;
        };

        $tableChunks = [];

        foreach ($this->collectBlocks($root) as $node) {
            if ($node->localName === 'tbl') {
                if (!TABLES_INSIDE_SECTION) {
                    $t = $this->cleanText($this->tableText($xp, $node));
                    if ($t !== '') $tableChunks[] = $t;
                    continue;
                }
                $rowLines = [];
                foreach ($this->tableRows($xp, $node) as $cells) {
                    if (count($cells) === 1 && $this->isAttachmentHeading($cells[0])) {
                        if ($rowLines) { $buffer[] = implode("\n", $rowLines); $rowLines = []; }
                        $flush();
                        $termsStarted = true;
                        $section = $this->cleanSection($cells[0]);
                        $sectionHasHeading = true;
                        continue;
                    }
                    $rowLines[] = implode(' | ', $cells);
                }
                if ($rowLines) $buffer[] = $this->cleanText(implode("\n", $rowLines));
                continue;
            }

            if ($node->localName !== 'p') continue;

            $style = $this->getStyle($xp, $node);
            $text = trim($this->paragraphText($xp, $node));
            if ($text === '' || ($style !== null && stripos($style, 'toc') !== false)) continue;

            if (mb_strlen($text) < 80 && preg_match('/^[\-\x{2013}\x{2014}\s]*END\s+OF\s+(CHAPTER|PART|DOCUMENT)\b/iu', $text)) {
                $flush();
                $section = 'General';
                continue;
            }

            if ($this->isHeading($style, $text, $termsStarted, $this->outlineLevel($xp, $node), $this->isListItem($xp, $node))) {
                $flush();
                $termsStarted = true;
                $section = $this->cleanSection($text);
                $sectionHasHeading = true;
                continue;
            }
            $buffer[] = $text;
        }
        $flush();

        foreach ($tableChunks as $i => $t) {
            $chunks[] = [
                'document_name' => $docName,
                'section' => 'Table ' . ($i + 1),
                'chunk_text' => $t,
                'category' => $category,
                'version' => $version,
            ];
        }
        return $chunks;
    }

    // Paragraphs and tables in document order, looking through every wrapper
    private function collectBlocks(DOMNode $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $name = $child->localName;
            if ($name === 'p' || $name === 'tbl') {
                $out[] = $child;
            } elseif (in_array($name, self::WRAPPERS, true)) {
                $out = array_merge($out, $this->collectBlocks($child));
            }
        }
        return $out;
    }

    // Find children named $target through ANY wrapper depth
    private function elementChildren(DOMNode $parent, string $target): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $name = $child->localName;
            if ($name === $target) {
                $out[] = $child;
            } elseif (in_array($name, self::WRAPPERS, true)) {
                $out = array_merge($out, $this->elementChildren($child, $target));
            }
        }
        return $out;
    }

    private function tableRows(DOMXPath $xp, DOMNode $table): array
    {
        $rows = [];
        foreach ($this->elementChildren($table, 'tr') as $row) {
            $cells = [];
            foreach ($this->elementChildren($row, 'tc') as $cell) {
                $t = $this->cellText($xp, $cell);
                if ($t !== '') $cells[] = $t;
            }
            if ($cells) $rows[] = $cells;
        }
        return $rows;
    }

    // Extract text from a cell, flattening nested tables and wrappers
    private function cellText(DOMXPath $xp, DOMNode $cell): string
    {
        $parts = [];
        foreach ($cell->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $n = $child->localName;

            if ($n === 'p') {
                $t = trim($this->paragraphText($xp, $child));
                if ($t !== '') $parts[] = $t;
            } elseif ($n === 'tbl') {
                foreach ($this->tableRows($xp, $child) as $nested) {
                    $parts[] = implode(' | ', $nested);
                }
            } elseif (in_array($n, self::WRAPPERS, true)) {
                $sub = $this->cellText($xp, $child);
                if ($sub !== '') $parts[] = $sub;
            }
        }
        return $this->normalizeSpaces(implode(' ', $parts));
    }

    private function normalizeSpaces(string $text): string
    {
        if (strlen($text) > 500000) {
            $r = preg_replace('/[ \t\r\n]+/', ' ', $text);
            return trim($r ?? $text);
        }
        $r = preg_replace('/\s+/u', ' ', $text);
        return trim($r ?? $text);
    }

    private function tableText(DOMXPath $xp, DOMNode $table): string
    {
        $lines = [];
        foreach ($this->tableRows($xp, $table) as $cells) {
            $lines[] = implode(' | ', $cells);
        }
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------

    private function getStyle(DOMXPath $xp, DOMNode $p): ?string
    {
        $n = $xp->query('.//w:pPr/w:pStyle', $p);
        if ($n->length === 0) return null;
        $v = $n->item(0)->getAttribute('w:val');
        return $v !== '' ? $v : null;
    }

    private function isListItem(DOMXPath $xp, DOMNode $p): bool
    {
        return $xp->query('./w:pPr/w:numPr', $p)->length > 0;
    }

    private function outlineLevel(DOMXPath $xp, DOMNode $p): ?string
    {
        $n = $xp->query('./w:pPr/w:outlineLvl', $p);
        return $n->length > 0 ? $n->item(0)->getAttribute('w:val') : null;
    }

    private function isHeading(?string $style, string $text, bool $started, ?string $outline, bool $listItem): bool
    {
        if (preg_match('/^\d+\s*\.\s*\d/', $text)) return false;
        if ($this->isAttachmentHeading($text) && !$listItem) return true;
        if ($style !== null && strtolower($style) === 'heading1') return true;

        if ($started && $outline === '0' && mb_strlen($text) < 200
            && $text === mb_strtoupper($text) && preg_match('/[A-Z]{3}/', $text)) return true;

        return $started && mb_strlen($text) < 200
            && preg_match('/^\d{1,2}(?!\d)\s*\.?\s*[A-Z]{2,}/', $text) === 1;
    }

    private function isAttachmentHeading(string $text): bool
    {
        return mb_strlen($text) < 150
            && preg_match('/^(Attachment|Appendix)\s+[A-Za-z]?\d+\s*[\x{2013}\x{2014}\-:]/iu', $text) === 1;
    }

    private function paragraphText(DOMXPath $xp, DOMNode $p): string
    {
        $nodes = $xp->query('.//w:r/w:t | .//w:r/w:tab | .//w:r/w:br', $p);
        $parts = [];
        foreach ($nodes as $n) {
            if ($n->localName === 't') $parts[] = $n->nodeValue;
            elseif ($n->localName === 'tab') $parts[] = "\t";
            elseif ($n->localName === 'br' && $n->getAttribute('w:type') !== 'page') $parts[] = "\n";
        }
        return str_replace("\xC2\xA0", ' ', implode('', $parts));
    }

    //KEYWORDS
    // Removes empty lines and trims, preserving intentional newlines
    private function cleanText(string $text): string
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/[ \t]*\t[ \t]*/', "\t", $line));
            if ($line !== '') $out[] = $line;
        }
        return implode("\n", $out);
    }

    // Cleans up a heading before using it as a section name
    private function cleanSection(string $text): string
    {
        $text = trim($this->normalizeSpaces($text));
        $text = preg_replace('/[\.\s]+$/', '', $text);
        $text = preg_replace('/\s+\d+$/', '', $text);
        $text = preg_replace('/^(\d{1,2})\s*\.?\s*(?=[A-Z])/', '$1. ', $text);
        return trim($text);
    }

    // Generates a comma-separated keyword string by:
    private function keywords(string $text, string $section, int $topN = 8): string
    {
        preg_match_all('/\b[a-zA-Z]{3,}\b/', mb_strtolower($section . ' ' . $text), $m);
        $words = array_filter($m[0], fn($w) => !isset($this->stopwords[$w]));
        $freq = array_count_values($words);
        arsort($freq);

        preg_match_all('/\b[A-Z][A-Z0-9]{1,5}\b/', $text, $am);

        $sectionWords = [];
        preg_match_all('/\b[a-zA-Z]{3,}\b/', mb_strtolower($section), $sm);
        foreach ($sm[0] as $w) {
            if (!isset($this->stopwords[$w])) $sectionWords[] = $w;
        }

        $merged = array_slice(array_unique(array_merge($sectionWords, $am[0], array_keys($freq))), 0, 12);
        return implode(', ', array_map('mb_strtolower', $merged));
    }
}

// DB writer
class DbWriter
{
    private $conn;

    public function __construct()
    {
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($this->conn->connect_error) die("❌ DB: {$this->conn->connect_error}\n");
        $this->conn->set_charset('utf8mb4');
    }

    public function deleteDocument(string $name): int
    {
        $s = $this->conn->prepare("DELETE FROM documents WHERE document_name = ?");
        $s->bind_param("s", $name);
        $s->execute();
        $n = $s->affected_rows;
        $s->close();
        return max(0, $n);
    }

    // Inserts every chunk as a row
    public function save(array $records): int
    {
        $s = $this->conn->prepare("INSERT INTO documents (document_name, section, chunk_text, keywords, category, version, status) VALUES (?,?,?,?,?,?,?)");
        $n = 0;
        foreach ($records as $r) {
            $s->bind_param("sssssss",
                $r['document_name'], $r['section'], $r['chunk_text'], $r['keywords'],
                $r['category'], $r['version'], $r['status']);
            if ($s->execute()) $n++;
        }
        $s->close();
        return $n;
    }

    public function close() { $this->conn->close(); }
}

// XLSX writer
class Xlsx
{
    public static function write(array $records, string $path, array $columns): void
    {
        $dir = sys_get_temp_dir() . '/kb_' . uniqid();
        mkdir($dir . '/_rels', 0777, true);
        mkdir($dir . '/xl/_rels', 0777, true);
        mkdir($dir . '/xl/worksheets', 0777, true);

        file_put_contents("$dir/[Content_Types].xml",
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');

        file_put_contents("$dir/_rels/.rels",
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        file_put_contents("$dir/xl/workbook.xml",
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="knowledge_base" sheetId="1" r:id="rId1"/></sheets></workbook>');

        file_put_contents("$dir/xl/_rels/workbook.xml.rels",
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        file_put_contents("$dir/xl/styles.xml",
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><name val="Calibri"/><sz val="11"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        $lastCol = self::col(count($columns));
        $lastRow = count($records) + 1;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . "<dimension ref=\"A1:$lastCol$lastRow\"/>"
            . '<cols><col min="1" max="' . count($columns) . '" width="22"/></cols><sheetData>';

        $xml .= '<row r="1">';
        foreach ($columns as $i => $h) $xml .= self::cell(self::col($i + 1) . '1', $h);
        $xml .= '</row>';

        foreach ($records as $ri => $rec) {
            $rn = $ri + 2;
            $xml .= "<row r=\"$rn\">";
            foreach ($columns as $i => $key) {
                $ref = self::col($i + 1) . $rn;
                $val = $rec[$key] ?? '';
                $xml .= ($key === 'id' && is_numeric($val))
                    ? "<c r=\"$ref\"><v>" . (int)$val . "</v></c>"
                    : self::cell($ref, (string)$val);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        file_put_contents("$dir/xl/worksheets/sheet1.xml", $xml);

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $src = rtrim($dir, '/');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isDir()) continue;
            $rel = substr($file->getRealPath(), strlen($src) + 1);
            $zip->addFile($file->getRealPath(), str_replace('\\', '/', $rel));
        }
        $zip->close();

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    private static function cell(string $ref, string $value): string
    {
        if (mb_strlen($value) > EXCEL_CELL_LIMIT) {
            $value = mb_substr($value, 0, EXCEL_CELL_LIMIT - 20) . "\n...[truncated]";
        }
        $v = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return "<c r=\"$ref\" t=\"inlineStr\"><is><t xml:space=\"preserve\">$v</t></is></c>";
    }

    private static function col(int $n): string
    {
        $s = '';
        while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
        return $s;
    }
}

// Pick file
$backupDir = $folder . '/backup';
if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

if ($hardcodedDocument !== '') {

   // Use the hardcoded one
    $file = $folder . '/docx/' . $hardcodedDocument;
    if (!file_exists($file)) die("❌ File not found: $file\n");
    echo "📖 Parsing: " . basename($file) . " (hardcoded)\n";
} else {

    // Pick the newest .docx, move all others to /backup
    $files = glob($folder . '/docx/*.docx') ?: [];
    if (!$files) die("❌ No .docx files in $folder/docx\n");
    usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
    while (count($files) > 1) {
        $old = array_shift($files);
        $dest = $backupDir . '/' . basename($old);
        if (file_exists($dest)) $dest = $backupDir . '/' . pathinfo($old, PATHINFO_FILENAME) . '_' . date('Ymd_His') . '.docx';
        rename($old, $dest);
        echo "📦 Moved to backup: " . basename($old) . "\n";
    }
    $file = $files[0];
    echo "📖 Parsing: " . basename($file) . " (newest)\n";
}

$docName = basename($file);
$version = detectVersion($docName);
echo "📂 Category: $category\n📅 Version: $version\n";

// Parse
$parser = new DocxParser();
$chunks = $parser->parse($file, $docName, $category, $version);
echo "✅ Extracted " . count($chunks) . " chunks\n";

//Debug dump
if ($debug) {
    $dump = "=== DOCUMENT: $docName ===\n\nTotal chunks: " . count($chunks) . "\n\n";

    // List every word/*.xml file and count how many <w:t> nodes it has
    $zip = new ZipArchive();
    if ($zip->open($file) === true) {
        $dump .= "=== RAW XML PARTS ===\n\n";
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!preg_match('#^word/.*\.xml$#', $name)) continue;
            $xml = $zip->getFromName($name);
            $count = preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $xml, $m);
            $dump .= sprintf("%-40s   %d <w:t> text nodes\n", $name, $count);
        }
        $zip->close();
    }

    $dump .= "\n=== EXTRACTED CHUNKS ===\n\n";
    foreach ($chunks as $i => $c) {
        $len = strlen($c['chunk_text']);
        $dump .= "#" . ($i + 1) . "  Section: {$c['section']}\n";
        $dump .= "    Text ($len bytes):\n";
        $body = $c['chunk_text'] === '' ? '   [EMPTY]' : '   ' . str_replace("\n", "\n   ", substr($c['chunk_text'], 0, 500));
        $dump .= $body . "\n\n";
    }
    file_put_contents($folder . '/debug_dump.txt', $dump);
    echo "🔍 Debug dump written to debug_dump.txt\n";
}

foreach ($chunks as $i => &$c) $c['id'] = $i + 1;
unset($c);

$output = $folder . '/' . pathinfo($docName, PATHINFO_FILENAME) . '.xlsx';
Xlsx::write($chunks, $output, $columns);
echo "💾 Excel: $output\n";

try {
    $db = new DbWriter();
    $removed = $db->deleteDocument($docName);
    if ($removed) echo "🧹 Removed $removed old rows\n";
    $saved = $db->save($chunks);
    $db->close();
    echo "🗄️ Saved $saved rows to MySQL\n";
} catch (Throwable $e) {
    echo "❌ DB error: {$e->getMessage()}\n   (Excel was still written)\n";
}

echo "✅ Done\n";
