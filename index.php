<?php
session_start();

$folder = __DIR__;
$docxFolder = $folder . '/docx';
$backupFolder = $folder . '/backup';

// Auto-create folders
if (!is_dir($docxFolder)) mkdir($docxFolder, 0777, true);
if (!is_dir($backupFolder)) mkdir($backupFolder, 0777, true);

// Load message from session (PRG pattern)
$message = $_SESSION['message'] ?? '';
$status  = $_SESSION['status']  ?? '';
unset($_SESSION['message'], $_SESSION['status']);

$records = [];
$currentXlsx = '';

/*HANDLE FILE DELETION (single file)*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
    $fileToDelete = basename($_POST['delete_file']);
    $filePath = $backupFolder . '/' . $fileToDelete;

    if (file_exists($filePath) && in_array(pathinfo($filePath, PATHINFO_EXTENSION), ['docx', 'xlsx'])) {
        unlink($filePath);
        $_SESSION['message'] = "File deleted: " . $fileToDelete;
        $_SESSION['status']  = 'success';
    } else {
        $_SESSION['message'] = "File not found: " . $fileToDelete;
        $_SESSION['status']  = 'error';
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/*HANDLE BULK DELETE (selected files)*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected']) && !empty($_POST['files'])) {
    $deleted = 0;
    foreach ($_POST['files'] as $f) {
        $fileToDelete = basename($f);
        $filePath = $backupFolder . '/' . $fileToDelete;

        if (file_exists($filePath) && in_array(pathinfo($filePath, PATHINFO_EXTENSION), ['docx', 'xlsx'])) {
            unlink($filePath);
            $deleted++;
        }
    }
    $_SESSION['message'] = "Deleted $deleted file(s).";
    $_SESSION['status']  = 'success';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/*FILES UPLOAD*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['docx_file'])) {
    $uploadedFile = $_FILES['docx_file'];
    $selectedCategory = $_POST['category'] ?? 'uncategorized';

    if ($uploadedFile['error'] === UPLOAD_ERR_OK) {
        $filename = basename($uploadedFile['name']);
        $newBaseName = pathinfo($filename, PATHINFO_FILENAME);

        // Capture the OLD file name BEFORE moving it
        $existingFiles = glob($docxFolder . '/*.docx');
        $previousFileName = '';

        // Move old .docx files to backup
        foreach ($existingFiles as $oldFile) {
            $previousFileName = pathinfo($oldFile, PATHINFO_FILENAME);

            $backupFile = $backupFolder . '/' . basename($oldFile);
            if (file_exists($backupFile)) {
                $timestamp = date('Ymd_His');
                $backupFile = $backupFolder . '/' . $previousFileName . '_' . $timestamp . '.docx';
            }
            rename($oldFile, $backupFile);
        }

        // Move the old Excel (same name as the old docx) into backup
        if ($previousFileName !== '') {
            $oldExcel = $folder . '/' . $previousFileName . '.xlsx';
            if (file_exists($oldExcel)) {
                $archivePath = $backupFolder . '/' . $previousFileName . '.xlsx';
                if (file_exists($archivePath)) {
                    $archivePath = $backupFolder . '/' . $previousFileName . '_' . date('Ymd_His') . '.xlsx';
                }
                rename($oldExcel, $archivePath);
            }
        }

        // Move uploaded file to docx folder
        $targetPath = $docxFolder . '/' . $filename;
        move_uploaded_file($uploadedFile['tmp_name'], $targetPath);

        // Save selected category to temp file for skrip2.php
        file_put_contents($folder . '/.selected_category', $selectedCategory);

        // Run the extraction script
        $output = shell_exec('C:\xampp\php\php.exe ' . escapeshellarg($folder . '/skrip2.php') . ' 2>&1');

        // Save message + status in session, then redirect (PRG pattern)
        $_SESSION['message'] = "File uploaded: " . $filename . " (Category: " . $selectedCategory . ")";
        $_SESSION['status']  = 'success';

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;

    } else {
        $_SESSION['message'] = "Upload failed. Please try again.";
        $_SESSION['status']  = 'error';

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

/*READ DATA EXCEL*/

function readExcelData($xlsxPath) {
    if (!file_exists($xlsxPath)) return [];

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) return [];

    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($xml === false) return [];

    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $rows = [];
    foreach ($xpath->query('//s:row') as $row) {
        $cells = [];
        foreach ($xpath->query('.//s:c', $row) as $cell) {
            $t = $xpath->query('.//s:t', $cell);
            if ($t->length > 0) {
                $cells[] = $t->item(0)->nodeValue;
            } else {
                $v = $xpath->query('.//s:v', $cell);
                $cells[] = $v->length > 0 ? $v->item(0)->nodeValue : '';
            }
        }
        $rows[] = $cells;
    }

    return $rows;
}

/*FILES LIST*/

function listFiles($folder, $ext = 'docx') {
    $files = glob($folder . '/*.' . $ext);
    return array_map('basename', $files);
}

$docxFiles = listFiles($docxFolder, 'docx');
$backupFiles = listFiles($backupFolder, 'docx');
$backupExcelFiles = listFiles($backupFolder, 'xlsx');
rsort($backupExcelFiles);

// Find the current XLSX (same name as the current .docx)
if (!empty($docxFiles)) {
    $currentXlsxName = pathinfo($docxFiles[0], PATHINFO_FILENAME) . '.xlsx';
    if (file_exists($folder . '/' . $currentXlsxName)) {
        $currentXlsx = $folder . '/' . $currentXlsxName;
    }
}

// Load records if Excel exists
if ($currentXlsx !== '') {
    $records = readExcelData($currentXlsx);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Docx Extractor</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&family=Playfair+Display:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <a href="#" class="nav__logo">Extractor</a>
  <div class="nav__links">
    <a href="#upload">Upload</a>
    <a href="#data">Data</a>
    <a href="#folders">Folders</a>
  </div>
  <div class="nav__actions">
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
      <span class="theme-toggle__icon theme-toggle__icon--sun">☀</span>
      <span class="theme-toggle__icon theme-toggle__icon--moon">☾</span>
    </button>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero__inner">
    <div class="section__eyebrow">Introduction</div>
    <h1 class="hero__title">
      Extract content from <em>Word documents</em> into a structured database.
    </h1>
    <p class="hero__lead">
      Upload a <code>.docx</code> file and the system will extract sections, generate keywords and save everything to and MySQL.
    </p>
  </div>
</section>

<div class="divider"></div>

<!-- UPLOAD SECTION -->
<section class="section" id="upload">
  <div class="section__inner">
    <div class="section__eyebrow">01 — Upload</div>
    <h2 class="section__title">Drop your <em>document</em> here.</h2>

    <?php if ($message): ?>
      <div class="alert alert--<?= $status ?>">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="upload-form">
      <label class="upload-box">
        <input type="file" name="docx_file" accept=".docx" required>
        <span class="upload-box__icon">↑</span>
        <span class="upload-box__text">Choose a .docx file</span>
        <span class="upload-box__hint">or drag and drop</span>
      </label>

      <!-- CATEGORY DROPDOWN -->
      <div class="upload-select">
        <label for="categorySelect" class="upload-select__label">Category</label>
        <select name="category" id="categorySelect" class="upload-select__input" required>
          <option value="" disabled selected>-- Select a category --</option>
          <option value="letter">Letter</option>
          <option value="agreement">Agreement</option>
          <option value="general">General</option>
          <option value="network_facing">Network Facing</option>
          <option value="customer_facing">Customer Facing</option>
          <option value="report">Report</option>
          <option value="other">Other</option>
        </select>
      </div>

      <button type="submit" class="btn-primary">
        Extract Data
        <span>→</span>
      </button>
    </form>
  </div>
</section>

<div class="divider"></div>

<!-- DATA TABLE -->
<?php if (!empty($records)): ?>
<section class="section" id="data">
  <div class="section__inner section__inner--wide">
    <div class="section__eyebrow">02 — Extracted Data</div>
    <h2 class="section__title">Your <em>structured</em> extracted data.</h2>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Document</th>
            <th>Section</th>
            <th>Content</th>
            <th>Keywords</th>
            <th>Category</th>
            <th>Version</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $i => $row): ?>
            <?php if ($i === 0) continue; ?>
            <tr class="data-row" data-row='<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>'>
              <?php foreach ($row as $cell): ?>
                <td><?= htmlspecialchars(mb_strimwidth($cell, 0, 70, '...')) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($currentXlsx)): ?>
      <a href="<?= htmlspecialchars(basename($currentXlsx)) ?>" class="btn-primary" download style="margin-top:40px;display:inline-flex">
        Download Excel
        <span>→</span>
      </a>
    <?php endif; ?>
  </div>
</section>

<div class="divider"></div>
<?php endif; ?>

<!-- FOLDERS -->
<section class="section" id="folders">
  <div class="section__inner">
    <div class="section__eyebrow">03 — Folders</div>
    <h2 class="section__title">What's in the <em>workspace</em>.</h2>

    <div class="folders">
      <div class="folder">
        <div class="folder__head">
          <span class="folder__dot"></span>
          <span class="folder__title">docx/</span>
        </div>
        <ul class="folder__list">
          <?php if (empty($docxFiles)): ?>
            <li class="folder__empty">No files yet</li>
          <?php else: ?>
            <?php foreach ($docxFiles as $file): ?>
              <li class="folder__item">
                <a href="docx/<?= rawurlencode($file) ?>" target="_blank" class="folder__link" title="<?= htmlspecialchars($file) ?>">
                  <?= htmlspecialchars($file) ?>
                </a>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <div class="folder">
        <div class="folder__head">
          <span class="folder__dot folder__dot--muted"></span>
          <span class="folder__title">backup/</span>
        </div>

        <!-- SEARCH BOX -->
        <div class="folder__search">
          <input
            type="text"
            id="backupSearch"
            class="folder__search-input"
            placeholder="Search files..."
            autocomplete="off"
          >
        </div>

        <!-- BULK ACTION FORM -->
        <form method="POST" id="bulkDeleteForm">
          <div class="folder__actions">
            <div class="folder__actions-left">
              <label class="folder__checkbox-label">
                <input type="checkbox" id="selectAll">
                <span>Select all</span>
              </label>
            </div>
            <div class="folder__actions-right">
              <button type="submit"
                      name="delete_selected"
                      value="1"
                      class="btn-action btn-action--danger"
                      onclick="return confirm('Delete selected files?');">
                Delete Selected
              </button>
            </div>
          </div>

          <!-- .docx backups -->
          <ul class="folder__list" id="backupDocxList">
            <?php if (empty($backupFiles)): ?>
              <li class="folder__empty">No backup .docx files</li>
            <?php else: ?>
              <?php foreach ($backupFiles as $file): ?>
                <li class="folder__item" data-filename="<?= htmlspecialchars(strtolower($file)) ?>">
                  <label class="folder__check">
                    <input type="checkbox" name="files[]" value="<?= htmlspecialchars($file) ?>">
                  </label>
                  <a href="backup/<?= rawurlencode($file) ?>" target="_blank" class="folder__link" title="<?= htmlspecialchars($file) ?>">
                    <?= htmlspecialchars($file) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>

          <!-- .xlsx archives -->
          <div class="folder__subhead">
            <span class="folder__dot folder__dot--muted"></span>
            <span class="folder__subtitle">Extracted Excel Archives</span>
          </div>
          <ul class="folder__list" id="backupXlsxList">
            <?php if (empty($backupExcelFiles)): ?>
              <li class="folder__empty">No archived Excel files</li>
            <?php else: ?>
              <?php foreach ($backupExcelFiles as $file): ?>
                <li class="folder__item" data-filename="<?= htmlspecialchars(strtolower($file)) ?>">
                  <label class="folder__check">
                    <input type="checkbox" name="files[]" value="<?= htmlspecialchars($file) ?>">
                  </label>
                  <a href="backup/<?= rawurlencode($file) ?>" download class="folder__link" title="<?= htmlspecialchars($file) ?>">
                    📊 <?= htmlspecialchars($file) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </form>

        <!-- NO RESULTS MESSAGE -->
        <div class="folder__no-results" id="backupNoResults" style="display:none;">
          No matching files
        </div>
      </div>
    </div>
  </div>
</section>

<div class="divider"></div>

<!-- FOOTER -->
<footer class="footer">
  <div class="footer__inner">
    <div class="footer__brand">
      <h4>Extractor</h4>
      <p>A tool for turning Word documents into structured table.</p>
    </div>
    <div class="footer__col">
      <h5>System</h5>
      <ul>
        <li><a href="#upload">Upload</a></li>
        <li><a href="#data">Data</a></li>
        <li><a href="#folders">Folders</a></li>
      </ul>
    </div>
    <div class="footer__col">
      <h5>Output</h5>
      <ul>
        <?php if (!empty($currentXlsx)): ?>
          <li><a href="<?= htmlspecialchars(basename($currentXlsx)) ?>" download>Excel</a></li>
        <?php else: ?>
          <li><a href="#">Excel</a></li>
        <?php endif; ?>
        <li><a href="http://localhost/phpmyadmin" target="_blank">MySQL</a></li>
      </ul>
    </div>
  </div>
  <div class="footer__bottom">
    <span>© 2026 Docx Extractor</span>
    <span>Made with patience</span>
  </div>
</footer>

<!-- DETAILS MODAL -->
<div class="modal" id="detailsModal">
  <div class="modal__backdrop" id="modalBackdrop"></div>
  <div class="modal__content">
    <button class="modal__close" id="modalClose" aria-label="Close">×</button>
    <div class="modal__eyebrow">Chunk Details</div>
    <h2 class="modal__title" id="modalTitle">Section</h2>
    <div class="modal__body" id="modalBody"></div>
  </div>
</div>

<script src="script.js"></script>
</body>
</html>