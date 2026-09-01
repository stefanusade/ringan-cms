<?php
/**
 * Layout header admin — sidebar ala WordPress.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/content_types.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';

function render_errors(array $errors): string
{
    if ($errors === []) {
        return '';
    }
    $html = '<div class="alert alert-error"><ul>';
    foreach ($errors as $field => $msgs) {
        foreach ((array) $msgs as $msg) {
            $html .= '<li>' . e($msg) . '</li>';
        }
    }
    return $html . '</ul></div>';
}

function admin_icon(string $name): string
{
    $icons = [
        'dashboard' => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1v-9.5z"/>',
        'content-types' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'entries' => '<path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M14 2v4h4M9 13h6M9 17h4"/>',
        'users' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-3.9 3.6-6 8-6s8 2.1 8 6"/>',
        'api-keys' => '<circle cx="8" cy="15" r="4"/><path d="M11 12 20 3M16 7l3 3M13 10l2 2"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'updates' => '<path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16"/>',
    ];
    $body = $icons[$name] ?? $icons['entries'];
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

/**
 * Bangun menu sidebar; setiap content type (CPT) tampil sebagai submenu Entries.
 */
function admin_menu_items(array $user): array
{
    $ct_active = (int) ($_GET['content_type'] ?? 0);
    $entries_children = [];
    foreach (get_content_types() as $ct) {
        $entries_children[] = [
            'label' => $ct['label'],
            'url' => admin_url('entries?content_type=' . (int) $ct['id']),
            'create_url' => can_write_entries($user) ? admin_url('entries/create?content_type=' . (int) $ct['id']) : null,
            'active' => (int) $ct['id'] === $ct_active,
        ];
    }

    $items = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'url' => admin_url('')],
    ];
    if (can_manage_content_types($user)) {
        $items[] = ['key' => 'content-types', 'label' => 'Content Types', 'icon' => 'content-types', 'url' => admin_url('content-types')];
    }
    $items[] = ['key' => 'entries', 'label' => 'Entries', 'icon' => 'entries', 'url' => admin_url('entries'), 'children' => $entries_children];
    if (is_superadmin($user)) {
        $items[] = ['key' => 'users', 'label' => 'Users', 'icon' => 'users', 'url' => admin_url('users')];
        $items[] = ['key' => 'api-keys', 'label' => 'API Keys', 'icon' => 'api-keys', 'url' => admin_url('api-keys')];
        $items[] = ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'url' => admin_url('settings')];
        $items[] = ['key' => 'updates', 'label' => 'Updates', 'icon' => 'updates', 'url' => admin_url('updates')];
    }
    return $items;
}

function admin_header(string $title, string $active = ''): void
{
    $user = current_user();
    $items = admin_menu_items($user);
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — <?= e(get_setting('site_title', 'Ringan CMS')) ?></title>
<?php $_site_favicon = site_favicon_url(); ?>
<?php if ($_site_favicon !== ''): ?>
<link rel="icon" href="<?= e($_site_favicon) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(BASE_URL . '/admin/assets/css/admin.css') ?>">
</head>
<body>
<div class="layout">
  <div class="mobile-topbar">
    <button type="button" id="sidebar-toggle" class="icon-btn" aria-label="Buka menu" aria-expanded="false">☰</button>
    <a class="brand" href="<?= e(admin_url('')) ?>">Ringan <span>CMS</span></a>
  </div>
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(admin_url('')) ?>">Ringan <span>CMS</span></a>
    <nav class="sidebar-nav">
      <ul class="menu">
        <?php foreach ($items as $item): ?>
          <?php $has_children = !empty($item['children']); ?>
          <li class="menu-item<?= $item['key'] === $active ? ' active' : '' ?><?= $has_children ? ' has-children' : '' ?>">
            <a class="menu-link" href="<?= e($item['url']) ?>" title="<?= e($item['label']) ?>">
              <?= admin_icon($item['icon']) ?>
              <span><?= e($item['label']) ?></span>
            </a>
            <?php if ($has_children): ?>
              <ul class="submenu">
                <?php if ($item['children'] === []): ?>
                  <li class="submenu-empty">Belum ada content type</li>
                <?php else: ?>
                  <?php foreach ($item['children'] as $child): ?>
                    <li class="submenu-item<?= !empty($child['active']) ? ' active' : '' ?>">
                      <a class="submenu-link" href="<?= e($child['url']) ?>"><?= e($child['label']) ?></a>
                      <?php if (!empty($child['create_url'])): ?>
                        <a class="submenu-add" href="<?= e($child['create_url']) ?>" title="Tambah entri baru" aria-label="Tambah entri baru">+</a>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="sidebar-footer">
      <form method="post" action="<?= e(admin_url('logout')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="logout-btn" title="Logout"><?= admin_icon('logout') ?> <span>Logout</span></button>
      </form>
      <button type="button" id="sidebar-collapse" class="collapse-btn" title="Sembunyikan / tampilkan sidebar" aria-label="Sembunyikan sidebar">«</button>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
  <main class="main">
    <header class="content-header">
      <h1 class="page-title"><?= e($title) ?></h1>
      <?= render_flash_messages() ?>
    </header>
<?php
}
