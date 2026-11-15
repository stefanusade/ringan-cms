<?php
/**
 * Import ZIP dari plugin WordPress "Ringan Export".
 *
 * Alur: upload ZIP → validasi manifest + profil → ekstrak media ke
 * storage/tmp/import (di luar webroot) → import bertahap (batch).
 *
 * Keamanan:
 *  - Nama file ZIP di-whitelist (manifest.json + media/<hash>.<ext>).
 *  - Tidak memakai extractTo(); setiap file dibaca dengan batas ukuran.
 *  - Proteksi zip-slip, zip-bomb (rasio & total ukuran), dan path traversal.
 *  - Semua nilai entry tetap melewati validate_entry_payload(), sehingga
 *    whitelist field, validasi tipe, dan sanitasi XSS tetap berlaku.
 *  - Media disimpan lewat save_binary_upload() yang memverifikasi MIME asli.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/content_types.php';
require_once __DIR__ . '/content_entries.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/taxonomies.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/export_profile.php';
require_once __DIR__ . '/wordpress_import.php';

const ZIP_IMPORT_SESSION_KEY = 'ringan_zip_import';
const ZIP_IMPORT_BATCH_SIZE = 20;
const ZIP_IMPORT_MAX_ENTRIES = 5000;
const ZIP_IMPORT_MAX_FILES = 20000;
const ZIP_IMPORT_MANIFEST_MAX_BYTES = 16777216;
const ZIP_IMPORT_TIME_LIMIT = 20;
const ZIP_IMPORT_MEDIA_PER_ENTRY = 100;

/* =========================================================================
 * Batas & direktori kerja
 * ========================================================================= */

function zip_import_max_bytes(): int
{
    return max(1048576, (int) env('ZIP_IMPORT_MAX_BYTES', '268435456'));
}

function zip_import_max_total_bytes(): int
{
    return max(1048576, (int) env('ZIP_IMPORT_MAX_TOTAL_BYTES', '536870912'));
}

function zip_import_max_file_bytes(): int
{
    return max(1048576, min(MAX_UPLOAD_SIZE, (int) env('ZIP_IMPORT_MAX_FILE_BYTES', '10485760')));
}

function zip_import_root(): string
{
    $dir = APP_ROOT . '/storage/tmp/import/zip';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Gagal membuat direktori import ZIP.');
    }
    return $dir;
}

function zip_import_token_is_valid(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
}

function zip_import_token_path(string $token): string
{
    if (!zip_import_token_is_valid($token)) {
        return '';
    }
    return zip_import_root() . '/' . $token;
}

function zip_import_token_dir(string $token): string
{
    $dir = zip_import_token_path($token);
    if ($dir === '') {
        throw new RuntimeException('Token import tidak valid.');
    }
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Gagal membuat direktori kerja import.');
    }
    return $dir;
}

function zip_import_remove_dir(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    } catch (Throwable $e) {
        log_error('Gagal membersihkan direktori import ZIP: ' . $e->getMessage(), 'import');
    }
}

function zip_import_cleanup(int $max_age = 86400): void
{
    try {
        $root = zip_import_root();
    } catch (Throwable $e) {
        return;
    }
    $limit = time() - $max_age;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $mtime = @filemtime($dir);
        if ($mtime !== false && $mtime < $limit) {
            zip_import_remove_dir($dir);
        }
    }
}

function zip_import_destroy(string $token): void
{
    $dir = zip_import_token_path($token);
    if ($dir !== '') {
        zip_import_remove_dir($dir);
    }
    zip_import_state_clear();
}

/* =========================================================================
 * State sesi
 * ========================================================================= */

function zip_import_state_start(array $state): void
{
    start_session();
    $existing = $_SESSION[ZIP_IMPORT_SESSION_KEY] ?? null;
    if (is_array($existing) && isset($existing['token'], $state['token'])
        && $existing['token'] !== $state['token']) {
        zip_import_remove_dir(zip_import_token_path((string) $existing['token']));
    }
    $_SESSION[ZIP_IMPORT_SESSION_KEY] = $state;
}

function zip_import_state_get(): ?array
{
    start_session();
    $state = $_SESSION[ZIP_IMPORT_SESSION_KEY] ?? null;
    return is_array($state) && isset($state['token']) ? $state : null;
}

function zip_import_state_update(array $patch): void
{
    $state = zip_import_state_get();
    if ($state === null) {
        return;
    }
    $_SESSION[ZIP_IMPORT_SESSION_KEY] = array_merge($state, $patch);
}

function zip_import_state_clear(): void
{
    start_session();
    unset($_SESSION[ZIP_IMPORT_SESSION_KEY]);
}

function zip_import_state_matches(array $state, string $token): bool
{
    return isset($state['token']) && is_string($state['token'])
        && hash_equals($state['token'], $token);
}

/* =========================================================================
 * Validasi path & manifest
 * ========================================================================= */

function zip_import_media_path_is_safe(string $path): bool
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
        || str_starts_with($path, '/') || str_contains($path, '..')) {
        return false;
    }
    return (bool) preg_match(
        '#^media/[A-Za-z0-9._-]{1,150}\.(?:jpg|jpeg|png|gif|webp|ico|pdf|txt|csv|zip|doc|docx|xls|xlsx|ppt|pptx)$#i',
        $path
    );
}

/**
 * @return string|null path media relatif yang valid
 */
function zip_import_validate_media_ref(mixed $ref): ?string
{
    if (is_string($ref)) {
        $ref = trim($ref);
        return zip_import_media_path_is_safe($ref) ? $ref : null;
    }
    if (!is_array($ref)) {
        return null;
    }
    $file = trim((string) ($ref['file'] ?? ''));
    return zip_import_media_path_is_safe($file) ? $file : null;
}

function zip_import_content_media_refs(string $html): array
{
    if (!preg_match_all('/\{\{ringan_media:([^}]+)\}\}/', $html, $matches)) {
        return [];
    }
    $refs = [];
    foreach ($matches[1] as $ref) {
        $ref = trim($ref);
        if (zip_import_media_path_is_safe($ref)) {
            $refs[] = $ref;
        }
    }
    return array_values(array_unique($refs));
}

function zip_import_load_manifest(string $token): ?array
{
    $dir = zip_import_token_path($token);
    if ($dir === '') {
        return null;
    }
    $file = $dir . '/manifest.json';
    if (!is_file($file)) {
        return null;
    }
    $json = @file_get_contents($file);
    if ($json === false) {
        return null;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

/* =========================================================================
 * Upload & analisis ZIP
 * ========================================================================= */

/**
 * Validasi ZIP yang diunggah, validasi manifest/profil/entry, lalu ekstrak
 * media ke direktori kerja sementara.
 *
 * @return array{ok:true,token:string,ct_id:int,ct_slug:string,ct_label:string,profile:array,entries:int,media_count:int,media_bytes:int,warnings:string[],source_site:string,site_name:string}|array{ok:false,error:string}
 */
function zip_import_store_upload(array $file, int $user_id): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'Ekstensi PHP ZipArchive (ext-zip) belum aktif di server.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = match ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas server.',
            UPLOAD_ERR_PARTIAL => 'File hanya ter-upload sebagian.',
            UPLOAD_ERR_NO_FILE => 'Pilih file ZIP terlebih dahulu.',
            UPLOAD_ERR_NO_TMP_DIR => 'Direktori temporary server tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file di server.',
            UPLOAD_ERR_EXTENSION => 'Upload diblokir oleh ekstensi server.',
            default => 'Terjadi kesalahan saat upload.',
        };
        return ['ok' => false, 'error' => $error];
    }
    if (($file['size'] ?? 0) <= 0) {
        return ['ok' => false, 'error' => 'File kosong.'];
    }
    if (($file['size'] ?? 0) > zip_import_max_bytes()) {
        return ['ok' => false, 'error' => 'Ukuran ZIP maksimal ' . round(zip_import_max_bytes() / 1048576, 1) . ' MB.'];
    }
    $tmp_name = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp_name)) {
        return ['ok' => false, 'error' => 'File upload tidak valid.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp_name);
    if (!in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
        return ['ok' => false, 'error' => 'File harus berupa arsip ZIP.'];
    }
    $magic = @file_get_contents($tmp_name, false, null, 0, 4);
    if (!is_string($magic) || strncmp($magic, "PK\x03\x04", 4) !== 0) {
        return ['ok' => false, 'error' => 'File bukan arsip ZIP yang valid.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp_name) !== true) {
        return ['ok' => false, 'error' => 'Gagal membuka arsip ZIP.'];
    }

    try {
        if ($zip->numFiles <= 0) {
            throw new RuntimeException('Arsip ZIP kosong.');
        }
        if ($zip->numFiles > ZIP_IMPORT_MAX_FILES) {
            throw new RuntimeException('Arsip memuat terlalu banyak file (maks ' . ZIP_IMPORT_MAX_FILES . ').');
        }

        $manifest_index = $zip->locateName('manifest.json');
        if ($manifest_index === false) {
            throw new RuntimeException('manifest.json tidak ditemukan di root ZIP.');
        }
        $manifest_stat = $zip->statIndex($manifest_index);
        if (!is_array($manifest_stat) || (int) $manifest_stat['size'] > ZIP_IMPORT_MANIFEST_MAX_BYTES) {
            throw new RuntimeException('manifest.json terlalu besar.');
        }
        $manifest_raw = $zip->getFromIndex($manifest_index, ZIP_IMPORT_MANIFEST_MAX_BYTES + 1);
        if (!is_string($manifest_raw) || $manifest_raw === '') {
            throw new RuntimeException('Gagal membaca manifest.json.');
        }
        $manifest = json_decode($manifest_raw, true, 64);
        if (!is_array($manifest)) {
            throw new RuntimeException('manifest.json bukan JSON object yang valid.');
        }
        if ((string) ($manifest['format'] ?? '') !== 'ringan-export') {
            throw new RuntimeException('Format manifest bukan "ringan-export".');
        }
        if ((int) ($manifest['format_version'] ?? 0) !== 1) {
            throw new RuntimeException('Versi manifest tidak didukung.');
        }

        $profile_in = $manifest['profile'] ?? null;
        if (!is_array($profile_in)) {
            throw new RuntimeException('Manifest tidak memuat profil ekspor.');
        }
        $profile_slug = export_profile_slug($profile_in['content_type']['slug'] ?? '');
        if ($profile_slug === '') {
            throw new RuntimeException('Profil pada manifest tidak memiliki slug content type.');
        }
        $ct = get_content_type_by_slug($profile_slug);
        if ($ct === null) {
            throw new RuntimeException(
                'Content type "' . $profile_slug . '" belum ada di CMS. Buat content type dan field-nya terlebih dahulu.'
            );
        }
        $fields = get_fields((int) $ct['id']);
        $validated = export_profile_validate($profile_in, $ct, $fields);
        if (!$validated['ok']) {
            throw new RuntimeException(
                'Profil pada ZIP tidak cocok dengan content type: '
                . implode(' ', array_slice($validated['errors'], 0, 5))
            );
        }
        $profile = $validated['profile'];
        $profile_fields = [];
        foreach ($profile['fields'] as $profile_field) {
            $profile_fields[(string) $profile_field['key']] = $profile_field;
        }

        $entries = $manifest['entries'] ?? null;
        if (!is_array($entries) || $entries === []) {
            throw new RuntimeException('Manifest tidak memuat entry.');
        }
        if (count($entries) > ZIP_IMPORT_MAX_ENTRIES) {
            throw new RuntimeException('Terlalu banyak entry (maks ' . ZIP_IMPORT_MAX_ENTRIES . ').');
        }

        /* ---- media index ---- */
        $media_index = is_array($manifest['media'] ?? null) ? $manifest['media'] : [];
        $media_meta = [];
        $declared_total = 0;
        foreach ($media_index as $rel => $meta) {
            $rel = (string) $rel;
            if (!zip_import_media_path_is_safe($rel)) {
                throw new RuntimeException('Path media tidak valid di manifest: ' . $rel);
            }
            if (!is_array($meta)) {
                throw new RuntimeException('Metadata media tidak valid: ' . $rel);
            }
            $size = (int) ($meta['size'] ?? 0);
            if ($size < 0 || $size > zip_import_max_file_bytes()) {
                throw new RuntimeException('Ukuran media melebihi batas: ' . $rel);
            }
            $media_mime = strtolower(trim((string) ($meta['mime'] ?? '')));
            if ($media_mime === '') {
                throw new RuntimeException('MIME media kosong: ' . $rel);
            }
            $name = mb_substr(trim((string) ($meta['name'] ?? basename($rel))), 0, 255);
            if ($name === '') {
                $name = basename($rel);
            }
            $media_meta[$rel] = ['name' => $name, 'mime' => $media_mime, 'size' => $size];
            $declared_total += $size;
        }
        if ($declared_total > zip_import_max_total_bytes()) {
            throw new RuntimeException('Total ukuran media melebihi batas.');
        }

        /* ---- validasi entry & referensi media ---- */
        $used_media = [];
        $entry_errors = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $entry_errors[] = 'Entry #' . ($index + 1) . ' bukan object.';
                continue;
            }
            $source_id = trim((string) ($entry['source_id'] ?? ''));
            if ($source_id === '' || mb_strlen($source_id) > 100) {
                $entry_errors[] = 'Entry #' . ($index + 1) . ' memiliki source_id tidak valid.';
            }
            $entry_fields = $entry['fields'] ?? null;
            if (!is_array($entry_fields)) {
                $entry_errors[] = 'Entry "' . $source_id . '" tidak memiliki object fields.';
                continue;
            }
            foreach ($entry_fields as $key => $value) {
                $key = (string) $key;
                if (!isset($profile_fields[$key])) {
                    $entry_errors[] = 'Entry "' . $source_id . '" memuat field tidak dikenal: ' . $key;
                    continue;
                }
                $type = (string) $profile_fields[$key]['type'];
                if (in_array($type, ['image', 'file'], true)) {
                    $ref = zip_import_validate_media_ref($value);
                    if ($ref === null) {
                        $entry_errors[] = 'Field "' . $key . '" pada entry "' . $source_id . '" bukan referensi media yang valid.';
                    } else {
                        $used_media[$ref] = true;
                    }
                } elseif ($type === 'gallery') {
                    if (!is_array($value)) {
                        $entry_errors[] = 'Field galeri "' . $key . '" pada entry "' . $source_id . '" harus array.';
                        continue;
                    }
                    if (count($value) > ZIP_IMPORT_MEDIA_PER_ENTRY) {
                        $entry_errors[] = 'Field galeri "' . $key . '" melebihi ' . ZIP_IMPORT_MEDIA_PER_ENTRY . ' item.';
                        continue;
                    }
                    foreach ($value as $item) {
                        $ref = zip_import_validate_media_ref($item);
                        if ($ref === null) {
                            $entry_errors[] = 'Field galeri "' . $key . '" memuat referensi media tidak valid.';
                        } else {
                            $used_media[$ref] = true;
                        }
                    }
                } elseif ($type === 'richtext') {
                    if (!is_string($value)) {
                        $entry_errors[] = 'Field richtext "' . $key . '" pada entry "' . $source_id . '" harus string.';
                        continue;
                    }
                    if (preg_match_all('/\{\{ringan_media:([^}]+)\}\}/', $value, $matches)) {
                        foreach ($matches[1] as $ref) {
                            $ref = trim($ref);
                            if (!zip_import_media_path_is_safe($ref)) {
                                $entry_errors[] = 'Field richtext "' . $key . '" memuat placeholder media tidak valid.';
                            } else {
                                $used_media[$ref] = true;
                            }
                        }
                    }
                } elseif ($type === 'relation') {
                    if (!is_array($value)) {
                        $entry_errors[] = 'Field relasi "' . $key . '" pada entry "' . $source_id . '" harus array/object.';
                    }
                }
                if (count($entry_errors) > 30) {
                    break 2;
                }
            }
        }
        if ($entry_errors !== []) {
            throw new RuntimeException(implode(' ', array_slice(array_values(array_unique($entry_errors)), 0, 10)));
        }
        foreach (array_keys($used_media) as $rel) {
            if (!isset($media_meta[$rel])) {
                throw new RuntimeException('Media yang dipakai entry tidak ada di indeks manifest: ' . $rel);
            }
        }

        /* ---- audit isi ZIP: hanya manifest + media terdaftar ---- */
        $allowed = ['manifest.json' => true];
        foreach ($media_meta as $rel => $meta) {
            $allowed[$rel] = true;
        }
        $actual_total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') {
                throw new RuntimeException('Nama file ZIP tidak valid.');
            }
            $name = str_replace('\\', '/', $name);
            if (str_ends_with($name, '/')) {
                throw new RuntimeException('ZIP tidak boleh memuat direktori eksplisit: ' . $name);
            }
            if (!isset($allowed[$name])) {
                throw new RuntimeException('ZIP memuat file yang tidak terdaftar di manifest: ' . $name);
            }
            if ($name === 'manifest.json') {
                continue;
            }
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                throw new RuntimeException('Metadata file ZIP tidak terbaca: ' . $name);
            }
            $size = (int) $stat['size'];
            $compressed = (int) ($stat['comp_size'] ?? 0);
            if ($size > zip_import_max_file_bytes()) {
                throw new RuntimeException('File di ZIP melebihi batas ukuran: ' . $name);
            }
            if ($compressed > 0 && $size > 1048576 && ($size / $compressed) > 200) {
                throw new RuntimeException('Rasio kompresi mencurigakan pada file: ' . $name);
            }
            $actual_total += $size;
        }
        if ($actual_total > zip_import_max_total_bytes()) {
            throw new RuntimeException('Total isi ZIP melebihi batas ukuran.');
        }

        /* ---- ekstrak media ke direktori kerja ---- */
        $token = bin2hex(random_bytes(16));
        $dir = zip_import_token_dir($token);
        $warnings = [];
        try {
            foreach ($media_meta as $rel => $meta) {
                $index = $zip->locateName($rel);
                if ($index === false) {
                    throw new RuntimeException('File media tidak ditemukan di ZIP: ' . $rel);
                }
                $stat = $zip->statIndex($index);
                $size = is_array($stat) ? (int) $stat['size'] : 0;
                if ($size > zip_import_max_file_bytes()) {
                    throw new RuntimeException('File media melebihi batas: ' . $rel);
                }
                $data = $zip->getFromIndex($index, zip_import_max_file_bytes() + 1);
                if (!is_string($data) || $data === '' || strlen($data) > zip_import_max_file_bytes()) {
                    throw new RuntimeException('Gagal membaca media dari ZIP: ' . $rel);
                }
                $target = $dir . '/' . $rel;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
                    throw new RuntimeException('Gagal membuat direktori media.');
                }
                if (file_put_contents($target, $data, LOCK_EX) === false) {
                    throw new RuntimeException('Gagal menulis media: ' . $rel);
                }
                @chmod($target, 0640);
                $real_size = (int) filesize($target);
                if ($meta['size'] > 0 && $real_size !== $meta['size']) {
                    $warnings[] = sprintf('Ukuran media "%s" tidak cocok dengan manifest.', $meta['name']);
                }
                $file_mime = (new finfo(FILEINFO_MIME_TYPE))->file($target);
                if (!is_string($file_mime) || $file_mime === '') {
                    throw new RuntimeException('Tidak dapat memverifikasi tipe media: ' . $rel);
                }
                if (strtolower($file_mime) !== $meta['mime']) {
                    $warnings[] = sprintf(
                        'MIME media "%s" (%s) tidak cocok dengan manifest (%s); file akan dilewati saat import.',
                        $meta['name'],
                        $file_mime,
                        $meta['mime']
                    );
                } elseif (!isset(UPLOAD_MIME_MAP[$file_mime])) {
                    $warnings[] = sprintf(
                        'Tipe media "%s" (%s) tidak diizinkan CMS; field akan dilewati.',
                        $meta['name'],
                        $file_mime
                    );
                }
            }

            $normalized_manifest = $manifest;
            $normalized_manifest['profile'] = $profile;
            $normalized_manifest['media'] = $media_meta;
            $json = json_encode(
                $normalized_manifest,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($json === false || file_put_contents($dir . '/manifest.json', $json, LOCK_EX) === false) {
                throw new RuntimeException('Gagal menyimpan manifest hasil validasi.');
            }
            @chmod($dir . '/manifest.json', 0640);
        } catch (Throwable $e) {
            zip_import_remove_dir($dir);
            throw $e;
        }

        $source = is_array($manifest['source'] ?? null) ? $manifest['source'] : [];
        $zip->close();
        return [
            'ok' => true,
            'token' => $token,
            'ct_id' => (int) $ct['id'],
            'ct_slug' => (string) $ct['slug'],
            'ct_label' => (string) $ct['label'],
            'profile' => $profile,
            'entries' => count($entries),
            'media_count' => count($media_meta),
            'media_bytes' => $declared_total,
            'warnings' => array_values(array_unique($warnings)),
            'source_site' => (string) ($source['site_url'] ?? ''),
            'site_name' => (string) ($source['site_name'] ?? ''),
        ];
    } catch (RuntimeException $e) {
        // Pesan RuntimeException dibuat sendiri (aman ditampilkan ke user).
        $zip->close();
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        // Jangan bocorkan detail internal (query/path) ke response.
        log_error('Import ZIP gagal: ' . $e->getMessage(), 'import');
        $zip->close();
        return ['ok' => false, 'error' => 'Terjadi kesalahan internal saat memproses ZIP. Periksa log aplikasi.'];
    }
    // Tidak tercapai — jaga-jaga.
    $zip->close();
    return ['ok' => false, 'error' => 'Import ZIP gagal.'];
}

/* =========================================================================
 * Ringkasan manifest
 * ========================================================================= */

function zip_import_summary(array $manifest): array
{
    $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
    $media = is_array($manifest['media'] ?? null) ? $manifest['media'] : [];
    $bytes = 0;
    foreach ($media as $meta) {
        $bytes += (int) (is_array($meta) ? ($meta['size'] ?? 0) : 0);
    }
    $statuses = [];
    foreach ($entries as $entry) {
        $status = (string) (is_array($entry) ? ($entry['status'] ?? 'draft') : 'draft');
        $statuses[$status] = ($statuses[$status] ?? 0) + 1;
    }
    $source = is_array($manifest['source'] ?? null) ? $manifest['source'] : [];
    return [
        'entries' => count($entries),
        'media_count' => count($media),
        'media_bytes' => $bytes,
        'statuses' => $statuses,
        'site_url' => (string) ($source['site_url'] ?? ''),
        'site_name' => (string) ($source['site_name'] ?? ''),
        'post_type' => (string) ($source['post_type'] ?? ''),
        'exported_at' => (string) ($manifest['exported_at'] ?? ''),
    ];
}

/* =========================================================================
 * Eksekusi import (batch)
 * ========================================================================= */

/**
 * @return array{results:array,total:int,offset:int,finished:bool}
 */
function zip_import_run_batch(array &$state, array $fields, array $user, int $limit = ZIP_IMPORT_BATCH_SIZE): array
{
    $token = (string) ($state['token'] ?? '');
    $manifest = zip_import_load_manifest($token);
    if ($manifest === null) {
        throw new RuntimeException('Data import ZIP tidak ditemukan atau sudah kedaluwarsa. Ulangi upload.');
    }

    $ct_id = (int) $state['ct_id'];
    $profile = is_array($state['profile'] ?? null) ? $state['profile'] : [];
    $options = is_array($state['options'] ?? null) ? $state['options'] : [];
    $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
    $media_index = is_array($manifest['media'] ?? null) ? $manifest['media'] : [];
    $total = count($entries);
    $offset = max(0, (int) ($state['offset'] ?? 0));
    $slice = array_slice($entries, $offset, max(1, $limit));

    $decorated = [];
    $fields_by_key = [];
    foreach ($fields as $field) {
        $field['options'] = decode_options($field['options_json'] ?? null);
        $decorated[] = $field;
        $fields_by_key[(string) $field['field_key']] = $field;
    }

    $taxonomies = !empty($options['import_terms']) && can_manage_taxonomies($user)
        ? get_taxonomies($ct_id)
        : [];
    $can_create_terms = !empty($options['create_terms']) && can_manage_taxonomies($user);
    $site_key = wp_import_site_key((string) ($manifest['source']['site_url'] ?? ''));
    $media_cache = is_array($state['media_cache'] ?? null) ? $state['media_cache'] : [];

    $results = ['imported' => [], 'updated' => [], 'skipped' => [], 'failed' => [], 'downgraded' => []];
    $deadline = microtime(true) + ZIP_IMPORT_TIME_LIMIT;
    $processed = 0;

    foreach ($slice as $entry) {
        if (microtime(true) > $deadline) {
            break;
        }
        $processed++;
        $source_id = (string) (is_array($entry) ? ($entry['source_id'] ?? '') : '');
        $title = trim((string) (is_array($entry) ? ($entry['title'] ?? '') : ''));
        $label = $title !== '' ? $title : ('#' . $source_id);
        try {
            $status = zip_import_status_for((array) $entry, $profile, $options);
            if ($status === 'skip') {
                $results['skipped'][] = ['title' => $label, 'reason' => 'Status entry tidak diimport.'];
                continue;
            }

            $warnings = [];
            $data = zip_import_build_payload(
                (array) $entry,
                $profile,
                $fields_by_key,
                $media_index,
                $token,
                (int) $user['id'],
                $site_key,
                $media_cache,
                $warnings
            );

            $validation = validate_entry_payload($data, $decorated, false, $status !== 'draft');
            $downgraded = false;
            if (!$validation['ok'] && $status !== 'draft' && !empty($options['fallback_draft'])) {
                $validation = validate_entry_payload($data, $decorated, false, false);
                if ($validation['ok']) {
                    $status = 'draft';
                    $downgraded = true;
                    $warnings[] = 'Disimpan sebagai draft karena field wajib belum lengkap.';
                }
            }
            if (!$validation['ok']) {
                $results['failed'][] = [
                    'title' => $label,
                    'reason' => wp_import_flatten_errors($validation['errors']),
                ];
                continue;
            }
            $data = $validation['data'];

            $existing = $source_id !== '' ? wp_import_ref_find($site_key, $source_id, $ct_id) : null;
            $duplicates = (string) ($options['duplicates'] ?? 'skip');

            if ($existing !== null && $duplicates === 'skip') {
                $results['skipped'][] = ['title' => $label, 'reason' => 'Sudah pernah diimport (entry #' . $existing . ').'];
                continue;
            }

            if ($existing !== null && $duplicates === 'update'
                && get_entry_for_content_type($existing, $ct_id) !== null) {
                update_entry($existing, $data, $status);
                $entry_id = $existing;
                $results['updated'][] = ['title' => $label, 'id' => $entry_id];
            } else {
                $entry_id = create_entry($ct_id, $data, $status, (int) $user['id']);
                if ($source_id !== '') {
                    wp_import_ref_save($site_key, $source_id, $ct_id, $entry_id);
                }
                $results['imported'][] = ['title' => $label, 'id' => $entry_id];
            }

            if ($downgraded) {
                $results['downgraded'][] = ['title' => $label, 'id' => $entry_id];
            }

            if ($taxonomies !== []) {
                $term_warnings = [];
                $term_item = ['terms' => []];
                foreach ((array) (is_array($entry) ? ($entry['terms'] ?? []) : []) as $term) {
                    if (!is_array($term)) {
                        continue;
                    }
                    $term_item['terms'][] = [
                        'domain' => (string) ($term['domain'] ?? $term['taxonomy'] ?? ''),
                        'slug' => (string) ($term['slug'] ?? ''),
                        'name' => (string) ($term['name'] ?? ''),
                    ];
                }
                wp_import_apply_terms($entry_id, $term_item, $taxonomies, $can_create_terms, $term_warnings);
                foreach ($term_warnings as $warning) {
                    $warnings[] = $warning;
                }
            }

            $state['stats']['warnings'] = array_slice(array_merge(
                (array) ($state['stats']['warnings'] ?? []),
                array_map(static fn(string $w): string => $label . ': ' . $w, array_slice($warnings, 0, 3))
            ), 0, 100);
        } catch (Throwable $e) {
            log_error('Import ZIP gagal untuk "' . $label . '": ' . $e->getMessage(), 'import');
            $results['failed'][] = ['title' => $label, 'reason' => 'Kesalahan internal saat import.'];
        }
    }

    $state['offset'] = $offset + $processed;
    $state['media_cache'] = $media_cache;
    $state['stats']['imported'] = (int) ($state['stats']['imported'] ?? 0) + count($results['imported']);
    $state['stats']['updated'] = (int) ($state['stats']['updated'] ?? 0) + count($results['updated']);
    $state['stats']['skipped'] = (int) ($state['stats']['skipped'] ?? 0) + count($results['skipped']);
    $state['stats']['failed'] = (int) ($state['stats']['failed'] ?? 0) + count($results['failed']);
    $state['stats']['downgraded'] = (int) ($state['stats']['downgraded'] ?? 0) + count($results['downgraded']);

    foreach (array_slice($results['failed'], 0, 20) as $failure) {
        if (count($state['stats']['failures'] ?? []) < 200) {
            $state['stats']['failures'][] = $failure;
        }
    }

    $finished = $state['offset'] >= $total;
    $state['finished'] = $finished;

    return [
        'results' => $results,
        'total' => $total,
        'offset' => $state['offset'],
        'finished' => $finished,
    ];
}

function zip_import_status_for(array $entry, array $profile, array $options): string
{
    if (!empty($options['force_draft'])) {
        return 'draft';
    }
    $status = (string) ($entry['status'] ?? '');
    if (in_array($status, entry_statuses(), true)) {
        return $status;
    }
    $default = (string) ($profile['options']['default_status'] ?? 'draft');
    return in_array($default, entry_statuses(), true) ? $default : 'draft';
}

/**
 * Bangun payload data entry dari satu entry manifest.
 *
 * @return array<string,mixed>
 */
function zip_import_build_payload(
    array $entry,
    array $profile,
    array $fields_by_key,
    array $media_index,
    string $token,
    int $user_id,
    string $site_key,
    array &$media_cache,
    array &$warnings
): array {
    $data = [];
    $values = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];

    foreach ((array) ($profile['fields'] ?? []) as $profile_field) {
        $key = (string) ($profile_field['key'] ?? '');
        if ($key === '' || !isset($fields_by_key[$key]) || !array_key_exists($key, $values)) {
            continue;
        }
        $raw = $values[$key];
        if ($raw === null || $raw === '' || $raw === []) {
            continue;
        }
        $field = $fields_by_key[$key];
        $type = (string) ($field['field_type'] ?? 'text');
        $field_options = decode_options($field['options_json'] ?? null);

        switch ($type) {
            case 'image':
            case 'file':
                $path = zip_import_import_media_ref(
                    $raw,
                    $media_index,
                    $token,
                    $user_id,
                    $media_cache,
                    $warnings,
                    $type === 'image'
                );
                if ($path !== null) {
                    $data[$key] = $path;
                }
                break;

            case 'gallery':
                if (!is_array($raw)) {
                    break;
                }
                $max = (int) ($profile_field['options']['media_max'] ?? 20);
                $max = max(1, min(ZIP_IMPORT_MEDIA_PER_ENTRY, $max));
                if (count($raw) > $max) {
                    $raw = array_slice($raw, 0, $max);
                    $warnings[] = sprintf('Galeri field "%s" dibatasi %d item.', $key, $max);
                }
                $paths = [];
                foreach ($raw as $ref) {
                    $path = zip_import_import_media_ref(
                        $ref,
                        $media_index,
                        $token,
                        $user_id,
                        $media_cache,
                        $warnings,
                        true
                    );
                    if ($path !== null) {
                        $paths[] = $path;
                    }
                }
                if ($paths !== []) {
                    $data[$key] = $paths;
                }
                break;

            case 'relation':
                $resolved = zip_import_resolve_relation(
                    $raw,
                    $field,
                    $field_options,
                    $site_key,
                    $warnings
                );
                if ($resolved !== null) {
                    $data[$key] = $resolved;
                }
                break;

            case 'richtext':
                if (!is_string($raw)) {
                    $warnings[] = sprintf('Field richtext "%s" bukan string dan dilewati.', $key);
                    break;
                }
                $html = zip_import_replace_media_tokens(
                    $raw,
                    $media_index,
                    $token,
                    $user_id,
                    $media_cache,
                    $warnings
                );
                if (trim($html) !== '') {
                    $data[$key] = $html;
                }
                break;

            default:
                $value = wp_import_coerce_value($field, $raw, $warnings);
                if ($value !== null) {
                    $data[$key] = $value;
                }
        }
    }

    return $data;
}

function zip_import_import_media_ref(
    mixed $ref,
    array $media_index,
    string $token,
    int $user_id,
    array &$media_cache,
    array &$warnings,
    bool $image_only
): ?string {
    $rel = zip_import_validate_media_ref($ref);
    if ($rel === null) {
        $warnings[] = 'Referensi media tidak valid.';
        return null;
    }
    if (array_key_exists($rel, $media_cache)) {
        return $media_cache[$rel] !== '' ? (string) $media_cache[$rel] : null;
    }
    if (!isset($media_index[$rel]) || !is_array($media_index[$rel])) {
        $warnings[] = 'Media tidak ada di indeks manifest: ' . $rel;
        $media_cache[$rel] = '';
        return null;
    }
    $dir = zip_import_token_path($token);
    $file = $dir !== '' ? $dir . '/' . $rel : '';
    if ($file === '' || !is_file($file)) {
        $warnings[] = 'File media tidak ditemukan: ' . $rel;
        $media_cache[$rel] = '';
        return null;
    }
    $binary = @file_get_contents($file);
    if ($binary === false || $binary === '') {
        $warnings[] = 'Gagal membaca media: ' . $rel;
        $media_cache[$rel] = '';
        return null;
    }
    $meta = $media_index[$rel];
    $mime = strtolower(trim((string) ($meta['mime'] ?? '')));
    $name = (string) ($meta['name'] ?? basename($rel));
    $saved = save_binary_upload(
        $binary,
        $image_only,
        $user_id,
        $name,
        $mime !== '' ? $mime : null
    );
    if (!$saved['ok']) {
        $warnings[] = 'Media dilewati: ' . $saved['error'];
        $media_cache[$rel] = '';
        return null;
    }
    if (count($media_cache) < 3000) {
        $media_cache[$rel] = $saved['path'];
    }
    return $saved['path'];
}

function zip_import_replace_media_tokens(
    string $html,
    array $media_index,
    string $token,
    int $user_id,
    array &$media_cache,
    array &$warnings
): string {
    $result = preg_replace_callback(
        '/\{\{ringan_media:([^}]+)\}\}/',
        static function (array $matches) use ($media_index, $token, $user_id, &$media_cache, &$warnings): string {
            $rel = trim($matches[1]);
            $path = zip_import_import_media_ref(
                ['file' => $rel],
                $media_index,
                $token,
                $user_id,
                $media_cache,
                $warnings,
                true
            );
            return $path === null ? '' : BASE_URL . '/files/' . ltrim($path, '/');
        },
        $html
    );
    return is_string($result) ? $result : $html;
}

function zip_import_resolve_relation(
    mixed $raw,
    array $field,
    array $field_options,
    string $site_key,
    array &$warnings
): mixed {
    $ids = [];
    if (is_array($raw) && isset($raw['__relation_source_ids']) && is_array($raw['__relation_source_ids'])) {
        $ids = $raw['__relation_source_ids'];
    } elseif (is_array($raw)) {
        $ids = $raw;
    } else {
        $ids = [$raw];
    }

    $target_slug = (string) ($field_options['relation_target'] ?? '');
    if ($target_slug === '') {
        $warnings[] = sprintf('Target relasi field "%s" belum diset di profil/field.', $field['field_key'] ?? '');
        return null;
    }
    $target = get_content_type_by_slug($target_slug);
    if ($target === null) {
        $warnings[] = 'Content type target relasi tidak ditemukan: ' . $target_slug;
        return null;
    }

    $resolved = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id === '') {
            continue;
        }
        $entry_id = wp_import_ref_find($site_key, $id, (int) $target['id']);
        if ($entry_id !== null) {
            $resolved[] = $entry_id;
        } else {
            $warnings[] = sprintf(
                'Relasi "%s" belum ada di CMS (target %s); nilai dilewati.',
                $id,
                $target_slug
            );
        }
    }
    $resolved = array_values(array_unique($resolved));
    if ($resolved === []) {
        return null;
    }
    return !empty($field_options['relation_single']) ? $resolved[0] : $resolved;
}
