<?php

use CbhScraper\FacebookClient;
use CbhScraper\PostParser;
use CbhScraper\Scraper;

require __DIR__ . '/vendor/autoload.php';

$options = getopt('', ['target:', 'since:', 'max-posts:', 'delay:', 'out:', 'existing-dir:', 'update', 'no-media', 'help']);
if (isset($options['help'])) {
    echo <<<TXT
    Cách dùng: php scrape.php [tuỳ chọn]

      --target=ID     ID số hoặc username trang/trang cá nhân Facebook
      --since=ISO8601 Thời điểm cắt (ví dụ: 2025-02-01T00:00:00+07:00)
      --max-posts=N   Chỉ xử lý N bài trong lần chạy này
      --delay=GIÂY    Khoảng nghỉ giữa các request tới Facebook (mặc định 2.0)
      --out=THƯ_MỤC   Thư mục xuất dữ liệu (mặc định ./data)
      --existing-dir=THƯ_MỤC Thư mục lưu trữ media đã tải trước đó (tránh tải lại)
      --update        Quét lại từ bài mới nhất để lấy bài mới đăng
      --no-media      Chỉ lấy JSON, không tải ảnh/video

    TXT;
    exit(0);
}

$config = require __DIR__ . '/config.php';

if (!empty($options['target'])) {
    $target = $options['target'];
    $config['page_id'] = $target;
    $config['page_url'] = is_numeric($target)
        ? "https://www.facebook.com/profile.php?id={$target}"
        : "https://www.facebook.com/{$target}";
}

date_default_timezone_set($config['timezone']);

$sinceTimestamp = !empty($options['since']) ? strtotime($options['since']) : null;
$existingDir = !empty($options['existing-dir']) ? rtrim($options['existing-dir'], '/') : null;

$scraper = new Scraper(
    new FacebookClient($config, (float) ($options['delay'] ?? 2.0)),
    new PostParser($config['timezone']),
    rtrim($options['out'] ?? __DIR__ . '/data', '/'),
    !isset($options['no-media']),
    $sinceTimestamp,
    $existingDir
);

try {
    $scraper->run(isset($options['max-posts']) ? (int) $options['max-posts'] : null, isset($options['update']));
} catch (RuntimeException $e) {
    fwrite(STDERR, "Lỗi: {$e->getMessage()}\nDữ liệu đã lấy vẫn được giữ, chạy lại để tiếp tục.\n");
    exit(1);
}
