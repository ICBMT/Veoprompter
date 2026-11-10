<?php

namespace App\Services\Ideas;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Bounded, non-networked XML parsing with an explicit region-local evidence date. */
final class TrendRssParser
{
    public function parse(string $xml, string $region, string $timezone, DateTimeImmutable $now): array
    {
        if (strlen($xml) > 1000000 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) return [];
        $zone = new DateTimeZone($timezone);
        $today = $now->setTimezone($zone);
        $previous = libxml_use_internal_errors(true);
        try {
            $feed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($feed === false) return [];
            $result = [];
            foreach ($feed->channel->item ?? [] as $item) {
                try {
                    $date = trim((string) $item->pubDate);
                    if (! preg_match('/\A(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),\h+\d{1,2}\h+[A-Za-z]{3}\h+\d{4}\h+\d{2}:\d{2}:\d{2}\h+(?:[+-]\d{4}|GMT|UTC)\z/', $date)) continue;
                    $published = (new DateTimeImmutable($date))->setTimezone($zone);
                    $errors = DateTimeImmutable::getLastErrors();
                    if ($errors && ($errors['warning_count'] || $errors['error_count'])) continue;
                    if ($published->format('Y-m-d') !== $today->format('Y-m-d') || $published > $today->modify('+5 minutes')) continue;
                } catch (Throwable) { continue; }
                $title = mb_substr(trim(strip_tags((string) $item->title)), 0, 240);
                if ($title === '' || isset($result[mb_strtolower($title)])) continue;
                $result[mb_strtolower($title)] = ['id' => count($result) + 1, 'topic' => $title,
                    'published_at' => $published->format(DATE_ATOM),
                    'url' => 'https://trends.google.com/trending?geo='.rawurlencode($region).'&q='.rawurlencode($title)];
                if (count($result) >= 20) break;
            }
            return array_values($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
