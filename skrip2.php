<?php

$categoryFile = __DIR__ . '/.selected_category';
if (file_exists($categoryFile)) {
    $HARDCODED_CATEGORY = trim(file_get_contents($categoryFile));
} else {
    $HARDCODED_CATEGORY = 'uncategorized';
}

// AUTO-DETECT VERSION FROM FILENAME
function autoDetectVersion(string $filename): string
{
    // 1. Try to find a date pattern like 20260921 or 2026-09-21
    if (preg_match('/(20\d{2})[-_]?(\d{2})[-_]?(\d{2})/', $filename, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];   
    }

    // 2. Try to find a year pattern like 2026 or 2025
    if (preg_match('/(20\d{2})/', $filename, $m)) {
        return $m[1];                            
    }

    // 3. Fall back to today's date
    return date('Y-m-d');
}

// DATABASE CONFIGURATION
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'knowledge_based');
define('DEFAULT_STATUS', 'extracted');

// CREATE DATABASE & TABLE
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) {
    die("❌ Database connection failed: " . $conn->connect_error . PHP_EOL);
}
echo "✅ Connected to MySQL successfully!" . PHP_EOL;

$conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
$conn->select_db(DB_NAME);
echo "✅ Database '" . DB_NAME . "' ready!" . PHP_EOL;

$sql = "CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_name VARCHAR(255),
    section VARCHAR(255),
    chunk_text TEXT,
    keywords TEXT,
    category VARCHAR(100),
    version VARCHAR(255),
    status VARCHAR(50)
)";
if ($conn->query($sql) === TRUE) {
    echo "✅ Table 'documents' ready!" . PHP_EOL;
} else {
    echo "❌ Error creating table: " . $conn->error . PHP_EOL;
}
$conn->close();

// COLUMNS FOR EXCEL
$COLUMNS = [
    'id',
    'document_name',
    'section',
    'chunk_text',
    'keywords',
    'category',
    'version',
    'status',
];

// FOLDER PATH
$folder = __DIR__;

// ============================================
// DOCXPARSER CLASS
// ============================================
class DocxParser
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private $stopwords = [
        'the', 'and', 'for', 'shall', 'this', 'that', 'with', 'from', 'all', 'any',
        'may', 'will', 'must', 'have', 'has', 'been', 'are', 'were', 'is', 'was',
        'be', 'to', 'of', 'in', 'on', 'at', 'by', 'as', 'or', 'a', 'an', 'it', 'its',
        'their', 'them', 'they', 'such', 'which', 'who', 'where', 'when', 'how', 'but',
        'not', 'no', 'yes', 'include', 'including', 'included', 'set', 'out', 'under',
        'over', 'per', 'one', 'two', 'three', 'first', 'second', 'third', 'new', 'old',
        'etc', 'ie', 'eg', 'i.e', 'e.g',
    ];

    public function __construct()
    {
        $this->stopwords = array_flip($this->stopwords);
    }

    public function parse(string $path, string $documentName, string $category, string $version): array
    {
        $xml = $this->readDocumentXml($path);
        if ($xml === null) {
            return [];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS);

        $chunks = [];
        $currentSection = 'General';
        $currentTexts = [];

        $flush = function () use (&$chunks, &$currentSection, &$currentTexts, $documentName, $category, $version) {
            $text = $this->cleanText(implode("\n", $currentTexts));
            if ($text !== '') {
                $chunks[] = [
                    'document_name' => $documentName,
                    'section' => $currentSection,
                    'chunk_text' => $text,
                    'category' => $category,
                    'version' => $version,
                ];
            }
            $currentTexts = [];
        };

        $paragraphs = $xpath->query('//w:body/w:p');
        foreach ($paragraphs as $para) {
            $style = $this->getStyle($xpath, $para);
            $text = $this->paragraphText($xpath, $para);
            $text = trim($text);
            if ($text === '' || $this->isToc($style)) {
                continue;
            }
            if ($this->isHeading($style)) {
                $flush();
                $currentSection = $this->cleanSection($text);
                continue;
            }
            $currentTexts[] = $text;
        }
        $flush();

        $tables = $xpath->query('//w:body/w:tbl');
        $idx = 1;
        foreach ($tables as $table) {
            $tableText = $this->tableText($xpath, $table);
            $tableText = $this->cleanText($tableText);
            if ($tableText !== '') {
                $chunks[] = [
                    'document_name' => $documentName,
                    'section' => 'Table ' . $idx,
                    'chunk_text' => $tableText,
                    'category' => $category,
                    'version' => $version,
                ];
                $idx++;
            }
        }

        foreach ($chunks as &$chunk) {
            $chunk['keywords'] = $this->extractKeywords($chunk['chunk_text'], $chunk['section']);
            $chunk['status'] = DEFAULT_STATUS;
        }
        unset($chunk);

        return $chunks;
    }

    private function readDocumentXml(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        return $xml === false ? null : $xml;
    }

    private function getStyle(DOMXPath $xpath, DOMNode $para): ?string
    {
        $nodes = $xpath->query('.//w:pPr/w:pStyle', $para);
        if ($nodes->length === 0) {
            return null;
        }
        $val = $nodes->item(0)->getAttribute('w:val');
        return $val !== '' ? $val : null;
    }

    private function isHeading(?string $style): bool
    {
        if ($style === null) return false;
        $style = strtolower($style);
        return in_array($style, ['heading1', 'partheading'], true);
    }

    private function isToc(?string $style): bool
    {
        return $style !== null && stripos($style, 'toc') !== false;
    }

    private function paragraphText(DOMXPath $xpath, DOMNode $para): string
    {
        $nodes = $xpath->query('.//w:t', $para);
        $parts = [];
        foreach ($nodes as $node) {
            $parts[] = $node->nodeValue;
        }
        return implode('', $parts);
    }

    private function tableText(DOMXPath $xpath, DOMNode $table): string
    {
        $rows = [];
        foreach ($xpath->query('.//w:tr', $table) as $row) {
            $cells = [];
            foreach ($xpath->query('.//w:tc', $row) as $cell) {
                $cellText = trim($this->paragraphText($xpath, $cell));
                if ($cellText !== '') {
                    $cells[] = $cellText;
                }
            }
            if (!empty($cells)) {
                $rows[] = implode(' | ', $cells);
            }
        }
        return implode("\n", $rows);
    }

    private function cleanText(string $text): string
    {
        $lines = explode("\n", $text);
        $cleaned = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $cleaned[] = $line;
            }
        }
        return implode("\n", $cleaned);
    }

    private function cleanSection(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/[\.\s]+$/', '', $text);
        $text = preg_replace('/\s+\d+$/', '', $text);
        $text = str_replace("\t", ' ', $text);
        return trim($text);
    }

    private function extractKeywords(string $text, string $section, int $topN = 8): string
    {
        $blob = $section . ' ' . $text;

        preg_match_all('/\b[a-zA-Z]{3,}\b/', mb_strtolower($blob), $m);
        $words = array_filter($m[0], function ($w) {
            return !isset($this->stopwords[$w]) && mb_strlen($w) > 2;
        });
        $freq = array_count_values($words);
        arsort($freq);
        $top = array_slice(array_keys($freq), 0, $topN);

        preg_match_all('/\b[A-Z][A-Z0-9]{1,5}\b/', $text, $am);
        $acronyms = array_unique($am[0]);

        $sectionWords = [];
        preg_match_all('/\b[a-zA-Z]{3,}\b/', mb_strtolower($section), $sm);
        foreach ($sm[0] as $w) {
            if (!isset($this->stopwords[$w]) && mb_strlen($w) > 2) {
                $sectionWords[] = $w;
            }
        }

        $merged = array_unique(array_merge($sectionWords, $acronyms, $top));
        $merged = array_slice($merged, 0, 12);
        $merged = array_map('mb_strtolower', $merged);
        return implode(', ', $merged);
    }
}

// ============================================
// DATABASE EXPORTER CLASS
// ============================================
class DatabaseExporter
{
    private $conn;

    public function __construct($host, $user, $pass, $dbname)
    {
        $this->conn = new mysqli($host, $user, $pass);
        if ($this->conn->connect_error) {
            die("❌ Database connection failed: " . $this->conn->connect_error . PHP_EOL);
        }
        echo "✅ Connected to MySQL successfully!" . PHP_EOL;
        $this->conn->select_db($dbname);
        echo "✅ Database '$dbname' selected!" . PHP_EOL;
    }

    public function save($records)
    {
        $stmt = $this->conn->prepare("INSERT INTO documents (document_name, section, chunk_text, keywords, category, version, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo "❌ Error preparing statement: " . $this->conn->error . PHP_EOL;
            return 0;
        }
        $count = 0;
        foreach ($records as $record) {
            $stmt->bind_param("sssssss",
                $record['document_name'],
                $record['section'],
                $record['chunk_text'],
                $record['keywords'],
                $record['category'],
                $record['version'],
                $record['status']
            );
            if ($stmt->execute()) {
                $count++;
            }
        }
        $stmt->close();
        return $count;
    }

    public function close()
    {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}

// EXCEL EXPORTER CLASS
class XlsxExporter
{
    public static function write(array $records, string $outputPath, array $columns): string
    {
        $tempDir = sys_get_temp_dir() . '/kb_export_' . uniqid();
        mkdir($tempDir, 0777, true);

        self::writeContentTypes($tempDir);
        self::writeRootRels($tempDir);
        self::writeWorkbook($tempDir);
        self::writeWorkbookRels($tempDir);
        self::writeStyles($tempDir);
        self::writeSheet($tempDir, $records, $columns);

        self::zipDirectory($tempDir, $outputPath);
        self::recursiveRemove($tempDir);

        return $outputPath;
    }

    private static function writeContentTypes(string $dir): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
        $xml .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
        $xml .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
        $xml .= '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n";
        $xml .= '  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . "\n";
        $xml .= '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n";
        $xml .= '</Types>';
        file_put_contents($dir . '/[Content_Types].xml', $xml);
    }

    private static function writeRootRels(string $dir): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $xml .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n";
        $xml .= '</Relationships>';
        @mkdir($dir . '/_rels');
        file_put_contents($dir . '/_rels/.rels', $xml);
    }

    private static function writeWorkbook(string $dir): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $xml .= '  <sheets><sheet name="knowledge_base" sheetId="1" r:id="rId1"/></sheets>' . "\n";
        $xml .= '</workbook>';
        @mkdir($dir . '/xl');
        file_put_contents($dir . '/xl/workbook.xml', $xml);
    }

    private static function writeWorkbookRels(string $dir): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $xml .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' . "\n";
        $xml .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' . "\n";
        $xml .= '</Relationships>';
        @mkdir($dir . '/xl/_rels');
        file_put_contents($dir . '/xl/_rels/workbook.xml.rels', $xml);
    }

    private static function writeStyles(string $dir): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
        $xml .= '  <fonts count="1"><font><name val="Calibri"/><sz val="11"/></font></fonts>' . "\n";
        $xml .= '  <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' . "\n";
        $xml .= '  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' . "\n";
        $xml .= '  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' . "\n";
        $xml .= '  <cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>' . "\n";
        $xml .= '  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' . "\n";
        $xml .= '  <dxfs count="0"/>' . "\n";
        $xml .= '  <tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>' . "\n";
        $xml .= '</styleSheet>';
        file_put_contents($dir . '/xl/styles.xml', $xml);
    }

    private static function writeSheet(string $dir, array $records, array $columns): void
    {
        @mkdir($dir . '/xl/worksheets');

        $maxRow = count($records) + 1;
        $maxCol = count($columns);
        $lastCol = self::columnLetter($maxCol);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
        $xml .= "  <dimension ref=\"A1:{$lastCol}{$maxRow}\"/>\n";
        $xml .= '  <cols><col min="1" max="' . $maxCol . '" width="22"/></cols>' . "\n";
        $xml .= '  <sheetData>' . "\n";

        $xml .= '    <row r="1">' . "\n";
        foreach ($columns as $idx => $header) {
            $col = self::columnLetter($idx + 1);
            $xml .= self::inlineStrCell("{$col}1", $header);
        }
        $xml .= '    </row>' . "\n";

        foreach ($records as $rIdx => $record) {
            $rowNum = $rIdx + 2;
            $xml .= "    <row r=\"{$rowNum}\">\n";
            foreach ($columns as $idx => $key) {
                $col = self::columnLetter($idx + 1);
                $ref = "{$col}{$rowNum}";
                $value = $record[$key] ?? '';
                if ($key === 'id' && is_numeric($value)) {
                    $xml .= "      <c r=\"{$ref}\"><v>" . (int)$value . "</v></c>\n";
                } else {
                    $xml .= self::inlineStrCell($ref, (string)$value);
                }
            }
            $xml .= '    </row>' . "\n";
        }

        $xml .= '  </sheetData>' . "\n";
        $xml .= '</worksheet>';
        file_put_contents($dir . '/xl/worksheets/sheet1.xml', $xml);
    }

    private static function inlineStrCell(string $ref, string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return "      <c r=\"{$ref}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>\n";
    }

    private static function columnLetter(int $n): string
    {
        $result = '';
        while ($n > 0) {
            $n--;
            $result = chr(65 + $n % 26) . $result;
            $n = (int)($n / 26);
        }
        return $result;
    }

    private static function zipDirectory(string $source, string $out): void
    {
        $zip = new ZipArchive();
        if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Cannot open {$out} for writing");
        }

        $source = rtrim($source, DIRECTORY_SEPARATOR);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();
            $relative = substr($realPath, strlen($source) + 1);
            if ($file->isDir()) {
                continue;
            }
            $zip->addFile($realPath, str_replace('\\', '/', $relative));
        }

        $zip->close();
    }

    private static function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }
}

// MAIN EXECUTION
$parser = new DocxParser();
$records = [];

echo PHP_EOL . "📄 Processing single document..." . PHP_EOL . PHP_EOL;

// CREATE BACKUP FOLDER
$backupFolder = $folder . '/backup';
if (!is_dir($backupFolder)) {
    mkdir($backupFolder, 0777, true);
}

// CHECK IF HARDCODED OR AUTO-DETECT
if (!empty($HARDCODED_DOCUMENT_NAME)) {
    // HARDCODED MODE
    $file = $folder . '/docx/' . $HARDCODED_DOCUMENT_NAME;

    if (!file_exists($file)) {
        die("❌ File not found: " . $file . PHP_EOL);
    }

    $actualDocumentName = basename($file);

    echo "📖 Parsing (Hardcoded): " . $actualDocumentName . PHP_EOL;
    echo "   📂 Category (Selected): " . $HARDCODED_CATEGORY . PHP_EOL;

} else {
    // AUTO-DETECT MODE
    $files = glob($folder . '/docx/*.docx');

    if (count($files) === 0) {
        die("❌ No .docx file found in folder: $folder/docx" . PHP_EOL);
    }

    // Sort by modification time — newest last
    usort($files, function ($a, $b) {
        return filemtime($a) - filemtime($b);
    });

    // If more than 1 file, move old ones to backup
    if (count($files) > 1) {
        echo "📦 Moving old files to backup folder..." . PHP_EOL;

        for ($i = 0; $i < count($files) - 1; $i++) {
            $oldFile = $files[$i];
            $backupFile = $backupFolder . '/' . basename($oldFile);

            if (file_exists($backupFile)) {
                $timestamp = date('Ymd_His');
                $backupFile = $backupFolder . '/' . pathinfo($oldFile, PATHINFO_FILENAME) . '_' . $timestamp . '.' . pathinfo($oldFile, PATHINFO_EXTENSION);
            }

            rename($oldFile, $backupFile);
            echo "   📦 Moved: " . basename($oldFile) . " → backup/" . basename($backupFile) . PHP_EOL;
        }
        echo PHP_EOL;
    }

    // Get newest file
    $files = glob($folder . '/docx/*.docx');
    usort($files, function ($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    $file = end($files);
    $actualDocumentName = basename($file);

    echo "📖 Parsing (Auto-Detect): " . $actualDocumentName . PHP_EOL;
    echo "   📂 Category (Selected): " . $HARDCODED_CATEGORY . PHP_EOL;
}

// SET OUTPUT PATH — USE THE DOCX NAME
$docxBaseName = pathinfo($actualDocumentName, PATHINFO_FILENAME);
$outputPath = $folder . '/' . $docxBaseName . '.xlsx';

// AUTO-DETECT VERSION FROM FILENAME
$detectedVersion = autoDetectVersion($actualDocumentName);
echo "   📅 Version (Auto-Detected): " . $detectedVersion . PHP_EOL;

// PROCESS THE FILE
$chunks = $parser->parse($file, $actualDocumentName, $HARDCODED_CATEGORY, $detectedVersion);
echo "   ✅ " . count($chunks) . " chunks extracted" . PHP_EOL;

$records = array_merge($records, $chunks);

// Add ID numbers
foreach ($records as $idx => &$record) {
    $record['id'] = $idx + 1;
}
unset($record);

echo PHP_EOL . "📊 Total: " . count($records) . " chunks extracted" . PHP_EOL . PHP_EOL;

// SAVE TO EXCEL
echo "💾 Saving to Excel..." . PHP_EOL;
XlsxExporter::write($records, $outputPath, $COLUMNS);
echo "✅ Excel saved to: " . $outputPath . PHP_EOL;

// SAVE TO MySQL DATABASE
echo PHP_EOL . "🗄️ Saving to MySQL database..." . PHP_EOL;

try {
    $db = new DatabaseExporter(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $count = $db->save($records);
    $db->close();
    echo "✅ " . $count . " records saved to MySQL database '" . DB_NAME . "'!" . PHP_EOL;
    echo "   📍 phpMyAdmin: http://localhost/phpmyadmin" . PHP_EOL;
} catch (Exception $e) {
    echo "❌ Error saving to MySQL: " . $e->getMessage() . PHP_EOL;
    echo "   ⚠️ Your data was still saved to Excel!" . PHP_EOL;
}

echo PHP_EOL . "✅ ALL DONE!" . PHP_EOL . PHP_EOL;