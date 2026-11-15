<?php
/**
 * Halaman admin plugin: pilih profil, pilih post type/status, jalankan ekspor.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const RINGAN_EXPORT_PAGE_SLUG = 'ringan-export';

function ringan_export_capability(): string
{
    return 'manage_options';
}

function ringan_export_draft_key(int $user_id): string
{
    return 'ringan_export_draft_' . $user_id;
}

/**
 * @return array{profile:array,raw:string,errors:string[],warnings:string[]}
 */
function ringan_export_draft_get(int $user_id): array
{
    $draft = get_transient(ringan_export_draft_key($user_id));
    if (!is_array($draft)) {
        return ['profile' => [], 'raw' => '', 'errors' => [], 'warnings' => []];
    }
    return [
        'profile' => is_array($draft['profile'] ?? null) ? $draft['profile'] : [],
        'raw' => (string) ($draft['raw'] ?? ''),
        'errors' => array_values((array) ($draft['errors'] ?? [])),
        'warnings' => array_values((array) ($draft['warnings'] ?? [])),
    ];
}

function ringan_export_draft_save(int $user_id, array $draft): void
{
    set_transient(ringan_export_draft_key($user_id), $draft, DAY_IN_SECONDS);
}

function ringan_export_draft_clear(int $user_id): void
{
    delete_transient(ringan_export_draft_key($user_id));
}

/* =========================================================================
 * Menu & aset
 * ========================================================================= */

function ringan_export_register_menu(): void
{
    add_management_page(
        __('Ringan Export', 'ringan-export'),
        __('Ringan Export', 'ringan-export'),
        ringan_export_capability(),
        RINGAN_EXPORT_PAGE_SLUG,
        'ringan_export_render_page'
    );
}

function ringan_export_enqueue_assets(string $hook): void
{
    if ($hook !== 'tools_page_' . RINGAN_EXPORT_PAGE_SLUG) {
        return;
    }
    wp_enqueue_style(
        'ringan-export-admin',
        RINGAN_EXPORT_URL . 'assets/admin.css',
        [],
        RINGAN_EXPORT_VERSION
    );
    wp_enqueue_script(
        'ringan-export-admin',
        RINGAN_EXPORT_URL . 'assets/admin.js',
        [],
        RINGAN_EXPORT_VERSION,
        true
    );
    wp_localize_script('ringan-export-admin', 'RinganExport', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ringan_export_ajax'),
        'batchSize' => RINGAN_EXPORT_BATCH_SIZE,
        'strings' => [
            'preparing' => __('Menyiapkan daftar post…', 'ringan-export'),
            'processing' => __('Memproses %1$d dari %2$d post…', 'ringan-export'),
            'finalizing' => __('Menyusun ZIP + media…', 'ringan-export'),
            'done' => __('Ekspor selesai. Unduh ZIP lalu unggah di Ringan CMS (Tools → Import ZIP).', 'ringan-export'),
            'failed' => __('Ekspor gagal.', 'ringan-export'),
            'stalled' => __('Ekspor berhenti karena tidak ada kemajuan. Coba kurangi jumlah post atau periksa log server.', 'ringan-export'),
            'confirmCancel' => __('Batalkan sesi ekspor dan hapus file sementara?', 'ringan-export'),
        ],
    ]);
}

/* =========================================================================
 * Handler form profil
 * ========================================================================= */

function ringan_export_handle_save_profile(): void
{
    if (!current_user_can(ringan_export_capability())) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    check_admin_referer('ringan_export_profile');

    $user_id = get_current_user_id();
    $raw = isset($_POST['profile_json']) ? (string) wp_unslash($_POST['profile_json']) : '';
    $result = ringan_export_profile_parse($raw);
    if (!$result['ok']) {
        ringan_export_draft_save($user_id, [
            'profile' => [],
            'raw' => $raw,
            'errors' => $result['errors'],
            'warnings' => [],
        ]);
        wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_invalid', ringan_export_admin_url()));
        exit;
    }

    ringan_export_draft_save($user_id, [
        'profile' => $result['profile'],
        'raw' => (string) wp_json_encode($result['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'errors' => [],
        'warnings' => [],
    ]);
    wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_saved', ringan_export_admin_url()));
    exit;
}

function ringan_export_handle_fetch_profile(): void
{
    if (!current_user_can(ringan_export_capability())) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    check_admin_referer('ringan_export_profile');

    $user_id = get_current_user_id();
    $url = isset($_POST['profile_url']) ? esc_url_raw(wp_unslash((string) $_POST['profile_url'])) : '';
    $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash((string) $_POST['api_key'])) : '';

    $result = ringan_export_profile_fetch($url, $api_key);
    if (!$result['ok']) {
        ringan_export_draft_save($user_id, [
            'profile' => [],
            'raw' => '',
            'errors' => $result['errors'],
            'warnings' => [],
        ]);
        wp_safe_redirect(add_query_arg('ringan_export_notice', 'fetch_failed', ringan_export_admin_url()));
        exit;
    }

    ringan_export_draft_save($user_id, [
        'profile' => $result['profile'],
        'raw' => (string) wp_json_encode($result['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'errors' => [],
        'warnings' => [],
    ]);
    wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_fetched', ringan_export_admin_url()));
    exit;
}

function ringan_export_admin_url(): string
{
    return admin_url('tools.php?page=' . RINGAN_EXPORT_PAGE_SLUG);
}

/* =========================================================================
 * AJAX ekspor
 * ========================================================================= */

function ringan_export_ajax_verify(): int
{
    check_ajax_referer('ringan_export_ajax', 'nonce');
    if (!current_user_can(ringan_export_capability())) {
        wp_send_json_error(['message' => __('Anda tidak memiliki akses.', 'ringan-export')], 403);
    }
    return get_current_user_id();
}

function ringan_export_ajax_prepare(): void
{
    $user_id = ringan_export_ajax_verify();

    $post_types = isset($_POST['post_types']) ? (array) wp_unslash($_POST['post_types']) : [];
    $post_types = array_values(array_filter(array_map('sanitize_key', array_map('strval', $post_types))));
    $statuses = isset($_POST['post_statuses']) ? (array) wp_unslash($_POST['post_statuses']) : [];
    $statuses = array_values(array_filter(array_map('sanitize_key', array_map('strval', $statuses))));

    $draft = ringan_export_draft_get($user_id);
    if ($draft['profile'] === []) {
        wp_send_json_error(['message' => __('Profil belum dimuat/valid. Tempel atau ambil profil dari CMS terlebih dahulu.', 'ringan-export')]);
    }
    $validated = ringan_export_profile_normalize($draft['profile']);
    if (!$validated['ok']) {
        wp_send_json_error(['message' => implode(' ', array_slice($validated['errors'], 0, 5))]);
    }

    $result = ringan_export_prepare($validated['profile'], $post_types, $statuses, $user_id);
    if (!$result['ok']) {
        wp_send_json_error(['message' => (string) ($result['error'] ?? 'Gagal menyiapkan ekspor.')]);
    }

    wp_send_json_success([
        'token' => (string) $result['token'],
        'total' => (int) $result['total'],
    ]);
}

function ringan_export_ajax_batch(): void
{
    $user_id = ringan_export_ajax_verify();
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
    $result = ringan_export_batch($token, $user_id);
    if (!$result['ok']) {
        wp_send_json_error(['message' => (string) ($result['error'] ?? 'Batch gagal.')]);
    }
    wp_send_json_success($result);
}

function ringan_export_ajax_finalize(): void
{
    $user_id = ringan_export_ajax_verify();
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
    $result = ringan_export_finalize($token, $user_id);
    if (!$result['ok']) {
        wp_send_json_error(['message' => (string) ($result['error'] ?? 'Finalisasi gagal.')]);
    }
    wp_send_json_success([
        'download_url' => (string) $result['download_url'],
        'size' => (int) $result['size'],
        'stats' => (array) $result['stats'],
        'size_label' => size_format((int) $result['size']),
    ]);
}

function ringan_export_ajax_cancel(): void
{
    $user_id = ringan_export_ajax_verify();
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
    ringan_export_cancel($token, $user_id);
    wp_send_json_success(['cancelled' => true]);
}

/* =========================================================================
 * Render halaman
 * ========================================================================= */

function ringan_export_render_page(): void
{
    if (!current_user_can(ringan_export_capability())) {
        return;
    }

    $user_id = get_current_user_id();
    $draft = ringan_export_draft_get($user_id);
    $profile = $draft['profile'];
    $notice = isset($_GET['ringan_export_notice']) ? sanitize_key((string) $_GET['ringan_export_notice']) : '';

    $notices = [
        'profile_saved' => [__('Profil berhasil dimuat dan divalidasi.', 'ringan-export'), 'success'],
        'profile_fetched' => [__('Profil berhasil diambil dari Ringan CMS.', 'ringan-export'), 'success'],
        'profile_invalid' => [__('Profil tidak valid. Periksa pesan error di bawah.', 'ringan-export'), 'error'],
        'fetch_failed' => [__('Gagal mengambil profil dari CMS.', 'ringan-export'), 'error'],
        'profile_cleared' => [__('Profil draft dikosongkan.', 'ringan-export'), 'success'],
    ];

    $post_types = [];
    foreach (get_post_types(['public' => true], 'objects') as $type) {
        if ($type->name === 'attachment') {
            continue;
        }
        $post_types[$type->name] = $type->labels->singular_name !== ''
            ? $type->labels->singular_name . ' (' . $type->name . ')'
            : $type->name;
    }

    $status_options = ['publish', 'draft', 'private', 'pending', 'future'];
    $profile_post_types = (array) ($profile['source']['post_types'] ?? []);
    $profile_statuses = (array) ($profile['source']['post_statuses'] ?? []);
    $acf_fields = ringan_export_acf_fields($profile_post_types !== [] ? $profile_post_types : array_keys($post_types));
    ?>
    <div class="wrap ringan-export">
        <h1><?php esc_html_e('Ringan Export', 'ringan-export'); ?></h1>
        <p class="description">
            <?php esc_html_e('Ekspor post/CPT beserta field ACF dan media ke satu file ZIP, lalu unggah ZIP tersebut di Ringan CMS melalui Tools → Import ZIP.', 'ringan-export'); ?>
        </p>

        <?php if ($notice !== '' && isset($notices[$notice])): ?>
            <div class="notice notice-<?php echo esc_attr($notices[$notice][1]); ?> is-dismissible">
                <p><?php echo esc_html($notices[$notice][0]); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!class_exists('ZipArchive')): ?>
            <div class="notice notice-error">
                <p><?php esc_html_e('Ekstensi PHP ZipArchive (ext-zip) tidak aktif. Ekspor tidak dapat dijalankan.', 'ringan-export'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!ringan_export_acf_active()): ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('Plugin ACF tidak terdeteksi. Sumber acf.* akan dibaca dari post meta mentah; field gambar/relasi mungkin perlu transform manual.', 'ringan-export'); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($draft['errors'] !== []): ?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e('Profil tidak valid:', 'ringan-export'); ?></strong></p>
                <ul class="ringan-export-errors">
                    <?php foreach (array_slice($draft['errors'], 0, 20) as $error): ?>
                        <li><?php echo esc_html((string) $error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="ringan-export-grid">
            <div class="card">
                <h2><?php esc_html_e('1. Profil dari Ringan CMS', 'ringan-export'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Di Ringan CMS buka Content Types → Profil, unduh/salin JSON-nya. Tempel di bawah atau ambil langsung dari endpoint /api/v1/{slug}/profile.', 'ringan-export'); ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ringan_export_fetch_profile">
                    <?php wp_nonce_field('ringan_export_profile'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="ringan-profile-url"><?php esc_html_e('URL profil (opsional)', 'ringan-export'); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" id="ringan-profile-url" name="profile_url"
                                       placeholder="https://cms.example.com/api/v1/artikel/profile">
                                <p class="description"><?php esc_html_e('Contoh: https://cms.example.com/api/v1/artikel/profile', 'ringan-export'); ?></p>
                                <input type="text" class="regular-text" name="api_key" autocomplete="off"
                                       placeholder="<?php esc_attr_e('X-API-Key (bila endpoint butuh autentikasi)', 'ringan-export'); ?>">
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button"><?php esc_html_e('Ambil Profil dari CMS', 'ringan-export'); ?></button>
                    </p>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ringan_export_save_profile">
                    <?php wp_nonce_field('ringan_export_profile'); ?>
                    <textarea name="profile_json" rows="14" class="large-text code" spellcheck="false"
                              placeholder="{ &quot;format&quot;: &quot;ringan-export-profile&quot;, ... }"><?php echo esc_textarea($draft['raw']); ?></textarea>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Simpan & Validasi Profil', 'ringan-export'); ?></button>
                        <?php if ($profile !== []): ?>
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ringan_export_clear_profile'), 'ringan_export_profile')); ?>">
                                <?php esc_html_e('Kosongkan', 'ringan-export'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2><?php esc_html_e('2. Ringkasan profil', 'ringan-export'); ?></h2>
                <?php if ($profile === []): ?>
                    <p class="description"><?php esc_html_e('Belum ada profil yang dimuat.', 'ringan-export'); ?></p>
                <?php else: ?>
                    <p>
                        <strong><?php echo esc_html((string) ($profile['content_type']['label'] ?? $profile['content_type']['slug'] ?? '')); ?></strong>
                        <code><?php echo esc_html((string) ($profile['content_type']['slug'] ?? '')); ?></code>
                    </p>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: post types, 2: statuses */
                            esc_html__('Post type: %1$s · Status: %2$s', 'ringan-export'),
                            esc_html(implode(', ', array_map('strval', $profile_post_types))),
                            esc_html(implode(', ', array_map('strval', $profile_statuses)))
                        );
                        ?>
                    </p>
                    <table class="widefat striped ringan-export-fields">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Field CMS', 'ringan-export'); ?></th>
                                <th><?php esc_html_e('Tipe', 'ringan-export'); ?></th>
                                <th><?php esc_html_e('Sumber WordPress', 'ringan-export'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ((array) ($profile['fields'] ?? []) as $field): ?>
                            <?php
                            $field_key = (string) ($field['key'] ?? '');
                            $source = (string) ($field['source'] ?? 'none');
                            $acf_name = str_starts_with($source, 'acf.') ? substr($source, 4) : '';
                            $acf_missing = $acf_name !== '' && $acf_fields !== [] && !isset($acf_fields[$acf_name]);
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($field_key); ?></code></td>
                                <td><?php echo esc_html((string) ($field['type'] ?? '')); ?></td>
                                <td>
                                    <code><?php echo esc_html($source); ?></code>
                                    <?php if ($acf_missing): ?>
                                        <span class="ringan-export-warn"><?php esc_html_e('field ACF tidak ditemukan', 'ringan-export'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($profile !== []): ?>
            <div class="card">
                <h2><?php esc_html_e('3. Jalankan ekspor', 'ringan-export'); ?></h2>
                <form id="ringan-export-form">
                    <fieldset>
                        <legend><?php esc_html_e('Post type', 'ringan-export'); ?></legend>
                        <?php foreach ($post_types as $type_name => $type_label): ?>
                            <label class="ringan-export-check">
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type_name); ?>"
                                    <?php checked(in_array($type_name, $profile_post_types, true)); ?>>
                                <?php echo esc_html($type_label); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <fieldset>
                        <legend><?php esc_html_e('Status post', 'ringan-export'); ?></legend>
                        <?php foreach ($status_options as $status): ?>
                            <label class="ringan-export-check">
                                <input type="checkbox" name="post_statuses[]" value="<?php echo esc_attr($status); ?>"
                                    <?php checked(in_array($status, $profile_statuses, true)); ?>>
                                <?php echo esc_html($status); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <p>
                        <button type="submit" class="button button-primary" id="ringan-export-start">
                            <?php esc_html_e('Mulai Ekspor', 'ringan-export'); ?>
                        </button>
                        <button type="button" class="button" id="ringan-export-cancel" hidden>
                            <?php esc_html_e('Batalkan', 'ringan-export'); ?>
                        </button>
                    </p>
                </form>

                <div id="ringan-export-progress" hidden>
                    <progress id="ringan-export-bar" max="1" value="0"></progress>
                    <p id="ringan-export-status" class="description"></p>
                    <div id="ringan-export-log" class="ringan-export-log" aria-live="polite"></div>
                    <p id="ringan-export-download" hidden>
                        <a class="button button-primary" id="ringan-export-link" href="#">
                            <?php esc_html_e('Unduh ZIP', 'ringan-export'); ?>
                        </a>
                        <span id="ringan-export-size" class="description"></span>
                    </p>
                </div>

                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: batch size */
                        esc_html__('Ekspor berjalan bertahap (%d post per permintaan) agar aman untuk situs besar. Jangan tutup halaman ini sampai selesai.', 'ringan-export'),
                        (int) RINGAN_EXPORT_BATCH_SIZE
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2><?php esc_html_e('Yang diekspor', 'ringan-export'); ?></h2>
            <ul class="ringan-export-list">
                <li><?php esc_html_e('Judul, konten, excerpt, slug, tanggal, status, author, dan featured image.', 'ringan-export'); ?></li>
                <li><?php esc_html_e('Field ACF sesuai sumber di profil (termasuk gambar, file, galeri, relasi, dan tanggal).', 'ringan-export'); ?></li>
                <li><?php esc_html_e('Media dari folder uploads (gambar/file yang dipakai field maupun konten) dengan validasi tipe & ukuran.', 'ringan-export'); ?></li>
                <li><?php esc_html_e('Kategori/tag yang dipetakan di profil; entri tanpa padanan taksonomi dilewati dengan peringatan.', 'ringan-export'); ?></li>
            </ul>
            <p class="description">
                <?php esc_html_e('Repeater/flexible content ACF belum dipetakan otomatis karena CMS belum memiliki tipe field tersebut.', 'ringan-export'); ?>
            </p>
        </div>
    </div>
    <?php
}

/**
 * Hapus profil draft (dipanggil dari tautan "Kosongkan").
 */
function ringan_export_handle_clear_profile(): void
{
    if (!current_user_can(ringan_export_capability())) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    check_admin_referer('ringan_export_profile');
    ringan_export_draft_clear(get_current_user_id());
    wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_cleared', ringan_export_admin_url()));
    exit;
}
add_action('admin_post_ringan_export_clear_profile', 'ringan_export_handle_clear_profile');