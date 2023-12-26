<?php

// define debug flags
$is_debug = isset($_GET['debug']);
$log_time = new DateTime();

// define log function
function debug_log($message) {
    global $is_debug, $log_time;
    if ($is_debug) {
        $now = new DateTime();
        $duration = $now->diff($log_time)->format("%s.%F s");
        error_log("$message ($duration)");
        echo "<code>[$duration]</code> $message<br>";
        // $log_time = $now;
    }
}

function pretty_dump($object) {
    global $is_debug;
    if ($is_debug) {
        echo "<pre>"; var_dump($object); echo "</pre>";
    }
}

// check PHP version, because some APIs are new
$php_version = phpversion(); 
if ($php_version < 8) {
    throw new ErrorException('Invalid PHP version: expected >=8.0.0, got ' . $php_version);
}

// import Composer dependencies
require 'vendor/autoload.php';
use IvoPetkov\HTML5DOMDocument;

// create database if needed
$database_file = 'nuclearpl.sqlite';
if (!file_exists($database_file)) {
    touch($database_file);
}
$database = new PDO("sqlite:$database_file");
$table_create_query = <<<EOT
CREATE TABLE IF NOT EXISTS articles (
    url TEXT NOT NULL PRIMARY KEY,
    title TEXT NOT NULL,
    timestamp INTEGER NOT NULL,
    date_time_rss TEXT NOT NULL,
    description TEXT NOT NULL,
    image_url TEXT
);
EOT;
$database->query($table_create_query);
debug_log("Created database: " . $database_file);

// read existing articles
$saved_article_urls = $database
    ->query('SELECT url FROM articles')
    ->fetchAll();
$saved_article_urls = array_map(fn($x) => $x['url'], $saved_article_urls);

// define website constants
$site_title = 'Portal nuclear.pl';
$site_description = 'Najnowsze informacje dotyczące zagadnień jądrowych z kraju i ze świata';
$base_url = 'https://nuclear.pl';
$logo_url = 'https://nuclear.pl/images/nuclear_logo.png';

// read main page
$main_url = $base_url . "/wiadomosci,index,0,0,0.html";
$main_document = new HTML5DOMDocument();
$stream_context = stream_context_create(["ssl"=>["verify_peer"=>false]]);
$main_document->loadHTMLFile($main_url, HTML5DOMDocument::ALLOW_DUPLICATE_IDS, $stream_context);
debug_log("Loaded main page: " . $main_url);

// detect article items
$main_view = $main_document->querySelector('div.article div.main');
$article_cards = $main_view->querySelectorAll('article');
debug_log("Detected article items: " . $article_cards->length);

// extract article URLs from main page
$main_page_article_urls = array();
foreach ($article_cards as $article_card) {
    $article_url_path = $article_card
        ->querySelector('a')
        ?->getAttribute('href');
    $article_url_path = ltrim($article_url_path, '.');
    $article_url = $base_url . $article_url_path;
    array_push($main_page_article_urls, $article_url);
}
debug_log("Extracted article URLs: " . count($main_page_article_urls));

// determine which articles are missing from database
$main_not_present_in_saved = array_diff($main_page_article_urls, $saved_article_urls);
debug_log("Counted missing articles: " . count($main_not_present_in_saved));

// for each missing article...
foreach ($main_not_present_in_saved as $article_url) {

    // load article
    $article_document = new HTML5DOMDocument();
    $article_document->loadHTMLFile($article_url, HTML5DOMDocument::ALLOW_DUPLICATE_IDS, $stream_context);
    $article = $article_document->querySelector('div.main div');
    
    // extract title
    $title = $article
        ->querySelector('h3.news_tytul')
        ?->textContent;
    $title = trim($title);

    debug_log($title);
    
    // extract date
    $date_time = $article
        ->querySelector('p.news_date')
        ?->textContent;
    // Data dodania: piątek, 22 grudnia 2023, autor: nuclear.pl
    $pattern = "/Data dodania: \w+, (\d+) (\w+) (\d+), autor: .*/u";
    $date_match = preg_match($pattern, $date_time, $matches);
    $MONTHS = array('stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia');
    $day = $matches[1];
    $month = array_search($matches[2], $MONTHS, true);
    $year = $matches[3];
    $date_time = DateTime::createFromFormat('j n Y', "$day $month $year");

    $timestamp = $date_time->getTimestamp();
    $date_time_rss = $date_time->format(DateTime::RSS);

    debug_log($date_time_rss);
    return;

    // extract content
    $content_body = $article->querySelector('div.newsContent div.text');
    $description = htmlspecialchars($content_body->innerHTML);

    // extract image
    $image_path = $article
        ->querySelector('div.img div.imageSV img')
        ?->getAttribute('src');
    $image_url = $image_path ? $base_url . $image_path : null;

    // append image to description if present
    if ($image_url) {
        $description = '<img src="' . $image_url . '"><br>' . $description;
    }

    // add new item to database (url, title, timestamp, date_time_rss, description, image_url,)
    $query = "INSERT OR REPLACE INTO articles VALUES ('$article_url', '$title', $timestamp, '$date_time_rss', '$description', '$image_url')";
    try {
        $database->prepare($query)->execute();
    } catch (PDOException) {
        debug_log("Failed to insert article: " . $article_url);
        continue;
    }
    debug_log("Article loaded and saved: " . $article_url);
}

// sort database and remove excess positions
$delete_over_limit = <<<EOD
DELETE FROM articles WHERE
timestamp NOT IN (
    SELECT timestamp FROM articles ORDER BY timestamp DESC LIMIT 30
)
EOD;
$database->prepare($delete_over_limit)->execute();

// read final articles from database
$final_articles = $database
    ->query('SELECT * FROM articles ORDER BY timestamp DESC')
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

// create RSS item for articles
foreach ($final_articles as $article) {
    $item = $channel->addChild('item');
    $item->addChild('title', $article['title']);
    $item->addChild('link', $article['url']);
    $item->addChild('pubDate', $article['date_time_rss']);
    $item->addChild('description', $article['description']);
}

// generate rss
$rss_xml = $rss->asXML();
if ($is_debug) {
    echo "<hr>";
    pretty_dump($rss_xml);
} else {
    header('Content-Type: application/rss+xml');
    echo $rss_xml;
}
