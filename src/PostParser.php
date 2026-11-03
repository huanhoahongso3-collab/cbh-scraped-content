<?php

namespace CbhScraper;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Chuyển node "Story" thô của GraphQL thành cấu trúc bài viết gọn.
 */
class PostParser
{
    public function __construct(private string $timezone)
    {
    }

    public function parse(array $story): array
    {
        $content = $story['comet_sections']['content']['story'] ?? [];
        $time = $story['creation_time'] ?? self::find($story, 'creation_time');
        $actor = self::find($story, 'actors')[0] ?? [];
        $feedback = self::findWith($story, 'reaction_count') ?? [];

        $post = [
            'post_id' => $story['post_id'],
            'url' => $story['permalink_url'] ?? $content['wwwURL'] ?? null,
            'creation_time' => $time,
            'created_at' => $time ? (new DateTimeImmutable("@{$time}"))->setTimezone(new DateTimeZone($this->timezone))->format(DATE_ATOM) : null,
            'author' => [
                'id' => $actor['id'] ?? null,
                'name' => $actor['name'] ?? null,
                'url' => $actor['url'] ?? null,
            ],
            'text' => $content['message']['text'] ?? $story['message']['text'] ?? '',
            'reaction_count' => $feedback['reaction_count']['count'] ?? null,
            'comment_count' => $feedback['comment_rendering_instance']['comments']['total_count'] ?? null,
            'share_count' => $feedback['share_count']['count'] ?? null,
            'media_count' => 0,
            'media' => [],
            'link' => null,
            'shared_post' => null,
        ];

        foreach ($story['attachments'] ?? [] as $attachment) {
            $this->parseAttachment($attachment['styles']['attachment'] ?? [], $post);
        }
        $post['media_count'] = max($post['media_count'], count($post['media']));

        $shared = $story['attached_story'] ?? $content['attached_story'] ?? null;
        if (is_array($shared)) {
            $post['shared_post'] = [
                'post_id' => $shared['post_id'] ?? self::find($shared, 'post_id'),
                'url' => $shared['permalink_url'] ?? self::find($shared, 'wwwURL'),
                'author' => self::find($shared, 'actors')[0]['name'] ?? null,
                'text' => self::find($shared, 'message')['text'] ?? '',
            ];
            foreach ($shared['attachments'] ?? [] as $attachment) {
                $this->parseAttachment($attachment['styles']['attachment'] ?? [], $post);
            }
        }

        return $post;
    }

    private function parseAttachment(array $attachment, array &$post): void
    {
        $album = $attachment['all_subattachments'] ?? null;
        if ($album) {
            $post['media_count'] += $album['count'] ?? count($album['nodes']);
            foreach ($album['nodes'] as $node) {
                $post['media'][] = self::media($node['media'], $node['url'] ?? null);
            }

            return;
        }

        if (isset($attachment['media']['id'])) {
            $post['media'][] = self::media($attachment['media'], $attachment['media']['url'] ?? $attachment['url'] ?? null);

            return;
        }

        $link = self::find($attachment, 'web_link')['url'] ?? $attachment['url'] ?? null;
        if ($link) {
            $post['link'] = [
                'url' => $link,
                'title' => $attachment['title_with_entities']['text'] ?? self::find($attachment, 'title_with_entities')['text'] ?? null,
            ];
        }
    }

    public static function media(array $media, ?string $url): array
    {
        $isVideo = ($media['__typename'] ?? '') === 'Video';
        // viewer_image trước đây có uri gốc; hiện tại uri nằm ở photo_image/image/placeholder_image
        $full = $media['viewer_image']['uri'] ?? $media['photo_image']['uri'] ?? $media['image']['uri'] ?? $media['placeholder_image']['uri'] ?? self::find($media, 'uri');

        return [
            'id' => $media['id'],
            'type' => $isVideo ? 'video' : 'photo',
            'url' => $url,
            'source_url' => $isVideo ? self::bestVideoUrl($media) : $full,
            'width' => $media['viewer_image']['width'] ?? $media['photo_image']['width'] ?? $media['width'] ?? null,
            'height' => $media['viewer_image']['height'] ?? $media['photo_image']['height'] ?? $media['height'] ?? null,
            'alt' => $media['accessibility_caption'] ?? null,
            'thumbnail_url' => self::find($media, 'preferred_thumbnail')['image']['uri']
                ?? $media['thumbnailImage']['uri'] ?? $media['photo_image']['uri'] ?? $media['image']['uri'] ?? $full,
            'file' => null,
        ];
    }

    public static function bestVideoUrl(array $data): ?string
    {
        foreach (['browser_native_hd_url', 'playable_url_quality_hd'] as $key) {
            if ($url = self::find($data, $key)) {
                return $url;
            }
        }
        $progressive = self::find($data, 'progressive_urls') ?? [];
        usort($progressive, fn ($a, $b) => (($b['metadata']['quality'] ?? '') === 'HD') <=> (($a['metadata']['quality'] ?? '') === 'HD'));
        foreach ($progressive as $item) {
            if (!empty($item['progressive_url'])) {
                return $item['progressive_url'];
            }
        }

        return self::find($data, 'browser_native_sd_url') ?? self::find($data, 'playable_url');
    }

    /** Giá trị khác null đầu tiên của $key ở bất kỳ độ sâu nào. */
    public static function find(array $data, string $key): mixed
    {
        if (isset($data[$key])) {
            return $data[$key];
        }
        foreach ($data as $value) {
            if (is_array($value) && ($found = self::find($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Mảng con đầu tiên có chứa $key. */
    private static function findWith(array $data, string $key): ?array
    {
        if (isset($data[$key])) {
            return $data;
        }
        foreach ($data as $value) {
            if (is_array($value) && ($found = self::findWith($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
