<?php

// define debug flags
$is_debug = isset($_GET['debug']);
$log_time = new DateTime();

// define log function
function debug_log($message, $skip_time = false) {
    global $is_debug, $log_time;
    if ($is_debug) {
        $now = new DateTime();
        $duration = $now->diff($log_time)->format("%s.%F");
        error_log("$message ($duration)");
        echo "<pre>";
        if ($skip_time) {
            // echo "\t";
        } else {
            echo "[$duration] ";
        }
        echo "$message</pre>";
    }
}

// check PHP version, because some APIs are new
$php_version = phpversion(); 
if ($php_version < 8) {
    throw new ErrorException("Invalid PHP version: expected >=8.0.0, got $php_version");
}

// import Composer dependencies
require 'vendor/autoload.php';
use IvoPetkov\HTML5DOMDocument;
$indenter = new \Gajus\Dindent\Indenter();

// create database if needed
$database_file = 'dlapilota.sqlite';
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
    description TEXT NOT NULL
);
EOT;
$database->query($table_create_query);

debug_log("Database created: $database_file");

// read existing articles
$saved_article_urls = $database
    ->query('SELECT url FROM articles')
    ->fetchAll();
$saved_article_urls = array_map(fn($x) => $x['url'], $saved_article_urls);
$existing_articles_count = count($saved_article_urls);

debug_log("Existing article URLs read from database: $existing_articles_count");

// define website constants
$site_title = 'DlaPilota.pl';
$site_description = 'Najpopularniejsze źródło informacji na temat małego i dużego lotnictwa';
$base_url = 'https://dlapilota.pl';
$logo_url = 'https://dlapilota.pl/themes/custom/dlapilota/logo.svg';

// read main page
$main_url = $base_url . "/wiadomosci"."/";
$stream_context = stream_context_create(["ssl"=>["verify_peer"=>false]]);
$main_page = file_get_contents($main_url, false, $stream_context);
$main_document = new HTML5DOMDocument();
$main_document->loadHTML($main_page, HTML5DOMDocument::ALLOW_DUPLICATE_IDS);

debug_log("Main page loaded: $main_url");

// detect article items
$main_view = $main_document->querySelector('div.view-articles div.view-content');
$article_cards = $main_view->querySelectorAll('div.card-block');

debug_log("Article items detected on main page: $article_cards->length");

// extract article URLs from main page
$main_page_article_urls = array();
foreach ($article_cards as $article_card) {
    $article_url_path = $article_card
        ->querySelector('div.field--name-node-title h3 a')
        ?->getAttribute('href');
    $article_url = $base_url . $article_url_path;
    array_push($main_page_article_urls, $article_url);
}
$main_page_article_urls_count = count($main_page_article_urls);

debug_log("Article URLs extracted: $main_page_article_urls_count ");

// determine which articles are missing from database
$articles_missing = array_diff($main_page_article_urls, $saved_article_urls);
$articles_missing_count = count($articles_missing);

debug_log("Articles missing from database: $articles_missing_count ");

// for each missing article...
$not_present_count = count($articles_missing);
$inserted_count = 0;
$article_no = 0;
foreach ($articles_missing as $article_url) {
    $article_no++;

    debug_log("Working with article $article_no of $not_present_count");

    // load article
    $article_document = new HTML5DOMDocument();
    $article_document->loadHTMLFile($article_url, HTML5DOMDocument::ALLOW_DUPLICATE_IDS, $stream_context);
    $article = $article_document->querySelector('div.contenthiner');

    debug_log("URL: $article_url", true);

    // extract title
    $title = $article
        ->querySelector('.field--name-node-title h2')
        ?->textContent;
    $title = trim($title);
    $title = str_replace('html5-dom-document-internal-entity1-quot-end', '"', $title);
    
    debug_log("Title: $title", true);

    // extract date
    $date_time = $article
        ->querySelector('div.field--name-node-post-date div.field__item span.item')
        ?->textContent;
        $date_time = trim($date_time);
    $date_time = DateTime::createFromFormat('d.m.Y G:i', $date_time);
    $timestamp = $date_time->getTimestamp();
    $date_time_rss = $date_time->format(DateTime::RSS);

    debug_log("Date: $date_time_rss", true);
    debug_log("Timestamp: $timestamp", true);

    // extract content
    $content_body = $article;
    $content_body->querySelector('div.nodecats')?->remove();
    $content_body->querySelector('div.field--name-field-tags')?->remove();
    $content_body->querySelector('span.a2a_kit')?->remove();
    $content_body->querySelector('div.field--name-field-source')?->remove();
    $description = $content_body->innerHTML;
    $description = $indenter->indent($description);

    debug_log("Content size: ".strlen($description), true);

    // add new item to database (url, title, timestamp, date_time_rss, description)
    $query = "INSERT OR REPLACE INTO articles VALUES "
        . "('$article_url', '$title', $timestamp, '$date_time_rss', '$description')";
    try {
        $database->prepare($query)->execute();
        $inserted_count += 1;
    } catch (PDOException $e) {
        debug_log("Failed to insert article: " . $article_url);
        debug_log($e, true);
        debug_log(htmlspecialchars($description), true);
        continue;
    }
}

debug_log("Articles inserted to database: $inserted_count of $not_present_count");

// sort database and remove excess positions
$excess_count = 30;
$delete_over_limit = <<<EOD
DELETE FROM articles WHERE timestamp NOT IN (
    SELECT timestamp FROM articles ORDER BY timestamp DESC LIMIT $excess_count
)
EOD;
$database->prepare($delete_over_limit)->execute();

debug_log("Excess articles removed from database: limit $excess_count");

// read final articles from database
$final_articles = $database
    ->query('SELECT * FROM articles ORDER BY timestamp DESC')
    ->fetchAll();

debug_log("Articles refetched from database for XML generation");

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

debug_log("RSS feed object created");

// generate rss
$rss_xml = $rss->asXML();
if ($is_debug) {
    debug_log("ECHO RSS_XML");
} else {
    header('Content-Type: application/rss+xml');
    echo $rss_xml;
}
