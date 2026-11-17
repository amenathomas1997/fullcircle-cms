<?php
/**
 * Scheduled pages: a page can carry an optional "publish_at" date and time
 * (the same "YYYY-MM-DDTHH:MM" form as a post's date). Until that moment a
 * published page behaves like a draft on the public site: only someone signed
 * in to the control panel (or holding a review link) can see it. Pages lists
 * it as "Scheduled".
 *
 * The field itself is on the page form (admin/index.php); this file holds the
 * shared rules so the public site and the control panel agree.
 */

declare(strict_types=1);

/** When a page is due to go live, as a timestamp, or 0 when it has no publish date. */
function page_publish_time(array $page): int
{
    $when = trim((string) ($page['publish_at'] ?? ''));
    return $when !== '' ? (strtotime($when) ?: 0) : 0;
}

/** True when a page is published and its publish date, if it has one, has passed. */
function page_is_live(array $page): bool
{
    return ($page['status'] ?? '') === 'published' && page_publish_time($page) <= time();
}

/** True when a page is published but waiting for its publish date. */
function page_is_scheduled(array $page): bool
{
    return ($page['status'] ?? '') === 'published' && page_publish_time($page) > time();
}

/** Any page or post: is it live on the website right now? */
function item_is_live(string $type, array $item): bool
{
    if ($type === 'posts') {
        return ($item['status'] ?? '') === 'published' && post_time($item) > 0 && post_time($item) <= time();
    }
    return page_is_live($item);
}

/** Posted "publish_at" value, cleaned: '' when empty, the first 16 characters when it looks right, null when it does not. */
function clean_publish_at(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = substr($value, 0, 16);
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) && strtotime($value) ? $value : null;
}
