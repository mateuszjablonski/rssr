<?php

// check PHP version, because some APIs are new
$php_version = phpversion(); 
if ($php_version < 8) {
    throw new ErrorException('Invalid PHP version: expected >=8.0.0, got ' . $php_version);
}

// define debug flag
$is_debug = isset($_GET['debug']);
$start_time = new DateTime();
$start_time = $start_time->format('H:i:s:u');

// import Composer dependencies
require 'vendor/autoload.php';
use IvoPetkov\HTML5DOMDocument;

// setup database
$database_file = 'jedzbezpiecznie.sqlite';
if (!file_exists($database_file)) {
    touch($database_file);
}
$database = new PDO("sqlite:$database_file");
$table_create_query = <<<EOT
CREATE TABLE IF NOT EXISTS videos (
    url TEXT NOT NULL PRIMARY KEY,
    title TEXT NOT NULL,
    timestamp INTEGER NOT NULL,
    date_time_rss TEXT NOT NULL,
    description TEXT NOT NULL,
    image_url TEXT
);
EOT;
$database->query($table_create_query);

// read existing videos
$saved_video_urls = $database
    ->query('SELECT url FROM videos')
    ->fetchAll();
$saved_video_urls = array_map(fn($x) => $x['url'], $saved_video_urls);

// define website constants
$site_title = 'Jedź Bezpiecznie TVP';
$site_description = 'Praktyczne porady dla kierowców';
$base_url = 'https://krakow.tvp.pl';
$logo_url = 'https://s3.tvp.pl/images2/c/8/4/uid_c845be81f638b183571e467d20a7edf31615997731068_width_900_play_0_pos_0_gs_0_height_506.jpg';

// extract videos as JSON object
$main_url = $base_url . '/1279100/jedz-bezpiecznie?order=release_date_desc';
$main_document = file_get_contents($main_url);
$main_json = explode('window.__websiteData = ', $main_document)[1];
$main_json = explode('</script>', $main_json)[0];
$main_json = substr($main_json, 0, -9) . '}'; // SUPER FLAKY
$main_json = json_decode($main_json, true, 512, JSON_THROW_ON_ERROR);

// create list with all videos
$all_videos = array();
$all_videos[] = $main_json['latestVideo'];
$all_videos = array_merge($all_videos, $main_json['videos']);

// extract video URLs from main page
$main_page_video_urls = array();
foreach ($all_videos as $video) {
    $video_url = $base_url . $video['url'];
    array_push($main_page_video_urls, $video_url);
}

// determine which videos are missing from database
$main_not_present_in_saved = array_diff($main_page_video_urls, $saved_video_urls);

// for each missing video...
foreach ($main_not_present_in_saved as $video_url) {

    // load video page as JSON object
    $video_document = file_get_contents($video_url);
    $video_json = explode('window.__newsData = ', $video_document)[1];
    $video_json = explode('window.__newData', $video_json)[0];
    $video_json = substr($video_json, 0, -13) . '}'; // SUPER FLAKY
    $video_json = json_decode($video_json, true, 512, JSON_THROW_ON_ERROR);

    // extract title
    $title = "Jedź Bezpiecznnie: " . $video_json['title'];
    
    // extract timestamp and convert to rss date
    $timestamp = $video_json['release_date_long'];
    $date_time = new DateTime();
    $date_time = $date_time->setTimestamp($timestamp);
    $date_time_rss = $date_time->format(DateTime::RSS);

    // extract description
    $description = $video_json['text_paragraph_lead'];

    // extract image url
    $image_url = $video_json['image'][0]['url'];

    // append image to description
    $description = '<img src="' . $image_url . '"><br>' . $description;

    // add new item to database (url, title, timestamp, date_time_rss, description, image_url,)
    $query = <<<EOD
    INSERT INTO videos VALUES 
    ('$video_url', '$title', $timestamp, '$date_time_rss', '$description', '$image_url')
    EOD;
    $database->prepare($query)->execute();
}

// sort database and remove excess positions
$delete_over_limit = <<<EOD
DELETE FROM videos WHERE
timestamp NOT IN (
    SELECT timestamp FROM videos  ORDER BY timestamp DESC LIMIT 30
)
EOD;
$database->prepare($delete_over_limit)->execute();

// read final videos from database
$final_videos = $database
    ->query('SELECT * FROM videos')
    ->fetchAll();

// prepare RSS container
$rss = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" />');
$channel = $rss->addChild('channel');
$channel->addChild('title', $site_title);
$channel->addChild('link', $base_url);
$channel->addChild('description', $site_description);
$logo = $channel->addChild('image');
$logo->addChild('url', $logo_url);
$logo->addChild('title', $site_title);
$logo->addChild('link', $base_url);

// create RSS item for videos
foreach ($final_videos as $video) {
    $item = $channel->addChild('item');
    $item->addChild('title', $video['title']);
    $item->addChild('link', $video['url']);
    $item->addChild('pubDate', $video['date_time_rss']);
    $item->addChild('description', $video['description']);
}

// generate rss
$rss_xml = $rss->asXML();

// debug print output
if ($is_debug) {
    // print execution time
    $end_time = new DateTime();
    $end_time = $end_time->format('H:i:s:u');
    echo $start_time . ' <- start time<br>' . $end_time . ' <- end time<br><hr>';

    // print preview
    $preview = new DOMDocument;
    $preview->preserveWhiteSpace = false;
    $preview->formatOutput = true;
    $preview->loadXML($rss_xml);
    header('Content-Type: text/html');
    echo '<pre style="white-space: pre-wrap;">' . htmlentities($preview->saveXML()) . '</pre>';
} else {
    header('Content-Type: application/rss+xml');
    echo $rss_xml;
}
