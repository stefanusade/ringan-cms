<?php
/**
 * Logika aksi massal entri (dipakai admin/entries/bulk.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content_entries.php';
require_once __DIR__ . '/audit_log.php';

const ENTRY_BULK_ACTIONS = ['trash', 'restore', 'delete', 'published', 'draft'];
const ENTRY_BULK_MAX = 500;

/**
 * Terapkan satu aksi massal ke daftar entry milik content type tertentu.
 *
 * @param int[] $ids
 * @param array $fields definisi field content type (untuk cek required saat publish)
 * @return array{done:int,skipped:int,notes:string[]}
 * @throws InvalidArgumentException|RuntimeException
 */
function bulk_entries_action(int $content_type_id, array $ids, string $action, array $fields, int $user_id): array
{
    if (!in_array($action, ENTRY_BULK_ACTIONS, true)) {
        throw new InvalidArgumentException('Aksi massal tidak valid.');
    }
    if (in_array($action, ['trash', 'restore'], true) && !entry_trash_supported()) {
        throw new RuntimeException('Fitur Trash belum aktif. Jalankan migrasi database/migrations/009_entry_trash.sql terlebih dahulu.');
    }

    $clean_ids = [];
    foreach ($ids as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $clean_ids[$id] = $id;
        }
    }
    $clean_ids = array_values(array_slice($clean_ids, 0, ENTRY_BULK_MAX, true));

    $result = ['done' => 0, 'skipped' => 0, 'notes' => [], 'changed' => []];
    if ($clean_ids === []) {
        return $result;
    }

    foreach ($clean_ids as $id) {
        $entry = get_entry_for_content_type($id, $content_type_id);
        if ($entry === null) {
            $result['skipped']++;
            continue;
        }
        $current = (string) $entry['status'];

        switch ($action) {
            case 'trash':
                if ($current === 'trash') {
                    $result['skipped']++;
                    break;
                }
                trash_entry($id);
                log_audit('trash_entry', 'content_entries', (string) $id, $user_id);
                $result['done']++;
                $result['changed'][] = $id;
                break;

            case 'restore':
                if ($current !== 'trash') {
                    $result['skipped']++;
                    break;
                }
                restore_entry($id);
                log_audit('restore_entry', 'content_entries', (string) $id, $user_id);
                $result['done']++;
                $result['changed'][] = $id;
                break;

            case 'delete':
                delete_entry($id);
                log_audit('delete_entry', 'content_entries', (string) $id, $user_id);
                $result['done']++;
                $result['changed'][] = $id;
                break;

            case 'published':
                if ($current === 'trash') {
                    $result['skipped']++;
                    $result['notes'][] = '#' . $id . ' masih di Trash; pulihkan dulu.';
                    break;
                }
                $missing = entry_missing_required_fields(decode_entry_data($entry), $fields);
                if ($missing !== []) {
                    $result['skipped']++;
                    $result['notes'][] = '#' . $id . ': ' . $missing[0];
                    break;
                }
                set_entry_status($id, 'published');
                log_audit('publish_entry', 'content_entries', (string) $id, $user_id);
                $result['done']++;
                $result['changed'][] = $id;
                break;

            case 'draft':
                if ($current === 'trash') {
                    $result['skipped']++;
                    $result['notes'][] = '#' . $id . ' masih di Trash; pulihkan dulu.';
                    break;
                }
                set_entry_status($id, 'draft');
                log_audit('draft_entry', 'content_entries', (string) $id, $user_id);
                $result['done']++;
                $result['changed'][] = $id;
                break;
        }
    }

    return $result;
}
