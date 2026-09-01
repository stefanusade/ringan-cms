<?php
/**
 * Taksonomi (kategori/tag/kustom) per content type, ala WordPress.
 * Taksonomi = definisi (mis. "category"), term = instance (mis. "Berita").
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/validation.php';

function get_taxonomies(int $content_type_id): array
{
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT t.*, (SELECT COUNT(*) FROM terms WHERE taxonomy_id = t.id) AS term_count
         FROM taxonomies t
         WHERE t.content_type_id = ?
         ORDER BY t.created_at ASC, t.id ASC'
    );
    $stmt->execute([$content_type_id]);
    return $stmt->fetchAll();
}

function get_taxonomy(int $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM taxonomies WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_taxonomy_by_slug(int $content_type_id, string $slug): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM taxonomies WHERE content_type_id = ? AND slug = ? LIMIT 1');
    $stmt->execute([$content_type_id, $slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function taxonomy_slug_exists(int $content_type_id, string $slug, ?int $exclude_id = null): bool
{
    $db = get_db();
    $sql = 'SELECT COUNT(*) FROM taxonomies WHERE content_type_id = ? AND slug = ?';
    $params = [$content_type_id, $slug];
    if ($exclude_id !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exclude_id;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ((int) $stmt->fetchColumn()) > 0;
}

function create_taxonomy(int $content_type_id, string $slug, string $label, bool $is_hierarchical): int
{
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO taxonomies (content_type_id, slug, label, is_hierarchical) VALUES (?, ?, ?, ?)');
    $stmt->execute([$content_type_id, $slug, $label, $is_hierarchical ? 1 : 0]);
    return (int) $db->lastInsertId();
}

function update_taxonomy(int $id, string $slug, string $label, bool $is_hierarchical): void
{
    $db = get_db();
    $stmt = $db->prepare('UPDATE taxonomies SET slug = ?, label = ?, is_hierarchical = ? WHERE id = ?');
    $stmt->execute([$slug, $label, $is_hierarchical ? 1 : 0, $id]);
}

function delete_taxonomy(int $id): void
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM taxonomies WHERE id = ?');
    $stmt->execute([$id]);
}

/* ===== Terms ===== */

function get_terms(int $taxonomy_id): array
{
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT t.*, (SELECT COUNT(*) FROM entry_terms et WHERE et.term_id = t.id) AS entry_count
         FROM terms t WHERE t.taxonomy_id = ?
         ORDER BY t.parent_id ASC, t.name ASC, t.id ASC'
    );
    $stmt->execute([$taxonomy_id]);
    return $stmt->fetchAll();
}

function get_term(int $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM terms WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function term_slug_exists(int $taxonomy_id, string $slug, ?int $exclude_id = null): bool
{
    $db = get_db();
    $sql = 'SELECT COUNT(*) FROM terms WHERE taxonomy_id = ? AND slug = ?';
    $params = [$taxonomy_id, $slug];
    if ($exclude_id !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exclude_id;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ((int) $stmt->fetchColumn()) > 0;
}

function create_term(int $taxonomy_id, string $name, string $slug, ?int $parent_id = null): int
{
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO terms (taxonomy_id, name, slug, parent_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$taxonomy_id, $name, $slug, $parent_id]);
    return (int) $db->lastInsertId();
}

function update_term(int $id, string $name, string $slug, ?int $parent_id): void
{
    $db = get_db();
    $stmt = $db->prepare('UPDATE terms SET name = ?, slug = ?, parent_id = ? WHERE id = ?');
    $stmt->execute([$name, $slug, $parent_id, $id]);
}

function delete_term(int $id): void
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM terms WHERE id = ?');
    $stmt->execute([$id]);
}

function make_term_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');
    if ($slug === '' || !preg_match('/^[a-z0-9]/', $slug)) {
        $slug = 'term-' . $slug;
    }
    return substr($slug, 0, 150);
}

/* ===== Relasi entry <-> term ===== */

function set_entry_terms(int $entry_id, array $term_ids): void
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM entry_terms WHERE entry_id = ?');
    $stmt->execute([$entry_id]);
    if ($term_ids === []) {
        return;
    }
    $term_ids = array_values(array_unique(array_map('intval', $term_ids)));
    $stmt = $db->prepare('INSERT INTO entry_terms (entry_id, term_id) VALUES (?, ?)');
    foreach ($term_ids as $tid) {
        $stmt->execute([$entry_id, $tid]);
    }
}

function get_entry_terms(int $entry_id): array
{
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT t.id AS term_id, t.slug AS term_slug, t.name AS term_name,
                tx.slug AS taxonomy_slug, tx.label AS taxonomy_label, tx.id AS taxonomy_id
         FROM entry_terms et
         JOIN terms t ON t.id = et.term_id
         JOIN taxonomies tx ON tx.id = t.taxonomy_id
         WHERE et.entry_id = ?
         ORDER BY tx.id ASC, t.name ASC'
    );
    $stmt->execute([$entry_id]);
    return $stmt->fetchAll();
}

function entry_terms_map(int $entry_id): array
{
    $map = [];
    foreach (get_entry_terms($entry_id) as $row) {
        $map[$row['taxonomy_slug']][] = $row['term_slug'];
    }
    return $map;
}

function get_taxonomies_with_terms(int $content_type_id): array
{
    $result = [];
    foreach (get_taxonomies($content_type_id) as $tax) {
        $tax['terms'] = get_terms((int) $tax['id']);
        $result[] = $tax;
    }
    return $result;
}

/**
 * Hanya kembalikan term-id yang BENAR-BENAR milik taksonomi tsb
 * (anti mass-assignment dari taksonomi lain).
 */
function resolve_term_ids(array $term_ids, int $taxonomy_id): array
{
    $term_ids = array_values(array_unique(array_map('intval', $term_ids)));
    if ($term_ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
    $stmt = get_db()->prepare("SELECT id FROM terms WHERE taxonomy_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$taxonomy_id], $term_ids));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function resolve_term_ids_by_slug(array $slugs, int $taxonomy_id): array
{
    $slugs = array_values(array_unique(array_map('trim', $slugs)));
    if ($slugs === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $stmt = get_db()->prepare("SELECT id FROM terms WHERE taxonomy_id = ? AND slug IN ($placeholders)");
    $stmt->execute(array_merge([$taxonomy_id], $slugs));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Kumpulkan term dari POST form entri:
 *  - $_POST['terms'][tax_id][] = id term yang dicentang
 *  - $_POST['new_terms'][tax_id] = "nama1, nama2" → dibuat otomatis
 * Mengembalikan [tax_id => [term_ids]] (semua id sudah divalidasi milik taksonomi).
 */
function collect_entry_terms_from_post(array $taxonomies, array $post): array
{
    $result = [];
    $posted_terms = is_array($post['terms'] ?? null) ? $post['terms'] : [];
    $posted_new = is_array($post['new_terms'] ?? null) ? $post['new_terms'] : [];
    foreach ($taxonomies as $tax) {
        $tid = (int) $tax['id'];
        $ids = isset($posted_terms[$tid]) && is_array($posted_terms[$tid])
            ? array_map('intval', $posted_terms[$tid])
            : [];
        $ids = resolve_term_ids($ids, $tid);
        $new_names = isset($posted_new[$tid]) ? array_map('trim', explode(',', (string) $posted_new[$tid])) : [];
        foreach ($new_names as $name) {
            if ($name === '') {
                continue;
            }
            $slug = make_term_slug($name);
            $base = $slug;
            $i = 2;
            while (term_slug_exists($tid, $slug)) {
                $slug = $base . '-' . $i;
                $i++;
            }
            $ids[] = create_term($tid, mb_substr($name, 0, 150), $slug);
        }
        $result[$tid] = array_values(array_unique($ids));
    }
    return $result;
}
