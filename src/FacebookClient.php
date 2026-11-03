<?php

namespace CbhScraper;

use RuntimeException;

/**
 * Client HTTP không đăng nhập: chỉ đọc được nội dung công khai của trang.
 */
class FacebookClient
{
    private const BASE = 'https://www.facebook.com';

    private ?string $lsd = null;
    private string $cookieFile;
    private float $lastRequestAt = 0.0;

    public function __construct(private array $config, private float $delay = 1.5)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'cbhfb');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    /**
     * Một trang feed (Facebook trả 3 bài mỗi lần).
     *
     * @return array{stories: array<int, array>, cursor: ?string, has_next: bool}
     */
    public function feedPage(?string $cursor, ?int $beforeTime): array
    {
        $variables = [
            'afterTime' => null,
            'beforeTime' => $beforeTime,
            'count' => 3,
            'cursor' => $cursor,
            'feedLocation' => 'TIMELINE',
            'feedbackSource' => 0,
            'focusCommentID' => null,
            'memorializedSplitTimeFilter' => null,
            'omitPinnedPost' => false,
            'postedBy' => ['group' => 'OWNER'],
            'privacy' => null,
            'privacySelectorRenderLocation' => 'COMET_STREAM',
            'renderLocation' => 'timeline',
            'scale' => 1,
            'stream_count' => 1,
            'taggedInOnly' => null,
            'trackingCode' => null,
            'useDefaultActor' => false,
            'id' => $this->config['page_id'],
        ];
        foreach ($this->config['relay_providers'] as $name => $value) {
            $variables["__relay_internal__pv__{$name}relayprovider"] = $value;
        }

        return $this->retry(function () use ($variables) {
            $body = $this->graphql($this->config['feed_query'], $variables);

            $stories = [];
            $pageInfo = null;
            $errors = [];
            foreach (explode("\n", $body) as $line) {
                $chunk = json_decode($line, true);
                if (!is_array($chunk)) {
                    continue;
                }
                foreach ($chunk['errors'] ?? [] as $error) {
                    $errors[] = $error['message'] ?? 'unknown';
                }
                $data = $chunk['data'] ?? [];
                $feed = $data['node']['timeline_list_feed_units'] ?? null;
                foreach ($feed['edges'] ?? [] as $edge) {
                    $stories[] = $edge['node'];
                }
                // Các bài sau bài đầu được stream thành từng dòng riêng
                if (($data['node']['__typename'] ?? null) === 'Story') {
                    $stories[] = $data['node'];
                }
                $pageInfo = $data['page_info'] ?? $feed['page_info'] ?? $pageInfo;
            }

            // Facebook luôn kèm vài lỗi field_exception vô hại, chỉ coi là hỏng khi không có dữ liệu
            if (!$stories && $pageInfo === null) {
                $this->lsd = null;
                throw new RuntimeException('Feed rỗng: ' . (implode('; ', array_unique($errors)) ?: substr($body, 0, 200)));
            }

            return [
                'stories' => $stories,
                'cursor' => $pageInfo['end_cursor'] ?? null,
                'has_next' => (bool) ($pageInfo['has_next_page'] ?? false),
            ];
        });
    }

    /**
     * Trang xem ảnh: cho ảnh kích thước gốc và id của media kế tiếp trong bài.
     *
     * @return array{uri: ?string, width: ?int, height: ?int, next_id: ?string, next_type: ?string}
     */
    public function photoPage(string $photoId, string $postId): array
    {
        return $this->retry(function () use ($photoId, $postId) {
            $html = $this->get(self::BASE . "/photo/?fbid={$photoId}&set=pcb.{$postId}");

            $image = null;
            if (preg_match('/"image":(\{"uri":"[^"]+"[^}]*\})/', $html, $m)) {
                $image = json_decode($m[1], true);
            }
            preg_match('/"nextMediaAfterNodeId":\{"__typename":"(\w+)","id":"(\d+)"/', $html, $next);

            if ($image === null && !$next) {
                throw new RuntimeException("Không đọc được trang ảnh {$photoId}");
            }

            return [
                'uri' => $image['uri'] ?? null,
                'width' => $image['width'] ?? null,
                'height' => $image['height'] ?? null,
                'next_id' => $next[2] ?? null,
                'next_type' => $next[1] ?? null,
            ];
        });
    }

    /**
     * Link mp4 trực tiếp của một video công khai, ưu tiên HD.
     */
    public function videoSource(string $videoId): ?string
    {
        return $this->retry(function () use ($videoId) {
            $html = $this->get(self::BASE . "/watch/?v={$videoId}");

            return PostParser::bestVideoUrl(self::scrapeVideoFields($html));
        });
    }

    public function download(string $url, string $path): void
    {
        $this->retry(function () use ($url, $path) {
            $fp = fopen($path . '.part', 'w');
            $ch = curl_init($url);
            $dlOptions = [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 900,
                CURLOPT_USERAGENT => $this->config['user_agent'],
            ];
            $proxy = getenv('ALL_PROXY') ?: getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: ($this->config['proxy'] ?? null);
            if ($proxy) {
                $dlOptions[CURLOPT_PROXY] = $proxy;
                if (str_starts_with($proxy, 'socks5')) {
                    $dlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
                }
            }
            curl_setopt_array($ch, $dlOptions);
            $ok = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (!$ok || $status !== 200 || filesize($path . '.part') === 0) {
                @unlink($path . '.part');
                throw new RuntimeException("Tải media thất bại (HTTP {$status}) {$error}");
            }
            rename($path . '.part', $path);
        });
    }

    private static function scrapeVideoFields(string $html): array
    {
        $fields = [];
        foreach (['browser_native_hd_url', 'browser_native_sd_url', 'playable_url_quality_hd', 'playable_url'] as $key) {
            if (preg_match('/"' . $key . '":("https:[^"]+")/', $html, $m)) {
                $fields[$key] = json_decode($m[1]);
            }
        }
        if (preg_match_all('/\{"progressive_url":("https:[^"]+"),"failure_reason":[^,]*,"metadata":\{"quality":"(\w+)"\}/', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $fields['progressive_urls'][] = ['progressive_url' => json_decode($match[1]), 'metadata' => ['quality' => $match[2]]];
            }
        }

        return $fields;
    }

    private function graphql(array $query, array $variables): string
    {
        if ($this->lsd === null) {
            $this->bootstrap();
        }

        return $this->request(self::BASE . '/api/graphql/', [
            'av' => '0',
            '__user' => '0',
            '__a' => '1',
            '__comet_req' => '15',
            'lsd' => $this->lsd,
            'fb_api_caller_class' => 'RelayModern',
            'fb_api_req_friendly_name' => $query['name'],
            'variables' => json_encode($variables),
            'server_timestamps' => 'true',
            'doc_id' => $query['doc_id'],
        ], [
            'X-FB-LSD: ' . $this->lsd,
            'X-FB-Friendly-Name: ' . $query['name'],
            'Origin: ' . self::BASE,
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
        ]);
    }

    /**
     * Mở trang để lấy cookie khách và token LSD dùng cho các request GraphQL.
     */
    private function bootstrap(): void
    {
        $html = $this->get($this->config['page_url']);
        if (!preg_match('/"LSD",\[\],\{"token":"([^"]+)"/', $html, $m)) {
            throw new RuntimeException('Không lấy được token LSD (có thể Facebook đang chặn IP này)');
        }
        $this->lsd = $m[1];
    }

    private function get(string $url): string
    {
        return $this->request($url, null, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
        ]);
    }

    private function request(string $url, ?array $post, array $headers): string
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequestAt);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HTTPHEADER => array_merge([
                'User-Agent: ' . $this->config['user_agent'],
                'Accept-Language: en-US,en;q=0.9',
            ], $headers),
        ];
        $proxy = getenv('ALL_PROXY') ?: getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: ($this->config['proxy'] ?? null);
        if ($proxy) {
            $options[CURLOPT_PROXY] = $proxy;
            if (str_starts_with($proxy, 'socks5')) {
                $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            }
        }
        curl_setopt_array($ch, $options);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $this->lastRequestAt = microtime(true);

        if ($body === false || $status !== 200) {
            throw new RuntimeException("HTTP {$status} {$error} ({$url})");
        }

        return $body;
    }

    private function retry(callable $fn, int $attempts = 4): mixed
    {
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (RuntimeException $e) {
                if ($i >= $attempts) {
                    throw $e;
                }
                fwrite(STDERR, "  ! {$e->getMessage()} — thử lại lần {$i}\n");
                sleep(5 * $i);
            }
        }
    }
}
