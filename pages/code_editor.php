<?php
require '../inc/auth.php';
require '../inc/connection.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// --------------------------------------------------
// 1. ADMIN ACCESS CONTROL
// --------------------------------------------------
$role = strtolower(trim((string) ($_SESSION['role'] ?? 'staff')));

if ($role !== 'admin') {
    header('Location: dashboard.php');
    exit;
}
require_once '../inc/editor_otp_gate.php';
editorOtpGate($connection);

// --------------------------------------------------
// 2. CONFIGURATION
// This editor can edit PHP files in this directory only.
// --------------------------------------------------
$baseDir = realpath(__DIR__);
$editorFile = 'code_editor.php';
$maxFileSize = 2 * 1024 * 1024; // 2 MB

if ($baseDir === false || !is_dir($baseDir)) {
    http_response_code(500);
    exit('The editor directory is unavailable.');
}

// CSRF token
if (empty($_SESSION['code_editor_csrf'])) {
    $_SESSION['code_editor_csrf'] = bin2hex(random_bytes(32));
}

// --------------------------------------------------
// 3. FLASH MESSAGES
// --------------------------------------------------
function editorFlash(string $message, string $type = 'error'): void
{
    $_SESSION['flash_text'] = $message;
    $_SESSION['flash_type'] = in_array(
        $type,
        ['success', 'error', 'warning'],
        true
    ) ? $type : 'error';
}

// --------------------------------------------------
// 4. VALIDATE AN ALLOWED PHP FILE
// --------------------------------------------------
function editorGetFile(string $name, string $baseDir): ?string
{
    // Only simple PHP filenames are allowed.
    if (
        $name === '' ||
        basename($name) !== $name ||
        !preg_match('/\A[a-zA-Z0-9_.-]+\.php\z/i', $name) ||
        $name === '.' ||
        $name === '..' ||
        strcasecmp($name, 'code_editor.php') === 0
    ) {
        return null;
    }

    $candidate = $baseDir . DIRECTORY_SEPARATOR . $name;

    // Reject symbolic links.
    if (is_link($candidate) || !is_file($candidate)) {
        return null;
    }

    $realPath = realpath($candidate);

    if ($realPath === false) {
        return null;
    }

    // Case-insensitive comparison also accommodates Windows/WAMP.
    $basePrefix = rtrim($baseDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;

    if (
        strncasecmp($realPath, $basePrefix, strlen($basePrefix)) !== 0
    ) {
        return null;
    }

    if (strtolower(pathinfo($realPath, PATHINFO_EXTENSION)) !== 'php') {
        return null;
    }

    return $realPath;
}

// --------------------------------------------------
// 5. SAVE FILE
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_file'])) {

    $fileName = is_string($_POST['file_name'] ?? null)
        ? $_POST['file_name']
        : '';

    $content = is_string($_POST['content'] ?? null)
        ? $_POST['content']
        : null;

    $csrfToken = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token']
        : '';

    $originalHash = is_string($_POST['original_hash'] ?? null)
        ? $_POST['original_hash']
        : '';

    $redirectUrl = 'code_editor.php?file=' . rawurlencode($fileName);

    if (
        !hash_equals(
            (string) $_SESSION['code_editor_csrf'],
            $csrfToken
        )
    ) {
        editorFlash('Security token expired or invalid. Reload the page and try again.');
    } elseif ($content === null) {
        editorFlash('Invalid file content received.');
    } elseif (strlen($content) > $maxFileSize) {
        editorFlash('File exceeds the 2 MB editing limit.');
    } elseif (!preg_match('/\A[a-f0-9]{64}\z/i', $originalHash)) {
        editorFlash('Invalid file version. Reload the editor and try again.');
    } else {
        $realPath = editorGetFile($fileName, $baseDir);

        if ($realPath === null || !is_readable($realPath) || !is_writable($realPath)) {
            editorFlash('Access denied. The file does not exist or is not writable.');
        } else {
            // Detect changes made after this file was opened.
            $currentHash = hash_file('sha256', $realPath);

            if (
                $currentHash === false ||
                !hash_equals($originalHash, $currentHash)
            ) {
                editorFlash(
                    'This file has changed since you opened it. '
                        . 'Reload it before saving to avoid overwriting changes.',
                    'warning'
                );
            } else {
                // Temporary recovery copy in case a write fails.
                $backupPath = tempnam(
                    sys_get_temp_dir(),
                    'code_editor_backup_'
                );

                $saved = false;

                if ($backupPath !== false && copy($realPath, $backupPath)) {
                    $bytesWritten = file_put_contents(
                        $realPath,
                        $content,
                        LOCK_EX
                    );

                    if ($bytesWritten === strlen($content)) {
                        $saved = true;
                    } else {
                        // Restore the original file if the write was incomplete.
                        if (copy($backupPath, $realPath)) {
                            editorFlash(
                                'The write was incomplete. The original file '
                                    . 'was restored. Check disk space and permissions.'
                            );
                        } else {
                            editorFlash(
                                'CRITICAL: The write failed and automatic '
                                    . 'restoration also failed. Restore the file '
                                    . 'from your backup immediately.'
                            );
                        }
                    }

                    @unlink($backupPath);
                } else {
                    if ($backupPath !== false) {
                        @unlink($backupPath);
                    }

                    editorFlash(
                        'Unable to create a recovery copy. No changes were saved.'
                    );
                }

                if ($saved) {
                    editorFlash('File saved successfully.', 'success');
                }
            }
        }
    }

    header('Location: ' . $redirectUrl);
    exit;
}

// --------------------------------------------------
// 6. LOAD SELECTED FILE
// --------------------------------------------------
$fileName = is_string($_GET['file'] ?? null)
    ? $_GET['file']
    : '';

$filePath = $fileName !== ''
    ? editorGetFile($fileName, $baseDir)
    : null;

$content = '';
$originalHash = '';
$fileError = '';

if ($fileName !== '' && $filePath === null) {
    $fileError = 'The requested file is unavailable or not permitted.';
}

if ($filePath !== null) {
    if (!is_readable($filePath)) {
        $fileError = 'This file cannot be read. Check its permissions.';
        $filePath = null;
    } elseif (filesize($filePath) > $maxFileSize) {
        $fileError = 'This file exceeds the 2 MB editing limit.';
        $filePath = null;
    } else {
        $loadedContent = file_get_contents($filePath);

        if ($loadedContent === false) {
            $fileError = 'Unable to read the selected file.';
            $filePath = null;
        } else {
            $content = $loadedContent;
            $originalHash = hash('sha256', $content);
        }
    }
}

// --------------------------------------------------
// 7. LIST ALLOWED PHP FILES
// --------------------------------------------------
$phpFiles = [];

foreach (scandir($baseDir) ?: [] as $file) {
    if (editorGetFile($file, $baseDir) !== null) {
        $phpFiles[] = $file;
    }
}

natcasesort($phpFiles);

include '../inc/header.php';
?>

<style>
.editor-container {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    grid-template-rows: minmax(0, 1fr);
    height: calc(130vh - 170px);
    min-height: 450px;
    background: #1e1e1e;
    color: #fff;
    border: 1px solid #333;
    border-radius: 12px;
    overflow: hidden;
    margin-top: 20px;
}

.editor-sidebar {
    background: #252526;
    border-right: 1px solid #333;
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    height: 100%;
    overflow: hidden;
}

.sidebar-header {
    flex: 0 0 auto;
    padding: 15px;
    background: #2d2d2d;
    font-weight: 700;
    font-size: 13px;
    border-bottom: 1px solid #333;
    color: #ccc;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}

.file-count {
    font-weight: 500;
    font-size: 11px;
    color: #888;
}

.sidebar-search {
    flex: 0 0 auto;
    padding: 8px 10px;
    border-bottom: 1px solid #333;
    background: #252526;
}

.sidebar-search input {
    width: 100%;
    box-sizing: border-box;
    background: #3c3c3c;
    color: #eee;
    border: 1px solid #3c3c3c;
    border-radius: 4px;
    padding: 7px 9px;
    font-size: 12px;
    outline: none;
}

.sidebar-search input:focus {
    border-color: #007acc;
}

.file-list {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 8px 0;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #555 #252526;
}

.file-list::-webkit-scrollbar {
    width: 10px;
}

.file-list::-webkit-scrollbar-track {
    background: #252526;
}

.file-list::-webkit-scrollbar-thumb {
    background: #555;
    border-radius: 5px;
    border: 2px solid #252526;
}

.file-list::-webkit-scrollbar-thumb:hover {
    background: #777;
}

.file-item {
    padding: 9px 12px;
    cursor: pointer;
    font-size: 13px;
    color: #aaa;
    display: block;
    text-decoration: none;
    overflow-wrap: anywhere;
    border-left: 3px solid transparent;
}

.file-item:hover,
.file-item:focus-visible {
    background: #37373d;
    color: #fff;
}

.file-item.active {
    background: #37373d;
    color: #fff;
    border-left-color: #007acc;
}

.file-empty {
    display: none;
    padding: 12px;
    color: #999;
    font-size: 12px;
}

.editor-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    height: 100%;
    background: #1e1e1e;
}

.editor-toolbar {
    flex: 0 0 auto;
    padding: 10px 15px;
    background: #2d2d2d;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #333;
    flex-wrap: wrap;
}

.toolbar-title {
    font-size: 13px;
    color: #ccc;
    overflow-wrap: anywhere;
}

.toolbar-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.editor-status {
    font-size: 12px;
    color: #aaa;
}

.btn-save {
    background: #007acc;
    color: #fff;
    border: 0;
    padding: 8px 15px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
}

.btn-save:hover:not(:disabled) {
    background: #005a9e;
}

.btn-save:disabled {
    opacity: .5;
    cursor: not-allowed;
}

#ace-editor {
    flex: 1 1 auto;
    width: 100%;
    min-height: 0;
}

.editor-notice {
    margin-top: 12px;
    padding: 12px;
    border-radius: 6px;
    background: #fff3cd;
    color: #664d03;
    overflow-wrap: anywhere;
}

.editor-toast {
    margin: 12px 0;
    padding: 12px 15px;
    border-radius: 6px;
}

.editor-toast-success {
    background: #d1e7dd;
    color: #0f5132;
}

.editor-toast-error {
    background: #f8d7da;
    color: #842029;
}

.editor-toast-warning {
    background: #fff3cd;
    color: #664d03;
}

@media (max-width: 700px) {
    .editor-container {
        grid-template-columns: 130px minmax(0, 1fr);
        height: calc(100vh - 200px);
        min-height: 400px;
    }

    .file-item {
        padding: 10px 7px;
        font-size: 11px;
    }

    .sidebar-header {
        padding: 10px 7px;
        font-size: 11px;
    }

    .file-count {
        display: none;
    }

    .sidebar-search {
        padding: 6px;
    }

    .editor-toolbar {
        padding: 10px;
    }

    .toolbar-actions {
        width: 100%;
        justify-content: space-between;
    }
}
</style>

<div class="users-page">
    <div class="users-page-head">
        <div>
            <div class="users-eyebrow">System Tools</div>
            <h2>Customize your file</h2>
            <p style="font-size: 14px;">Edit PHP files directly from the browser. Use with extreme caution.</p>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_text'])): ?>
    <?php
        $flashText = (string) $_SESSION['flash_text'];
        $flashType = (string) ($_SESSION['flash_type'] ?? 'error');

        unset($_SESSION['flash_text'], $_SESSION['flash_type']);

        if (!in_array($flashType, ['success', 'error', 'warning'], true)) {
            $flashType = 'error';
        }
        ?>

    <div class="editor-toast editor-toast-<?= h($flashType) ?>" role="status">
        <?= h($flashText) ?>
    </div>
    <?php endif; ?>

    <?php if ($fileError !== ''): ?>
    <div class="editor-notice" role="alert">
        <?= h($fileError) ?>
    </div>
    <?php endif; ?>

    <div class="editor-container">
        <aside class="editor-sidebar">
            <div class="sidebar-header">
                <span>Project Files</span>
                <span class="file-count" id="fileCount"><?= count($phpFiles) ?></span>
            </div>

            <div class="sidebar-search">
                <input type="search" id="fileFilter" placeholder="Search files..." autocomplete="off"
                    aria-label="Search PHP files">
            </div>

            <nav class="file-list" id="fileList" aria-label="PHP project files">
                <?php foreach ($phpFiles as $file): ?>
                <a href="code_editor.php?file=<?= rawurlencode($file) ?>"
                    class="file-item <?= $fileName === $file && $filePath !== null ? 'active' : '' ?>"
                    data-name="<?= h(strtolower($file)) ?>">
                    <i class="fa-solid fa-file-code" aria-hidden="true"></i>
                    <?= h($file) ?>
                </a>
                <?php endforeach; ?>

                <?php if (!$phpFiles): ?>
                <div style="padding:12px;color:#999;font-size:12px;">
                    No editable PHP files found.
                </div>
                <?php endif; ?>

                <div class="file-empty" id="fileEmpty">No files match your search.</div>
            </nav>
        </aside>

        <main class="editor-main">
            <?php if ($filePath !== null): ?>
            <div class="editor-toolbar">
                <span class="toolbar-title">
                    Editing: <strong><?= h($fileName) ?></strong>
                </span>

                <form method="POST" id="saveForm" class="toolbar-actions">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['code_editor_csrf']) ?>">

                    <input type="hidden" name="file_name" value="<?= h($fileName) ?>">

                    <input type="hidden" name="original_hash" value="<?= h($originalHash) ?>">

                    <input type="hidden" name="content" id="code_content">
                    <input type="hidden" name="save_file" value="1">

                    <span id="editorStatus" class="editor-status">
                        No unsaved changes
                    </span>

                    <button type="submit" id="saveButton" class="btn-save" disabled>
                        Save File
                    </button>
                </form>
            </div>

            <div id="ace-editor" aria-label="PHP code editor"></div>
            <?php else: ?>
            <div
                style="display:flex;align-items:center;justify-content:center;height:100%;padding:20px;color:#888;text-align:center;flex-direction:column;">
                <i class="fa-solid fa-code fa-3x" style="margin-bottom:15px;opacity:.3;"></i>
                <p>Select a PHP file from the sidebar to start editing.</p>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
// Sidebar: live file filter + keep the active file in view.
(function() {
    const filter = document.getElementById('fileFilter');
    const list = document.getElementById('fileList');
    const empty = document.getElementById('fileEmpty');
    const count = document.getElementById('fileCount');

    if (!filter || !list) {
        return;
    }

    const items = Array.from(list.querySelectorAll('.file-item'));
    const total = items.length;

    filter.addEventListener('input', function() {
        const term = filter.value.trim().toLowerCase();
        let visible = 0;

        items.forEach(function(item) {
            const match = term === '' || item.dataset.name.indexOf(term) !== -1;
            item.style.display = match ? '' : 'none';
            if (match) {
                visible++;
            }
        });

        empty.style.display = (visible === 0 && total > 0) ? 'block' : 'none';
        count.textContent = term === '' ? String(total) : visible + '/' + total;
    });

    const active = list.querySelector('.file-item.active');
    if (active) {
        // Scroll only the sidebar list, not the whole page.
        list.scrollTop = Math.max(0, active.offsetTop - list.clientHeight / 2);
    }
})();
</script>

<?php if ($filePath !== null): ?>
<script src="https://cdn.jsdelivr.net/npm/ace-builds@1.32.6/src-min-noconflict/ace.js"></script>
<script src="https://cdn.jsdelivr.net/npm/ace-builds@1.32.6/src-min-noconflict/mode-php.js"></script>
<script src="https://cdn.jsdelivr.net/npm/ace-builds@1.32.6/src-min-noconflict/theme-monokai.js"></script>

<script>
(function() {
    const editorElement = document.getElementById('ace-editor');
    const saveForm = document.getElementById('saveForm');
    const saveButton = document.getElementById('saveButton');
    const contentInput = document.getElementById('code_content');
    const statusElement = document.getElementById('editorStatus');

    if (!window.ace || !editorElement) {
        statusElement.textContent = 'Editor failed to load. Check your internet connection.';
        return;
    }

    const editor = ace.edit('ace-editor');

    editor.setTheme('ace/theme/monokai');
    editor.session.setMode('ace/mode/php');

    // Safely encode PHP source for JavaScript.
    editor.setValue(
        <?= json_encode(
                    $content,
                    JSON_HEX_TAG |
                        JSON_HEX_APOS |
                        JSON_HEX_AMP |
                        JSON_HEX_QUOT |
                        JSON_INVALID_UTF8_SUBSTITUTE
                ) ?>,
        -1
    );

    editor.setOptions({
        fontSize: '14px',
        showPrintMargin: false,
        useSoftTabs: true,
        tabSize: 4,
        wrap: false,
        showGutter: true,
        highlightActiveLine: true,
        scrollPastEnd: 0.3
    });

    // Ace's undo history is the "dirty" marker, so start clean.
    editor.session.getUndoManager().reset();

    editor.commands.addCommand({
        name: 'saveFile',
        bindKey: {
            win: 'Ctrl-S',
            mac: 'Command-S'
        },
        exec: function() {
            if (!saveButton.disabled) {
                saveForm.requestSubmit();
            }
        }
    });

    function updateDirtyState() {
        const dirty = editor.session.getUndoManager().hasUndo();

        saveButton.disabled = !dirty;
        statusElement.textContent = dirty ?
            'Unsaved changes' :
            'No unsaved changes';
    }

    editor.session.on('change', updateDirtyState);

    saveForm.addEventListener('submit', function(event) {
        if (saveButton.disabled) {
            event.preventDefault();
            return;
        }

        if (!window.confirm('Save changes to <?= h($fileName) ?>?')) {
            event.preventDefault();
            return;
        }

        contentInput.value = editor.getValue();
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
        statusElement.textContent = 'Saving file...';
    });

    let submitting = false;
    saveForm.addEventListener('submit', function() {
        submitting = true;
    });

    window.addEventListener('beforeunload', function(event) {
        if (!submitting && editor.session.getUndoManager().hasUndo()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // Correct Ace sizing when the browser window changes.
    window.addEventListener('resize', function() {
        editor.resize();
    });

    // Expose the editor for optional debugging in the browser console.
    window.codeEditorInstance = editor;

    editor.focus();
    editor.resize();
})();
</script>
<?php endif; ?>

<?php include '../inc/footer.php'; ?>