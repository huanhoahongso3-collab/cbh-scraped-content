<?php

namespace CbhScraper;

use RuntimeException;

class Scraper
{
    // Ở chế độ cập nhật, gặp liên tiếp từng này bài đã có thì coi như đã bắt kịp
    private const KNOWN_STREAK_LIMIT = 6;
    // Số bài liên tiếp cũ hơn mốc --since để dừng phân trang
    private const OLD_STREAK_LIMIT = 5;

    private string $postsDir;
    private string $stateFile;
    /** @var array<string, array> */
    private array $posts = [];

    public function __construct(
        private FacebookClient $client,
        private PostParser $parser,
        private string $dataDir,
        private bool $withMedia = true,
        private ?int $sinceTimestamp = null,
    ) {
        $this->postsDir = "{$dataDir}/posts";
        $this->stateFile = "{$dataDir}/state.json";
        if (!is_dir($this->postsDir)) {
            mkdir($this->postsDir, 0777, true);
        }
        foreach (glob("{$this->postsDir}/*/post.json") as $file) {
            $post = json_decode(file_get_contents($file), true);
            $this->posts[$post['post_id']] = $post;
        }
    }

    public function run(?int $maxPosts, bool $update): void
    {
        $state = is_file($this->stateFile) ? json_decode(file_get_contents($this->stateFile), true) : [];
        $update = $update || !empty($state['reached_end']);

        // Chạy tiếp lần trước: lọc theo thời gian bài cũ nhất đã có thay vì cursor (cursor hết hạn nhanh)
        $beforeTime = null;
        if (!$update && $this->posts) {
            $beforeTime = min(array_column($this->posts, 'creation_time'));
            $this->log('Tiếp tục từ bài cũ nhất đã lưu (' . date('Y-m-d', $beforeTime) . '), đã có ' . count($this->posts) . ' bài');
        }

        $cursor = null;
        $handled = 0;
        $knownStreak = 0;
        $oldStreak = 0;
        do {
            $page = $this->client->feedPage($cursor, $beforeTime);
            foreach ($page['stories'] as $story) {
                if (empty($story['post_id'])) {
                    continue;
                }

                // Kiểm tra mốc thời gian --since nếu có
                $postTime = $story['creation_time'] ?? null;
                if ($this->sinceTimestamp !== null && $postTime !== null && $postTime < $this->sinceTimestamp) {
                    $oldStreak++;
                    if ($oldStreak >= self::OLD_STREAK_LIMIT) {
                        $this->log('Đã chạm mốc thời gian --since (' . date('Y-m-d', $this->sinceTimestamp) . '). Dừng quét.');
                        break 2;
                    }
                    continue;
                } else {
                    $oldStreak = 0;
                }

                $known = $this->posts[$story['post_id']] ?? null;
                if ($known && ($known['media_complete'] || !$this->withMedia)) {
                    $knownStreak++;
                    continue;
                }
                $knownStreak = 0;

                $post = $this->parser->parse($story);
                $this->log(sprintf('[%d] %s %s — %d media — %s', count($this->posts) + ($known ? 0 : 1), substr((string) $post['created_at'], 0, 10), $post['post_id'], $post['media_count'], self::excerpt($post['text'])));
                $this->save($post);
                $handled++;
            }
            $this->writeIndex();

            $cursor = $page['cursor'];
            $reachedEnd = !$page['has_next'] || $cursor === null;
            if ($update && $knownStreak >= self::KNOWN_STREAK_LIMIT) {
                $this->log('Đã bắt kịp các bài đã lưu.');
                break;
            }
            if ($maxPosts !== null && $handled >= $maxPosts) {
                break;
            }
        } while (!$reachedEnd);

        if (!empty($reachedEnd)) {
            $state['reached_end'] = true;
            $this->log('Đã tới bài viết cũ nhất của trang.');
        }
        $state['updated_at'] = date(DATE_ATOM);
        file_put_contents($this->stateFile, self::json($state));
        $this->log('Tổng cộng ' . count($this->posts) . " bài trong {$this->dataDir}/posts.json");
    }

    private function save(array $post): void
    {
        $dir = "{$this->postsDir}/{$post['post_id']}";
        if (!is_dir("{$dir}/media")) {
            mkdir("{$dir}/media", 0777, true);
        }

        $post['media_complete'] = false;
        if ($this->withMedia) {
            $post['media_complete'] = $this->fetchMedia($post, "{$dir}/media");
        }
        $post['scraped_at'] = date(DATE_ATOM);

        file_put_contents("{$dir}/post.json", self::json($post));
        $this->posts[$post['post_id']] = $post;
    }

    /**
     * Bổ sung media còn thiếu (feed chỉ trả 5 ảnh đầu của album) rồi tải tất cả về.
     */
    private function fetchMedia(array &$post, string $mediaDir): bool
    {
        $items = $post['media'];
        if (!$items) {
            return true;
        }

        $albumComplete = false;
        if (count($items) >= 5 && $items[0]['type'] === 'photo') {
            $items = $this->walkAlbum($items, $post['post_id']);
            $albumComplete = true;
        }

        $allOk = true;
        foreach ($items as $i => &$item) {
            $num = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $ext = $item['type'] === 'video' ? 'mp4' : 'jpg';
            $file = "{$num}_{$item['id']}.{$ext}";
            $path = "{$mediaDir}/{$file}";

            if (is_file($path) && filesize($path) > 0) {
                $item['file'] = "media/{$file}";
                continue;
            }

            $url = $item['type'] === 'video'
                ? $this->client->videoSource($item['id'])
                : $item['source_url'];

            if (!$url) {
                $allOk = false;
                continue;
            }

            try {
                $this->client->download($url, $path);
                $item['file'] = "media/{$file}";
            } catch (RuntimeException $e) {
                $this->log("  Lỗi tải media {$item['id']}: {$e->getMessage()}");
                $allOk = false;
            }
        }
        unset($item);

        $post['media'] = $items;
        $post['media_count'] = count($items);

        return $allOk && ($albumComplete || count($items) < 5);
    }

    private function walkAlbum(array $items, string $postId): array
    {
        $seen = array_flip(array_column($items, 'id'));
        $currentId = end($items)['id'];

        while ($currentId) {
            try {
                $info = $this->client->photoPage($currentId, $postId);
            } catch (RuntimeException) {
                break;
            }

            $nextId = $info['next_id'];
            if (!$nextId || isset($seen[$nextId])) {
                break;
            }

            $seen[$nextId] = true;
            $items[] = [
                'id' => $nextId,
                'type' => $info['next_type'] === 'Video' ? 'video' : 'photo',
                'url' => "https://www.facebook.com/photo/?fbid={$nextId}",
                'source_url' => $info['uri'],
                'width' => $info['width'],
                'height' => $info['height'],
                'alt' => null,
                'thumbnail_url' => null,
                'file' => null,
            ];
            $currentId = $nextId;
        }

        return $items;
    }

    private function writeIndex(): void
    {
        $list = array_values($this->posts);
        usort($list, fn ($a, $b) => ($b['creation_time'] ?? 0) <=> ($a['creation_time'] ?? 0));
        file_put_contents("{$this->dataDir}/posts.json", self::json($list));
    }

    private function log(string $msg): void
    {
        echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    }

    private static function excerpt(?string $text, int $limit = 60): string
    {
        $oneLine = preg_replace('/\s+/', ' ', trim((string) $text));

        return mb_strlen($oneLine) > $limit ? mb_substr($oneLine, 0, $limit) . '…' : $oneLine;
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
