<?php
/**
 * ACL sederhana berbasis role.
 */

declare(strict_types=1);

function is_superadmin(array $user): bool
{
    return ($user['role'] ?? '') === 'superadmin';
}

function can_manage_users(array $user): bool
{
    return is_superadmin($user);
}

function can_manage_content_types(array $user): bool
{
    return is_superadmin($user);
}

function can_manage_api_keys(array $user): bool
{
    return is_superadmin($user);
}

function can_write_entries(array $user): bool
{
    return in_array($user['role'] ?? '', ['superadmin', 'editor'], true);
}

function can_view_entries(array $user): bool
{
    return in_array($user['role'] ?? '', ['superadmin', 'editor', 'viewer'], true);
}
