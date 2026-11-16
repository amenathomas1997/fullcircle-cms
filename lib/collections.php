<?php
/**
 * Videos (/videos/) and success stories (/success-stories/), edited in the
 * control panel, plus the archive of sent email updates (/updates/archive/).
 *
 * A video has a YouTube or Vimeo address and a full transcript; it plays in
 * the privacy-enhanced player. A success story is shown only when the
 * "permission to share" box is ticked as well as it being published.
 */

declare(strict_types=1);

const STORY_OUTCOMES = ['employment' => 'Employment', 'education' => 'Education', 'independence' => 'Independent living', 'technology' => 'Technology skills'];
const UPDATE_ARCHIVE_DIR = CONTENT_DIR . '/update-archive';

/** Turn a YouTube or Vimeo address into its embeddable player address, or null. */
function video_embed_url(string $url): ?string
{
    if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0';
    }
    if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1';
    }
    return null;
}

function published_items(string $type): array
{
    return array_values(array_filter(all_items($type), fn($i) => ($i['status'] ?? '') === 'published'
        && ($type !== 'stories' || !empty($i['consent']))));
}

function videos_index(): never
{
    $videos = published_items('videos');
    $out = '<h1>Videos</h1><p class="lede">Short how-to videos about assistive technology. Every video has captions and a full transcript.</p>';
    if (!$videos) {
        $out .= '<p>We’re recording our first videos. <a href="/updates/">Get updates by email</a> to hear when they’re ready, or browse our <a href="/resources/">cheat sheets</a> in the meantime.</p>';
    } else {
        $out .= '<ul class="resource-list">';
        foreach ($videos as $v) {
            $out .= '<li><article><h2><a href="/videos/' . h($v['slug']) . '/">' . h($v['title']) . '</a></h2><p>' . h($v['summary'] ?? '') . '</p></article></li>';
        }
        $out .= '</ul>';
    }
    send_html(render_layout('Videos', $out, 'How-to videos about screen readers and assistive technology, with captions and transcripts.'));
}

function video_page(string $slug): never
{
    $v = get_item('videos', $slug);
    if (!$v || (($v['status'] ?? '') !== 'published' && !can_preview())) {
        not_found();
    }
    $embed = video_embed_url((string) ($v['video_url'] ?? ''));
    $out = '<article><h1>' . h($v['title']) . '</h1><p class="lede">' . h($v['summary'] ?? '') . '</p>';
    if ($embed) {
        $out .= '<div class="video-frame"><iframe src="' . h($embed) . '" title="' . h('Video: ' . $v['title']) . '" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen loading="lazy"></iframe></div>';
    }
    $out .= '<p><a href="' . h($v['video_url'] ?? '') . '">Watch on ' . (str_contains((string) ($v['video_url'] ?? ''), 'vimeo') ? 'Vimeo' : 'YouTube') . '</a>'
        . (($v['described_url'] ?? '') !== '' ? ' · <a href="' . h($v['described_url']) . '">Version with audio description</a>' : '') . '</p>'
        . '<section aria-labelledby="transcript-heading"><h2 id="transcript-heading">Transcript</h2>' . ($v['transcript'] ?? '') . '</section></article>'
        . '<p><a href="/videos/">All videos</a></p>';
    send_html(render_layout((string) ($v['title'] ?? ''), $out, (string) ($v['summary'] ?? '')));
}

function stories_index(): never
{
    $stories = published_items('stories');
    $out = '<h1>Success Stories</h1><p class="lede">What people have achieved with the right technology and training.</p>';
    if (!$stories) {
        $out .= '<p>Stories are coming soon. Has training made a difference for you? <a href="' . h(contact_path()) . '">Tell us about it</a>.</p>';
    } else {
        foreach (STORY_OUTCOMES as $key => $label) {
            $group = array_filter($stories, fn($s) => ($s['outcome'] ?? '') === $key);
            if (!$group) {
                continue;
            }
            $out .= '<h2>' . h($label) . '</h2><ul class="resource-list">';
            foreach ($group as $s) {
                $out .= '<li><article><h3><a href="/success-stories/' . h($s['slug']) . '/">' . h($s['title']) . '</a></h3><p>' . h($s['summary'] ?? '') . '</p></article></li>';
            }
            $out .= '</ul>';
        }
    }
    send_html(render_layout('Success Stories', $out, 'Success stories from ' . site_name() . '.'));
}

function story_page(string $slug): never
{
    $s = get_item('stories', $slug);
    $visible = $s && ($s['status'] ?? '') === 'published' && !empty($s['consent']);
    if (!$s || (!$visible && !can_preview())) {
        not_found();
    }
    $out = '<article><h1>' . h($s['title']) . '</h1>'
        . '<ul class="post-meta"><li>' . h($s['person'] ?? '') . '</li><li>' . h(STORY_OUTCOMES[$s['outcome'] ?? ''] ?? '') . '</li></ul>'
        . '<div class="post-body">' . ($s['body'] ?? '') . '</div></article>'
        . '<p><a href="/success-stories/">All success stories</a></p>';
    send_html(render_layout((string) ($s['title'] ?? ''), $out, (string) ($s['summary'] ?? '')));
}

/* ---------- Archive of sent email updates ---------- */

function archive_update(string $subject, string $html): void
{
    $id = date('Y-m-d') . '-' . (slugify($subject) ?: 'update');
    $id = substr($id, 0, 90);
    for ($n = 2; is_file(UPDATE_ARCHIVE_DIR . "/$id.json"); $n++) {
        $id = preg_replace('/-\d+$/', '', $id) . "-$n";
    }
    write_json(UPDATE_ARCHIVE_DIR . "/$id.json", ['subject' => $subject, 'html' => $html, 'sent' => date('c')]);
}

function update_archive_list(): array
{
    $items = [];
    foreach (glob(UPDATE_ARCHIVE_DIR . '/*.json') ?: [] as $f) {
        if ($d = read_json($f)) {
            $d['id'] = basename($f, '.json');
            $items[] = $d;
        }
    }
    usort($items, fn($a, $b) => strcmp($b['sent'], $a['sent']));
    return $items;
}

function update_archive_index(): never
{
    $items = update_archive_list();
    $out = '<h1>Past Email Updates</h1><p class="lede">Every update we’ve sent, so you can see what you’ll get. <a href="/updates/">Sign up for updates</a>.</p>';
    if (!$items) {
        $out .= '<p>We haven’t sent an update yet. <a href="/updates/">Sign up</a> to get the first one.</p>';
    } else {
        $out .= '<ul class="post-list">';
        foreach ($items as $i) {
            $out .= '<li><article><h2><a href="/updates/archive/' . h($i['id']) . '/">' . h($i['subject']) . '</a></h2>'
                . '<p class="post-meta">' . h(format_date($i['sent'])) . '</p></article></li>';
        }
        $out .= '</ul>';
    }
    send_html(render_layout('Past email updates', $out, 'Past email updates from ' . site_name() . '.'));
}

function update_archive_page(string $id): never
{
    $d = preg_match('/^[a-z0-9-]+$/', $id) ? read_json(UPDATE_ARCHIVE_DIR . "/$id.json") : null;
    if (!$d) {
        not_found();
    }
    $out = '<article><h1>' . h($d['subject']) . '</h1><p class="post-meta">Sent ' . h(format_date($d['sent'])) . '</p>'
        . '<div class="post-body">' . $d['html'] . '</div></article>'
        . '<p><a href="/updates/archive/">All past updates</a> · <a href="/updates/">Get updates by email</a></p>';
    send_html(render_layout((string) ($d['subject'] ?? ''), $out));
}
