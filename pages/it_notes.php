<?php
require '../inc/auth.php';
require '../inc/connection.php';

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// =========================================================
// SECURITY: ONLY ADMINS CAN ACCESS THIS PAGE
// =========================================================
$role = strtolower($_SESSION['role'] ?? 'staff');
if ($role !== 'admin') {
    echo '<script>
            alert("You are not an admin!");
            window.location.href = "dashboard.php";
          </script>';
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$pageTitle = "IT Knowledge Base";

// =========================================================
// ACTIONS: SAVE / DELETE
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save_note') {
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $noteId = (int) ($_POST['note_id'] ?? 0);

        if ($title === '') {
            $_SESSION['flash_text'] = "Title is required.";
            $_SESSION['flash_type'] = "error";
        } else {
            if ($noteId > 0) {
                $stmt = mysqli_prepare($connection, "UPDATE it_notes SET title = ?, content = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "ssi", $title, $content, $noteId);
            } else {
                $stmt = mysqli_prepare($connection, "INSERT INTO it_notes (title, content, created_by) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "ssi", $title, $content, $userId);
            }

            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['flash_text'] = $noteId > 0 ? "Note updated successfully." : "Note created successfully.";
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_text'] = "Unable to save the note. Please try again.";
                $_SESSION['flash_type'] = "error";
            }

            mysqli_stmt_close($stmt);
        }

        header("Location: it_notes.php" . ($noteId > 0 ? '?id=' . $noteId : ''));
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_note') {
        $noteId = (int) ($_POST['note_id'] ?? 0);

        if ($noteId > 0) {
            mysqli_query($connection, "DELETE FROM it_notes WHERE id = {$noteId}");
        }

        $_SESSION['flash_text'] = "Note deleted.";
        $_SESSION['flash_type'] = "info";
        header("Location: it_notes.php");
        exit;
    }
}

// =========================================================
// LOAD DATA
// =========================================================
$notesList = mysqli_query(
    $connection,
    "SELECT id, title, content, created_at, updated_at
     FROM it_notes
     ORDER BY updated_at DESC, id DESC"
);

$selectedNoteId = (int) ($_GET['id'] ?? 0);
$currentNote = null;

if ($selectedNoteId > 0) {
    $stmt = mysqli_prepare($connection, "SELECT * FROM it_notes WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $selectedNoteId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $currentNote = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Normalize legacy note content that may have been saved with HTML entities
// such as &amp;nbsp; instead of real HTML spacing.
$editorContent = '';
if ($currentNote) {
    $editorContent = (string) ($currentNote['content'] ?? '');
    $editorContent = html_entity_decode($editorContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Handle content that was accidentally encoded more than once.
    if (strpos($editorContent, '&nbsp;') !== false || strpos($editorContent, '&amp;') !== false) {
        $editorContent = html_entity_decode($editorContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

$noteCount = $notesList ? mysqli_num_rows($notesList) : 0;

include '../inc/header.php';
?>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<style>
    :root {
        --kb-primary: #4f46e5;
        --kb-primary-dark: #4338ca;
        --kb-primary-soft: #eef2ff;
        --kb-bg: #f6f8fc;
        --kb-card: #ffffff;
        --kb-border: #e5e7eb;
        --kb-text: #172033;
        --kb-muted: #64748b;
        --kb-light: #f8fafc;
        --kb-danger: #dc2626;
        --kb-danger-soft: #fef2f2;
        --kb-success: #15803d;
    }

    .knowledge-page {
        padding: 8px 0 28px;
    }

    .knowledge-head {
        display: flex;
        align-items: flex-end;
        padding: 4px 10px;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 20px;
    }

    .knowledge-head-left {
        min-width: 0;
    }

    .knowledge-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: var(--kb-primary);
        background: var(--kb-primary-soft);
        border: 1px solid #e0e7ff;
        border-radius: 999px;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .06em;
        text-transform: uppercase;
        margin-bottom: 8px;
    }

    .knowledge-head h1 {
        margin: 0;
        color: var(--kb-text);
        font-size: clamp(24px, 3vw, 31px);
        line-height: 1.15;
        font-weight: 800;
        letter-spacing: -.025em;
    }

    .knowledge-head p {
        margin: 7px 0 0;
        color: var(--kb-muted);
        font-size: 14px;
        max-width: 700px;
    }

    .knowledge-head-stat {
        display: flex;
        align-items: center;
        gap: 10px;
        background: var(--kb-card);
        border: 1px solid var(--kb-border);
        border-radius: 12px;
        padding: 10px 14px;
        color: var(--kb-muted);
        font-size: 12px;
        white-space: nowrap;
        box-shadow: 0 3px 12px rgba(15, 23, 42, .04);
    }

    .knowledge-head-stat strong {
        color: var(--kb-text);
        font-size: 16px;
    }

    .kb-alert {
        display: flex;
        align-items: center;
        gap: 10px;
        border-radius: 10px;
        padding: 11px 14px;
        margin-bottom: 16px;
        font-size: 13px;
        font-weight: 600;
        border: 1px solid transparent;
    }

    .kb-alert.success {
        color: var(--kb-success);
        background: #f0fdf4;
        border-color: #bbf7d0;
    }

    .kb-alert.error {
        color: #b91c1c;
        background: var(--kb-danger-soft);
        border-color: #fecaca;
    }

    .kb-alert.info {
        color: #1d4ed8;
        background: #eff6ff;
        border-color: #bfdbfe;
    }

    .kb-alert button {
        margin-left: auto;
        border: 0;
        background: transparent;
        color: inherit;
        cursor: pointer;
        opacity: .7;
        font-size: 16px;
    }

    .kb-workspace {
        display: grid;
        grid-template-columns: 310px minmax(0, 1fr);
        min-height: 650px;
        height: calc(100vh - 245px);
        min-height: 560px;
        max-height: 820px;
        background: var(--kb-card);
        border: 1px solid var(--kb-border);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 8px 30px rgba(15, 23, 42, .06);
    }

    /* Sidebar */
    .kb-sidebar {
        min-width: 0;
        display: flex;
        flex-direction: column;
        background: #fbfcfe;
        border-right: 1px solid var(--kb-border);
    }

    .kb-sidebar-top {
        padding: 16px;
        border-bottom: 1px solid var(--kb-border);
    }

    .kb-sidebar-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 13px;
    }

    .kb-sidebar-title h2 {
        margin: 0;
        font-size: 14px;
        color: var(--kb-text);
        font-weight: 800;
    }

    .kb-new-btn,
    .kb-save-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 0;
        cursor: pointer;
        font-weight: 700;
        transition: .18s ease;
        text-decoration: none;
    }

    .kb-new-btn {
        padding: 7px 10px;
        border-radius: 8px;
        color: #fff;
        background: var(--kb-primary);
        font-size: 12px;
        box-shadow: 0 3px 8px rgba(79, 70, 229, .18);
    }

    .kb-new-btn:hover,
    .kb-save-btn:hover {
        background: var(--kb-primary-dark);
        transform: translateY(-1px);
    }

    .kb-search {
        position: relative;
    }

    .kb-search i {
        position: absolute;
        left: 11px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 12px;
        pointer-events: none;
    }

    .kb-search input {
        width: 100%;
        height: 37px;
        padding: 0 11px 0 32px;
        border: 1px solid #dbe1ea;
        border-radius: 9px;
        outline: none;
        background: #fff;
        color: var(--kb-text);
        font-size: 12px;
        box-sizing: border-box;
        transition: .18s ease;
    }

    .kb-search input:focus {
        border-color: #a5b4fc;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .10);
    }

    .kb-notes-list {
        flex: 1;
        overflow-y: auto;
        padding: 8px;
    }

    .kb-note-item {
        position: relative;
        display: block;
        padding: 12px 10px 12px 13px;
        margin-bottom: 3px;
        border-radius: 10px;
        color: inherit;
        text-decoration: none;
        cursor: pointer;
        transition: background .16s ease, box-shadow .16s ease;
    }

    .kb-note-item:hover {
        background: #f1f5f9;
    }

    .kb-note-item.active {
        background: var(--kb-primary-soft);
        box-shadow: inset 3px 0 0 var(--kb-primary);
    }

    .kb-note-main {
        min-width: 0;
        padding-right: 28px;
    }

    .kb-note-title {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--kb-text);
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .kb-note-item.active .kb-note-title {
        color: #3730a3;
    }

    .kb-note-preview {
        color: #94a3b8;
        font-size: 11px;
        line-height: 1.45;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        margin-bottom: 5px;
    }

    .kb-note-date {
        color: #a1aab8;
        font-size: 10px;
    }

    .kb-delete-form {
        position: absolute;
        right: 7px;
        top: 10px;
        opacity: 0;
        transition: opacity .15s ease;
    }

    .kb-note-item:hover .kb-delete-form,
    .kb-note-item.active .kb-delete-form {
        opacity: 1;
    }

    .kb-delete-btn {
        width: 27px;
        height: 27px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #94a3b8;
        cursor: pointer;
    }

    .kb-delete-btn:hover {
        color: var(--kb-danger);
        background: #fff;
    }

    .kb-empty-list {
        padding: 45px 20px;
        text-align: center;
        color: #94a3b8;
        font-size: 12px;
    }

    .kb-empty-list i {
        display: block;
        font-size: 25px;
        margin-bottom: 10px;
        color: #cbd5e1;
    }

    /* Editor */
    .kb-editor {
        min-width: 0;
        min-height: 0;
        display: flex;
        flex-direction: column;
        background: #fff;
        overflow: hidden;
    }

    .kb-editor-header {
        display: flex;
        align-items: center;
        gap: 15px;
        min-height: 76px;
        padding: 12px 18px;
        border-bottom: 1px solid var(--kb-border);
        background: #fff;
    }

    .kb-doc-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        display: grid;
        place-items: center;
        color: var(--kb-primary);
        background: var(--kb-primary-soft);
        border-radius: 10px;
        font-size: 17px;
    }

    .kb-title-wrap {
        min-width: 0;
        flex: 1;
    }

    .kb-title-input {
        display: block;
        width: 100%;
        border: 0;
        outline: 0;
        background: transparent;
        color: var(--kb-text);
        font-size: 19px;
        font-weight: 800;
        padding: 0;
        letter-spacing: -.015em;
    }

    .kb-title-input::placeholder {
        color: #a1aab8;
    }

    .kb-editor-meta {
        display: block;
        margin-top: 5px;
        color: #94a3b8;
        font-size: 10px;
    }

    .kb-save-btn {
        flex: 0 0 auto;
        padding: 9px 15px;
        border-radius: 9px;
        color: #fff;
        background: var(--kb-primary);
        font-size: 12px;
        box-shadow: 0 3px 8px rgba(79, 70, 229, .18);
    }

    .kb-editor-body {
        flex: 1 1 auto;
        min-height: 0;
        height: 100%;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .kb-editor .ql-container {
        min-height: 0;
        height: 0;
        flex: 1 1 auto;
        overflow: hidden;
    }

    .kb-editor .ql-editor {
        height: 100%;
        min-height: 0;
        overflow-y: auto !important;
        overflow-x: hidden;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
    }

    #editor {
        flex: 1;
        min-height: 0;
        border: 0;
        font-size: 14px;
        color: #334155;
    }

    #editor .ql-editor {
        min-height: 100%;
        box-sizing: border-box;
        padding: 24px 30px 35px;
        line-height: 1.75;
    }

    #editor .ql-editor.ql-blank::before {
        left: 30px;
        right: 30px;
        color: #a1aab8;
        font-style: normal;
    }

    .kb-editor .ql-toolbar.ql-snow {
        border: 0;
        border-bottom: 1px solid var(--kb-border);
        padding: 9px 18px;
        background: #fbfcfe;
    }

    .kb-editor .ql-container.ql-snow {
        border: 0;
        flex: 1;
        min-height: 0;
    }

    .kb-editor .ql-snow .ql-picker {
        font-size: 12px;
    }

    .kb-editor .ql-snow button:hover,
    .kb-editor .ql-snow button.ql-active,
    .kb-editor .ql-snow .ql-picker-label:hover {
        color: var(--kb-primary);
    }

    .kb-editor-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 8px 18px;
        border-top: 1px solid #eef2f7;
        background: #fff;
        color: #94a3b8;
        font-size: 10px;
    }

    .kb-editor-footer span {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .kb-no-note {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 40px;
        color: var(--kb-muted);
    }

    .kb-no-note-inner {
        max-width: 360px;
    }

    .kb-no-note-icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 15px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        background: var(--kb-primary-soft);
        color: var(--kb-primary);
        font-size: 25px;
    }

    .kb-no-note h3 {
        margin: 0 0 7px;
        color: var(--kb-text);
        font-size: 18px;
    }

    .kb-no-note p {
        margin: 0 0 18px;
        font-size: 13px;
        line-height: 1.6;
    }

    @media (max-width: 900px) {
        .kb-workspace {
            grid-template-columns: 250px minmax(0, 1fr);
        }

        .knowledge-head-stat {
            display: none;
        }
    }

    @media (max-width: 700px) {
        .knowledge-page {
            padding-top: 0;
        }

        .knowledge-head {
            margin-bottom: 14px;
        }

        .knowledge-head p {
            font-size: 12px;
        }

        .kb-workspace {
            display: flex;
            flex-direction: column;
            height: auto;
            max-height: none;
            min-height: 0;
            border-radius: 12px;
        }

        .kb-sidebar {
            border-right: 0;
            border-bottom: 1px solid var(--kb-border);
            max-height: 280px;
        }

        .kb-editor {
            min-height: 620px;
        }

        .kb-editor-header {
            padding: 12px;
        }

        .kb-doc-icon {
            display: none;
        }

        #editor .ql-editor {
            padding: 20px 18px 30px;
        }

        #editor .ql-editor.ql-blank::before {
            left: 18px;
            right: 18px;
        }

        .kb-editor .ql-toolbar.ql-snow {
            padding: 7px 10px;
        }

        .kb-save-btn {
            padding: 9px 11px;
        }
    }
</style>

<div class="users-page knowledge-page">
    <div class="knowledge-head">
        <div class="knowledge-head-left">
            <div class="knowledge-eyebrow">
                <i class="fa-solid fa-shield-halved"></i>
                Administration
            </div>
            <h2>IT Knowledge Base</h2>
            <p>Keep internal IT procedures, system guides, configurations and important technical notes organised in one
                secure workspace.</p>
        </div>

        <div class="knowledge-head-stat">
            <i class="fa-regular fa-file-lines"></i>
            <strong><?= $noteCount ?></strong>
            <?= $noteCount === 1 ? 'note' : 'notes' ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_text'])): ?>
        <div class="kb-alert <?= h($_SESSION['flash_type'] ?? 'info') ?>" id="usersToast">
            <i
                class="fa-solid <?= ($_SESSION['flash_type'] ?? '') === 'success' ? 'fa-circle-check' : (($_SESSION['flash_type'] ?? '') === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info') ?>"></i>
            <span><?= h($_SESSION['flash_text']) ?></span>
            <button type="button" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
        </div>
        <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <div class="kb-workspace">
        <!-- SIDEBAR -->
        <aside class="kb-sidebar">
            <div class="kb-sidebar-top">
                <div class="kb-sidebar-title">
                    <h2>Knowledge Notes</h2>
                    <a href="it_notes.php?id=0" class="kb-new-btn">
                        <i class="fa-solid fa-plus"></i>
                        New
                    </a>
                </div>

                <div class="kb-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" id="noteSearch" placeholder="Search notes..." autocomplete="off">
                </div>
            </div>

            <div class="kb-notes-list" id="notesList">
                <?php if ($notesList && mysqli_num_rows($notesList) > 0): ?>
                    <?php while ($n = mysqli_fetch_assoc($notesList)): ?>
                        <?php
                        $previewText = html_entity_decode((string) ($n['content'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $previewText = html_entity_decode($previewText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $preview = trim(preg_replace('/\s+/', ' ', strip_tags($previewText)));
                        $displayDate = !empty($n['updated_at']) ? $n['updated_at'] : ($n['created_at'] ?? '');
                        ?>
                        <div class="kb-note-item <?= $selectedNoteId === (int) $n['id'] ? 'active' : '' ?>"
                            data-title="<?= h(strtolower($n['title'])) ?>" data-preview="<?= h(strtolower($preview)) ?>"
                            onclick="openNote(<?= (int) $n['id'] ?>)">
                            <div class="kb-note-main">
                                <span class="kb-note-title"><?= h($n['title']) ?></span>
                                <div class="kb-note-preview"><?= h($preview !== '' ? $preview : 'No content yet') ?></div>
                                <div class="kb-note-date">
                                    <i class="fa-regular fa-clock"></i>
                                    <?= h($displayDate) ?>
                                </div>
                            </div>

                            <form method="POST" class="kb-delete-form" onclick="event.stopPropagation()"
                                onsubmit="return confirm('Delete this note? This action cannot be undone.');">
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                                <button type="submit" class="kb-delete-btn" title="Delete note" aria-label="Delete note">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </form>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="kb-empty-list" id="noNotesMessage">
                        <i class="fa-regular fa-note-sticky"></i>
                        No notes yet.<br>Create your first knowledge note.
                    </div>
                <?php endif; ?>

                <div class="kb-empty-list" id="noSearchResults" style="display:none;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    No matching notes found.
                </div>
            </div>
        </aside>

        <!-- EDITOR -->
        <main class="kb-editor">
            <?php if ($currentNote): ?>
                <form method="POST" id="noteForm" class="kb-editor-body">
                    <div class="kb-editor-header">
                        <div class="kb-doc-icon">
                            <i class="fa-regular fa-file-lines"></i>
                        </div>

                        <div class="kb-title-wrap">
                            <input type="text" name="title" class="kb-title-input" value="<?= h($currentNote['title']) ?>"
                                placeholder="Note title..." required>
                            <span class="kb-editor-meta">
                                Editing note #<?= (int) $currentNote['id'] ?>
                                <?php if (!empty($currentNote['updated_at'])): ?>
                                    &bull; Last updated <?= h($currentNote['updated_at']) ?>
                                <?php endif; ?>
                            </span>
                        </div>

                        <input type="hidden" name="action" value="save_note">
                        <input type="hidden" name="note_id" value="<?= (int) $currentNote['id'] ?>">

                        <button type="submit" class="kb-save-btn">
                            <i class="fa-solid fa-check"></i>
                            Save Note
                        </button>
                    </div>

                    <div id="editor"><?= $editorContent ?></div>

                    <div class="kb-editor-footer">
                        <span><i class="fa-solid fa-lock"></i> Admin-only knowledge base</span>
                        <span id="wordCount">0 words</span>
                    </div>
                </form>
            <?php else: ?>
                <form method="POST" id="noteForm" class="kb-editor-body">
                    <div class="kb-editor-header">
                        <div class="kb-doc-icon">
                            <i class="fa-regular fa-file-circle-plus"></i>
                        </div>

                        <div class="kb-title-wrap">
                            <input type="text" name="title" class="kb-title-input" placeholder="Untitled Note..." required>
                            <span class="kb-editor-meta">Create a new internal knowledge note</span>
                        </div>

                        <input type="hidden" name="action" value="save_note">
                        <input type="hidden" name="note_id" value="0">

                        <button type="submit" class="kb-save-btn">
                            <i class="fa-solid fa-check"></i>
                            Save Note
                        </button>
                    </div>

                    <div id="editor"></div>

                    <div class="kb-editor-footer">
                        <span><i class="fa-solid fa-lock"></i> Admin-only knowledge base</span>
                        <span id="wordCount">0 words</span>
                    </div>
                </form>
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const editorElement = document.getElementById('editor');
        const noteForm = document.getElementById('noteForm');

        if (!editorElement || !noteForm) return;

        const quill = new Quill('#editor', {
            theme: 'snow',
            placeholder: 'Start writing your IT note here... You can add procedures, configuration details, troubleshooting steps, links and other internal documentation.',
            modules: {
                toolbar: [
                    [{
                        header: [1, 2, 3, false]
                    }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{
                        list: 'ordered'
                    }, {
                        list: 'bullet'
                    }],
                    [{
                        indent: '-1'
                    }, {
                        indent: '+1'
                    }],
                    ['blockquote', 'code-block', 'link'],
                    ['clean']
                ]
            }
        });

        const wordCount = document.getElementById('wordCount');

        function updateWordCount() {
            if (!wordCount) return;
            const text = quill.getText().trim();
            const count = text ? text.split(/\s+/).length : 0;
            wordCount.textContent = count + (count === 1 ? ' word' : ' words');
        }

        quill.on('text-change', updateWordCount);
        updateWordCount();

        noteForm.addEventListener('submit', function() {
            let content = noteForm.querySelector('input[name="content"]');

            if (!content) {
                content = document.createElement('input');
                content.type = 'hidden';
                content.name = 'content';
                noteForm.appendChild(content);
            }

            content.value = quill.root.innerHTML;

            const saveButton = noteForm.querySelector('.kb-save-btn');
            if (saveButton) {
                saveButton.disabled = true;
                saveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
            }
        });

        const searchInput = document.getElementById('noteSearch');
        const noSearchResults = document.getElementById('noSearchResults');

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                const items = document.querySelectorAll('.kb-note-item');
                let visible = 0;

                items.forEach(function(item) {
                    const haystack = (item.dataset.title || '') + ' ' + (item.dataset.preview ||
                        '');
                    const match = !query || haystack.includes(query);
                    item.style.display = match ? '' : 'none';
                    if (match) visible++;
                });

                if (noSearchResults) {
                    noSearchResults.style.display = query && visible === 0 ? '' : 'none';
                }
            });
        }

        // Prevent accidental navigation when clicking the delete form.
        document.querySelectorAll('.kb-delete-form').forEach(function(form) {
            form.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        });
    });

    function openNote(id) {
        window.location.href = 'it_notes.php?id=' + encodeURIComponent(id);
    }
</script>

<?php include '../inc/footer.php'; ?>