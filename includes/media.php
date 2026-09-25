<?php
/**
 * Pustaka media (media library): pelacakan file yang diunggah.
 *
 * Setiap file yang berhasil diunggah (via handle_upload()/save_base64_upload())
 * dicatat di tabel `media` agar bisa dikelola di menu Media. Fungsi di sini
 * TIDAK bergantung pada includes/upload.php (untuk menghindari dependensi
 * melingkar); penghapusan file fisik dilakukan oleh pemanggil via delete_upload().
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/validation.php';

/**
 * Catat (atau perbarui) sebuah file di pustaka media.
 */
function media_register(string $relative_path, string $original_name, string $mime, int $size, ?int $uploaded_by = null): void
{
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    if ($relative_path === '') {
        return;
    }
    $original_name = $original_name !== '' ? $original_name : basename($relative_path);
    $original_name = mb_substr($original_name, 0, 255);
    $mime = mb_substr($mime, 0, 100);
    $stmt = get_db()->prepare(
        'INSERT INTO media (path, original_name, mime, size, uploaded_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE original_name = VALUES(original_name),
                                 mime = VALUES(mime),
                                 size = VALUES(size),
                                 uploaded_by = VALUES(uploaded_by)'
    );
    $stmt->execute([$relative_path, $original_name, $mime, max(0, $size), $uploaded_by]);
}

function media_delete_by_path(string $relative_path): void
{
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    if ($relative_path === '') {
        return;
    }
    $stmt = get_db()->prepare('DELETE FROM media WHERE path = ?');
    $stmt->execute([$relative_path]);
}

function media_path_exists(string $relative_path): bool
{
    $stmt = get_db()->prepare('SELECT COUNT(*) FROM media WHERE path = ?');
    $stmt->execute([ltrim(str_replace('\\', '/', $relative_path), '/')]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * Daftarkan file yang sudah ada di storage/uploads namun belum tercatat di
 * pustaka media (mis. unggahan sebelum fitur ini ada). Tidak menimpa metadata
 * file yang sudah terdaftar.
 *
 * @return int jumlah file baru yang didaftarkan
 */
function media_sync_from_disk(): int
{
    if (!defined('UPLOAD_DIR') || !is_dir(UPLOAD_DIR)) {
        return 0;
    }
    $base = realpath(UPLOAD_DIR);
    if ($base === false) {
        return 0;
    }
    $count = 0;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $full = str_replace('\\', '/', $file->getPathname());
        $rel = ltrim(substr($full, strlen($base)), '/');
        if ($rel === '') {
            continue;
        }
        $name = basename($rel);
        if (str_starts_with($name, '.') || $name === 'index.html') {
            continue;
        }
        if (media_path_exists($rel)) {
            continue;
        }
        $mime = $finfo->file((string) $file->getPathname());
        media_register($rel, $name, is_string($mime) ? $mime : 'application/octet-stream', (int) $file->getSize(), null);
        $count++;
    }
    return $count;
}

function get_media_item(int $id): ?array
{
    $stmt = get_db()->prepare(
        'SELECT m.*, u.username AS uploader
         FROM media m LEFT JOIN users u ON u.id = m.uploaded_by
         WHERE m.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function count_media(string $q = ''): int
{
    if ($q === '') {
        return (int) get_db()->query('SELECT COUNT(*) FROM media')->fetchColumn();
    }
    $stmt = get_db()->prepare('SELECT COUNT(*) FROM media WHERE original_name LIKE ? OR path LIKE ?');
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return array{items:array,total:int,page:int,per_page:int,total_pages:int}
 */
function get_media_items(int $page = 1, int $per_page = 24, string $q = ''): array
{
    $per_page = max(1, min(100, $per_page));
    $page = max(1, $page);
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE m.original_name LIKE ? OR m.path LIKE ?';
        $like = '%' . $q . '%';
        $params = [$like, $like];
    }
    $total = count_media($q);
    $offset = ($page - 1) * $per_page;
    $stmt = get_db()->prepare(
        "SELECT m.*, u.username AS uploader
         FROM media m LEFT JOIN users u ON u.id = m.uploaded_by
         $where
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT $per_page OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'items' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total > 0 ? (int) ceil($total / $per_page) : 1,
    ];
}

function media_is_image(array $item): bool
{
    return str_starts_with((string) ($item['mime'] ?? ''), 'image/');
}

function media_human_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}
