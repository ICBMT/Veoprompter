<?php

require __DIR__.'/../app/Services/Ideas/TrendRssParser.php';
$parser = new App\Services\Ideas\TrendRssParser;
$now = new DateTimeImmutable('2026-10-15 20:00:00 UTC'); // Oct 16 in Pakistan, Oct 15 in the US.
$count = 0;
$assert = function ($condition, $message) use (&$count) { if (!$condition) throw new RuntimeException($message); $count++; };
$item = fn ($title, $date) => '<item><title>'.htmlspecialchars($title, ENT_XML1).'</title><pubDate>'.$date.'</pubDate></item>';
$xml = '<rss><channel>'.$item('Current topic', 'Thu, 15 Oct 2026 19:30:00 GMT').$item('current topic', 'Thu, 15 Oct 2026 19:40:00 GMT').$item('Old topic', 'Wed, 14 Oct 2026 10:00:00 GMT').$item('Future topic', 'Fri, 16 Oct 2026 20:00:00 GMT').'</channel></rss>';
$result = $parser->parse($xml, 'PK', 'Asia/Karachi', $now);
$assert(count($result) === 1, 'only current-day nonduplicate evidence survives');
$assert(str_starts_with($result[0]['published_at'], '2026-10-16'), 'region-local date, not server UTC day');
$assert(str_starts_with($result[0]['url'], 'https://trends.google.com/trending?geo=PK'), 'server-owned source URL');
$assert(count($parser->parse($xml, 'US', 'America/New_York', $now)) === 1, 'US local evidence date supported');
foreach (['<broken', '<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss/>', str_repeat('x', 1000001), '<rss><channel>'.$item('No date', '').'</channel></rss>', '<rss><channel>'.$item('Relative date', 'now').'</channel></rss>'] as $bad) {
    $assert($parser->parse($bad, 'PK', 'Asia/Karachi', $now) === [], 'malformed/unsafe/oversized/undated input rejected');
}
$many = '<rss><channel>';
foreach (range(1, 50) as $i) $many .= $item('Topic '.$i, 'Thu, 15 Oct 2026 19:30:00 GMT');
$assert(count($parser->parse($many.'</channel></rss>', 'PK', 'Asia/Karachi', $now)) === 20, 'bounded evidence list');
$assert($parser->parse('<rss><channel>'.$item('<img src=x onerror=alert(1)>Topic', 'Thu, 15 Oct 2026 19:30:00 GMT').'</channel></rss>', 'PK', 'Asia/Karachi', $now)[0]['topic'] === 'Topic', 'source markup stripped');
echo "PASS: {$count} live-trend parser assertions\n";
