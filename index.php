<?php

declare(strict_types=1);

session_start();

$baseDirectory = __DIR__;
$mediaExtensions = [
    'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg',
    'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac',
    'mp4', 'webm', 'mov', 'mkv', 'avi', 'm4v',
];
$defaultPasswordHash = '$2y$12$4CrnpjEP9/UynbMgOd1bcuO1RA2ByYMU5Mc6SyUNDTb1axpKYcYCa'; // BitteAendern123!
$configuredPasswordHash = getenv('MEDIA_MANAGER_PASSWORD_HASH') ?: $defaultPasswordHash;

function isAuthenticated(): bool
{
    return ($_SESSION['is_authenticated'] ?? false) === true;
}

function sanitizeRelativePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? '';
    $path = trim($path, '/');

    if ($path === '' || $path === '.') {
        return '';
    }

    $parts = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }

    return implode('/', $parts);
}

function resolvePath(string $baseDirectory, string $relativePath): ?string
{
    $safeRelativePath = sanitizeRelativePath($relativePath);
    $target = $safeRelativePath === '' ? $baseDirectory : $baseDirectory . DIRECTORY_SEPARATOR . $safeRelativePath;

    $realTarget = realpath($target);
    if ($realTarget === false) {
        return null;
    }

    $realBase = realpath($baseDirectory);
    if ($realBase === false) {
        return null;
    }

    if (strpos($realTarget, $realBase) !== 0) {
        return null;
    }

    return $realTarget;
}

function relativeFromBase(string $baseDirectory, string $target): string
{
    $baseDirectory = rtrim(str_replace('\\', '/', realpath($baseDirectory) ?: $baseDirectory), '/');
    $target = str_replace('\\', '/', $target);

    if (strpos($target, $baseDirectory) === 0) {
        $result = ltrim(substr($target, strlen($baseDirectory)), '/');
        return $result;
    }

    return '';
}

function extensionOf(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function mediaTypeFromExtension(string $extension): string
{
    $image = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'];
    $audio = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'];
    $video = ['mp4', 'webm', 'mov', 'mkv', 'avi', 'm4v'];

    if (in_array($extension, $image, true)) {
        return 'image';
    }
    if (in_array($extension, $audio, true)) {
        return 'audio';
    }
    if (in_array($extension, $video, true)) {
        return 'video';
    }

    return 'other';
}

function listDirectoryItems(string $directory, string $baseDirectory, array $mediaExtensions): array
{
    $items = [];

    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) {
            continue;
        }

        $name = $entry->getFilename();
        $path = $entry->getPathname();

        if ($entry->isDir()) {
            $items[] = [
                'kind' => 'directory',
                'name' => $name,
                'relativePath' => relativeFromBase($baseDirectory, $path),
                'size' => 0,
                'modified' => $entry->getMTime(),
                'type' => 'directory',
            ];
            continue;
        }

        if (!$entry->isFile()) {
            continue;
        }

        $ext = extensionOf($name);
        if (!in_array($ext, $mediaExtensions, true)) {
            continue;
        }

        $items[] = [
            'kind' => 'file',
            'name' => $name,
            'relativePath' => relativeFromBase($baseDirectory, $path),
            'size' => $entry->getSize(),
            'modified' => $entry->getMTime(),
            'type' => mediaTypeFromExtension($ext),
            'ext' => $ext,
        ];
    }

    return $items;
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, $index === 0 ? 0 : 1, ',', '.') . ' ' . $units[$index];
}

function streamFile(string $path, bool $download): void
{
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');

    if ($download) {
        $filename = basename($path);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    }

    readfile($path);
    exit;
}

function createZipFromMedia(string $directory, array $items, string $archiveName): void
{
    $tmpZip = tempnam(sys_get_temp_dir(), 'media_zip_');
    if ($tmpZip === false) {
        throw new RuntimeException('Temporäre ZIP-Datei konnte nicht erstellt werden.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP-Datei konnte nicht geöffnet werden.');
    }

    foreach ($items as $item) {
        if (($item['kind'] ?? '') !== 'file') {
            continue;
        }
        $absolute = $directory . DIRECTORY_SEPARATOR . $item['name'];
        if (!is_file($absolute)) {
            continue;
        }
        $zip->addFile($absolute, $item['name']);
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Length: ' . (string) filesize($tmpZip));
    header('Content-Disposition: attachment; filename="' . rawurlencode($archiveName) . '"');
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

$authError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authAction = (string) ($_POST['auth_action'] ?? '');
    if ($authAction === 'login') {
        $password = (string) ($_POST['password'] ?? '');
        if (password_verify($password, $configuredPasswordHash)) {
            $_SESSION['is_authenticated'] = true;
            header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $authError = 'Passwort ist nicht korrekt.';
    }
    if ($authAction === 'logout') {
        session_unset();
        session_destroy();
        session_start();
    }
}

if (!isAuthenticated()) {
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login – PHP Media Datei-Manager</title>
        <style>
            body { font-family: Arial, sans-serif; background: #0f1320; color: #f5f7ff; margin: 0; display: grid; place-items: center; min-height: 100vh; }
            .box { width: min(450px, 92vw); background: #1a2133; border: 1px solid #344059; border-radius: 12px; padding: 18px; }
            h1 { margin: 0 0 8px; font-size: 1.2rem; }
            p { color: #b7bfd8; }
            input, button { width: 100%; background: #131a29; border: 1px solid #3a4763; color: #f5f7ff; border-radius: 8px; padding: 10px; margin-top: 8px; }
            .error { background: #441d1d; border: 1px solid #b15b5b; border-radius: 8px; padding: 10px; color: #ffd3d3; }
            .hint { font-size: .85rem; }
            code { background: #11192a; padding: 2px 4px; border-radius: 4px; }
        </style>
    </head>
    <body>
    <main class="box">
        <h1>🔒 Passwortschutz aktiv</h1>
        <p>Bitte Passwort eingeben, um den Datei-Manager und alle Funktionen zu nutzen.</p>
        <?php if ($authError !== ''): ?>
            <p class="error"><?= htmlspecialchars($authError, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="auth_action" value="login">
            <label for="password">Passwort</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
            <button type="submit">Einloggen</button>
        </form>
        <p class="hint">Standard ist <code>BitteAendern123!</code>. Setze für Produktion unbedingt <code>MEDIA_MANAGER_PASSWORD_HASH</code>.</p>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$currentRelative = sanitizeRelativePath((string) ($_GET['path'] ?? ''));
$currentDirectory = resolvePath($baseDirectory, $currentRelative);
if ($currentDirectory === null || !is_dir($currentDirectory)) {
    $currentRelative = '';
    $currentDirectory = $baseDirectory;
}

$action = (string) ($_GET['action'] ?? '');
$fileRelative = sanitizeRelativePath((string) ($_GET['file'] ?? ''));
$fileAbsolute = $fileRelative !== '' ? resolvePath($baseDirectory, $fileRelative) : null;

if (in_array($action, ['view', 'download'], true)) {
    if ($fileAbsolute !== null && is_file($fileAbsolute)) {
        streamFile($fileAbsolute, $action === 'download');
    }
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['post_action'] ?? '');

    if ($postAction === 'create_folder') {
        $newFolder = trim((string) ($_POST['folder_name'] ?? ''));
        if ($newFolder === '') {
            $error = 'Ordnername darf nicht leer sein.';
        } elseif (preg_match('/[\\\/:*?"<>|]/', $newFolder)) {
            $error = 'Ordnername enthält ungültige Zeichen.';
        } else {
            $target = $currentDirectory . DIRECTORY_SEPARATOR . $newFolder;
            if (is_dir($target)) {
                $error = 'Ordner existiert bereits.';
            } elseif (@mkdir($target, 0775)) {
                $message = 'Ordner wurde erstellt.';
            } else {
                $error = 'Ordner konnte nicht erstellt werden.';
            }
        }
    }

    if ($postAction === 'upload_file' && isset($_FILES['upload_media'])) {
        $upload = $_FILES['upload_media'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Upload fehlgeschlagen.';
        } else {
            $filename = basename((string) $upload['name']);
            $ext = extensionOf($filename);
            if (!in_array($ext, $mediaExtensions, true)) {
                $error = 'Nur Medien-Dateien sind erlaubt.';
            } else {
                $target = $currentDirectory . DIRECTORY_SEPARATOR . $filename;
                if (move_uploaded_file((string) $upload['tmp_name'], $target)) {
                    $message = 'Datei erfolgreich hochgeladen.';
                } else {
                    $error = 'Datei konnte nicht gespeichert werden.';
                }
            }
        }
    }

    if ($postAction === 'delete_file') {
        $deleteRelative = sanitizeRelativePath((string) ($_POST['delete_file'] ?? ''));
        $deleteAbsolute = resolvePath($baseDirectory, $deleteRelative);
        if ($deleteAbsolute === null || !is_file($deleteAbsolute)) {
            $error = 'Datei zum Löschen nicht gefunden.';
        } elseif (@unlink($deleteAbsolute)) {
            $message = 'Datei wurde gelöscht.';
        } else {
            $error = 'Datei konnte nicht gelöscht werden.';
        }
    }
}

$search = mb_strtolower(trim((string) ($_GET['search'] ?? '')));
$typeFilter = (string) ($_GET['type'] ?? 'all');
$sortBy = (string) ($_GET['sort'] ?? 'name');
$sortOrder = (string) ($_GET['order'] ?? 'asc');

$items = listDirectoryItems($currentDirectory, $baseDirectory, $mediaExtensions);

$items = array_values(array_filter($items, static function (array $item) use ($search, $typeFilter): bool {
    if (($item['kind'] ?? '') === 'directory') {
        return $search === '' || mb_stripos((string) $item['name'], $search) !== false;
    }

    if ($typeFilter !== 'all' && ($item['type'] ?? '') !== $typeFilter) {
        return false;
    }

    if ($search !== '' && mb_stripos((string) $item['name'], $search) === false) {
        return false;
    }

    return true;
}));

usort($items, static function (array $a, array $b) use ($sortBy, $sortOrder): int {
    if ($a['kind'] !== $b['kind']) {
        return $a['kind'] === 'directory' ? -1 : 1;
    }

    $factor = $sortOrder === 'desc' ? -1 : 1;

    if ($sortBy === 'size') {
        return (($a['size'] <=> $b['size']) ?: strcasecmp((string) $a['name'], (string) $b['name'])) * $factor;
    }

    if ($sortBy === 'modified') {
        return (($a['modified'] <=> $b['modified']) ?: strcasecmp((string) $a['name'], (string) $b['name'])) * $factor;
    }

    return strcasecmp((string) $a['name'], (string) $b['name']) * $factor;
});

if ($action === 'zip') {
    $zipItems = array_values(array_filter($items, static fn(array $item): bool => ($item['kind'] ?? '') === 'file'));
    if ($zipItems !== []) {
        $archiveName = 'media_' . date('Ymd_His') . '.zip';
        createZipFromMedia($currentDirectory, $zipItems, $archiveName);
    }
}

$currentPreview = sanitizeRelativePath((string) ($_GET['preview'] ?? ''));
$previewAbsolute = $currentPreview !== '' ? resolvePath($baseDirectory, $currentPreview) : null;
$previewExists = $previewAbsolute !== null && is_file($previewAbsolute);
$previewType = $previewExists ? mediaTypeFromExtension(extensionOf((string) basename($previewAbsolute))) : 'other';

function queryWithout(array $removeKeys): string
{
    $query = $_GET;
    foreach ($removeKeys as $key) {
        unset($query[$key]);
    }

    return http_build_query($query);
}

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Media Datei-Manager</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #0f1320; color: #f5f7ff; }
        .container { max-width: 1280px; margin: auto; padding: 16px; }
        h1 { margin: 0 0 8px; }
        .muted { color: #b7bfd8; }
        .grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 16px; }
        .card { background: #1a2133; border: 1px solid #344059; border-radius: 12px; overflow: hidden; }
        .card h2 { margin: 0; font-size: 1rem; padding: 12px 14px; background: #232c43; border-bottom: 1px solid #344059; }
        .card-body { padding: 12px; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        input, select, button { background: #131a29; border: 1px solid #3a4763; color: #f5f7ff; border-radius: 8px; padding: 8px 10px; }
        button, .btn { text-decoration: none; display: inline-block; cursor: pointer; }
        .btn { background: #2d3b5b; border: 1px solid #495d89; color: #fff; border-radius: 8px; padding: 8px 10px; }
        .btn.danger { background: #5a2d2d; border-color: #a35252; }
        .message { background: #17381d; border: 1px solid #2b8544; padding: 10px; border-radius: 8px; margin: 0 0 10px; }
        .error { background: #441d1d; border: 1px solid #b15b5b; padding: 10px; border-radius: 8px; margin: 0 0 10px; }
        .breadcrumbs { margin: 8px 0 12px; font-size: .9rem; }
        .list { max-height: 66vh; overflow: auto; display: flex; flex-direction: column; gap: 8px; }
        .item { border: 1px solid #35415c; border-radius: 10px; padding: 10px; display: grid; grid-template-columns: 1fr auto; gap: 8px; }
        .meta { color: #b7bfd8; font-size: .85rem; }
        .actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .preview { min-height: 280px; border: 1px dashed #415172; border-radius: 10px; display: grid; place-items: center; background: #131a2b; padding: 8px; }
        .preview img, .preview video { max-width: 100%; max-height: 60vh; }
        audio, video { width: 100%; }
        form.inline { display: inline; }
        .small { font-size: .82rem; }
        @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">
    <h1>PHP Index: Datei-System für Bilder, Musik & Videos</h1>
    <p class="muted">Wichtige Funktionen: Ordnernavigation, Suche/Filter/Sortierung, Vorschau/Playback, Download, ZIP-Export, Upload, Ordner erstellen und Datei löschen.</p>
    <form method="post" class="inline" style="margin: 0 0 12px;">
        <input type="hidden" name="auth_action" value="logout">
        <button type="submit">🔓 Abmelden</button>
    </form>

    <?php if ($message !== ''): ?>
        <p class="message"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="grid">
        <section class="card">
            <h2>Datei-Browser</h2>
            <div class="card-body">
                <form method="get" class="toolbar">
                    <input type="hidden" name="path" value="<?= htmlspecialchars($currentRelative, ENT_QUOTES) ?>">
                    <input type="text" name="search" placeholder="Datei suchen" value="<?= htmlspecialchars($_GET['search'] ?? '', ENT_QUOTES) ?>">
                    <select name="type">
                        <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>Alle Medien</option>
                        <option value="image" <?= $typeFilter === 'image' ? 'selected' : '' ?>>Bilder</option>
                        <option value="audio" <?= $typeFilter === 'audio' ? 'selected' : '' ?>>Audio</option>
                        <option value="video" <?= $typeFilter === 'video' ? 'selected' : '' ?>>Video</option>
                    </select>
                    <select name="sort">
                        <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Name</option>
                        <option value="size" <?= $sortBy === 'size' ? 'selected' : '' ?>>Größe</option>
                        <option value="modified" <?= $sortBy === 'modified' ? 'selected' : '' ?>>Geändert</option>
                    </select>
                    <select name="order">
                        <option value="asc" <?= $sortOrder === 'asc' ? 'selected' : '' ?>>Aufsteigend</option>
                        <option value="desc" <?= $sortOrder === 'desc' ? 'selected' : '' ?>>Absteigend</option>
                    </select>
                    <button type="submit">Filtern</button>
                    <a class="btn" href="?path=<?= urlencode($currentRelative) ?>&action=zip&<?= queryWithout(['action']) ?>">ZIP Download</a>
                </form>

                <div class="breadcrumbs">
                    <strong>Pfad:</strong>
                    <a class="btn small" href="?path=">/</a>
                    <?php
                    $segments = $currentRelative !== '' ? explode('/', $currentRelative) : [];
                    $progressive = '';
                    foreach ($segments as $segment):
                        $progressive = $progressive === '' ? $segment : $progressive . '/' . $segment;
                        ?>
                        / <a class="btn small" href="?path=<?= urlencode($progressive) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="toolbar">
                    <form method="post" class="inline">
                        <input type="hidden" name="post_action" value="create_folder">
                        <input type="text" name="folder_name" placeholder="Neuer Ordnername">
                        <button type="submit">Ordner erstellen</button>
                    </form>

                    <form method="post" enctype="multipart/form-data" class="inline">
                        <input type="hidden" name="post_action" value="upload_file">
                        <input type="file" name="upload_media" accept="image/*,audio/*,video/*">
                        <button type="submit">Datei hochladen</button>
                    </form>
                </div>

                <div class="list">
                    <?php if (count($items) === 0): ?>
                        <p class="muted">Keine passenden Inhalte im aktuellen Ordner.</p>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                        <article class="item">
                            <div>
                                <strong><?= htmlspecialchars($item['name'], ENT_QUOTES) ?></strong>
                                <div class="meta">
                                    <?= $item['kind'] === 'directory' ? 'Ordner' : strtoupper((string) $item['type']) ?>
                                    • <?= $item['kind'] === 'directory' ? '-' : formatBytes((int) $item['size']) ?>
                                    • <?= date('d.m.Y H:i', (int) $item['modified']) ?>
                                </div>
                                <div class="meta small"><?= htmlspecialchars((string) $item['relativePath'], ENT_QUOTES) ?></div>
                            </div>
                            <div class="actions">
                                <?php if ($item['kind'] === 'directory'): ?>
                                    <a class="btn" href="?path=<?= urlencode((string) $item['relativePath']) ?>">Öffnen</a>
                                <?php else: ?>
                                    <a class="btn" href="?path=<?= urlencode($currentRelative) ?>&preview=<?= urlencode((string) $item['relativePath']) ?>&<?= queryWithout(['preview']) ?>">Vorschau</a>
                                    <a class="btn" href="?action=view&file=<?= urlencode((string) $item['relativePath']) ?>" target="_blank">Direkt öffnen</a>
                                    <a class="btn" href="?action=download&file=<?= urlencode((string) $item['relativePath']) ?>">Download</a>
                                    <form method="post" class="inline" onsubmit="return confirm('Datei wirklich löschen?');">
                                        <input type="hidden" name="post_action" value="delete_file">
                                        <input type="hidden" name="delete_file" value="<?= htmlspecialchars((string) $item['relativePath'], ENT_QUOTES) ?>">
                                        <button type="submit" class="danger">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="card">
            <h2>Vorschau / Wiedergabe</h2>
            <div class="card-body">
                <div class="preview">
                    <?php if ($previewExists && $previewType === 'image'): ?>
                        <img src="?action=view&file=<?= urlencode($currentPreview) ?>" alt="Preview">
                    <?php elseif ($previewExists && $previewType === 'audio'): ?>
                        <audio controls autoplay src="?action=view&file=<?= urlencode($currentPreview) ?>"></audio>
                    <?php elseif ($previewExists && $previewType === 'video'): ?>
                        <video controls autoplay src="?action=view&file=<?= urlencode($currentPreview) ?>"></video>
                    <?php else: ?>
                        <p class="muted">Datei für Vorschau auswählen.</p>
                    <?php endif; ?>
                </div>
                <p class="meta" style="margin-top:10px;">
                    <?php if ($previewExists): ?>
                        Aktuell: <strong><?= htmlspecialchars($currentPreview, ENT_QUOTES) ?></strong>
                    <?php else: ?>
                        Kein Medium ausgewählt.
                    <?php endif; ?>
                </p>
                <p class="muted small">
                    Sicherheit: Alle Pfade werden serverseitig normalisiert und auf das Projektverzeichnis begrenzt.
                </p>
            </div>
        </section>
    </div>
</div>
</body>
</html>
