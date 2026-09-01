<?php
/**
 * Pagination sederhana.
 */

declare(strict_types=1);

function render_pagination(string $base_url, int $page, int $total_pages, array $extra_query = []): string
{
    if ($total_pages <= 1) {
        return '';
    }
    $html = '<nav class="pagination">';
    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i === $page) {
            $html .= '<span class="page current">' . $i . '</span>';
        } else {
            $query = array_merge($extra_query, ['page' => $i]);
            $html .= '<a class="page" href="' . e($base_url . '?' . http_build_query($query)) . '">' . $i . '</a>';
        }
    }
    return $html . '</nav>';
}
