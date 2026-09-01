<?php
/**
 * Validasi & penanganan file upload yang aman.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/validation.php';

const UPLOAD_MIME_MAP = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/zip' => 'zip',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
];

const IMAGE_MIME_MAP = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/x-icon' => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
];

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas.',
        UPLOAD_ERR_PARTIAL => 'File hanya ter-upload sebagian.',
        UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload.',
        UPLOAD_ERR_NO_TMP_DIR => 'Direktori temporary server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file di server.',
        UPLOAD_ERR_EXTENSION => 'Upload diblokir oleh ekstensi server.',
        default => 'Terjadi kesalahan saat upload.',
    };
}

/**
 * @return array{ok:true,path:string,mime:string}|array{ok:false,error:string}
 */
function handle_upload(array $file, bool $image_only = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE))];
    }
    if (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
        return ['ok' => false, 'error' => 'Ukuran file maksimal ' . round(MAX_UPLOAD_SIZE / 1048576, 1) . ' MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'File upload tidak valid.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($mime === false) {
        return ['ok' => false, 'error' => 'Tidak dapat memverifikasi tipe file.'];
    }
    $map = $image_only ? IMAGE_MIME_MAP : UPLOAD_MIME_MAP;
    if (!isset($map[$mime])) {
        return ['ok' => false, 'error' => 'Tipe file tidak diizinkan.'];
    }
    $ext = $map[$mime];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $subdir = date('Y/m');
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Gagal membuat direktori penyimpanan.'];
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan file.'];
    }
    @chmod($dir . '/' . $name, 0640);
    return ['ok' => true, 'path' => $subdir . '/' . $name, 'mime' => $mime];
}

/**
 * Simpan upload berbasis data URI (base64) dari API.
 *
 * @return array{ok:true,path:string}|array{ok:false,error:string}
 */
function save_base64_upload(string $data_uri, bool $image_only = false): array
{
    if (!preg_match('#^data:([a-z0-9.+-]+/[a-z0-9.+-]+);base64,(.+)$#is', $data_uri, $m)) {
        return ['ok' => false, 'error' => 'Format data URI tidak valid.'];
    }
    $mime = strtolower($m[1]);
    $binary = base64_decode($m[2], true);
    if ($binary === false || $binary === '') {
        return ['ok' => false, 'error' => 'Data base64 tidak valid.'];
    }
    $map = $image_only ? IMAGE_MIME_MAP : UPLOAD_MIME_MAP;
    if (!isset($map[$mime])) {
        return ['ok' => false, 'error' => 'Tipe file tidak diizinkan.'];
    }
    if (strlen($binary) > MAX_UPLOAD_SIZE) {
        return ['ok' => false, 'error' => 'Ukuran file melebihi batas.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'rcm');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'Gagal membuat file temporary.'];
    }
    file_put_contents($tmp, $binary);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($tmp);
    @unlink($tmp);
    if ($real_mime !== $mime || !isset($map[$real_mime])) {
        return ['ok' => false, 'error' => 'Isi file tidak sesuai dengan tipe yang diklaim.'];
    }
    $ext = $map[$real_mime];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $subdir = date('Y/m');
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Gagal membuat direktori penyimpanan.'];
    }
    file_put_contents($dir . '/' . $name, $binary);
    @chmod($dir . '/' . $name, 0640);
    return ['ok' => true, 'path' => $subdir . '/' . $name];
}

/**
 * Cek path relatif upload aman & file-nya benar-benar ada.
 */
function valid_upload_path(string $relative_path): bool
{
    if ($relative_path === '' || str_contains($relative_path, '..') || str_starts_with($relative_path, '/')) {
        return false;
    }
    $full = realpath(UPLOAD_DIR . '/' . $relative_path);
    $base = realpath(UPLOAD_DIR);
    return $full !== false && $base !== false && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full);
}

function delete_upload(string $relative_path): void
{
    if ($relative_path === '' || !valid_upload_path($relative_path)) {
        return;
    }
    @unlink(UPLOAD_DIR . '/' . $relative_path);
}
