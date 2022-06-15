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
    description TEXT NOT NULL,
    image_url TEXT
);
EOT;
$database->query($table_create_query);

// read existing articles
$saved_article_urls = $database
    ->query('SELECT url FROM articles')
    ->fetchAll();
$saved_article_urls = array_map(fn($x) => $x['url'], $saved_article_urls);

// define website constants
$site_title = 'DlaPilota.pl';
$site_description = 'Najpopularniejsze źródło informacji na temat małego i dużego lotnictwa';
$base_url = 'https://dlapilota.pl';
$logo_url = 'https://dlapilota.pl/themes/custom/dlapilota/logo.svg';

// read main page
$main_url = $base_url . '/wiadomosci';
$main_document = new HTML5DOMDocument();
$main_document->loadHTMLFile($main_url, HTML5DOMDocument::ALLOW_DUPLICATE_IDS);
$main_view = $main_document->querySelector('div.view-articles div.view-content');
$article_cards = $main_view->querySelectorAll('div.card-block');

// extract article URLs from main page
$main_page_article_urls = array();
foreach ($article_cards as $article_card) {
    $article_url_path = $article_card
        ->querySelector('div.field--name-node-title h3 a')
        ?->getAttribute('href');
    $article_url = $base_url . $article_url_path;
    array_push($main_page_article_urls, $article_url);
}

// determine which articles are missing from database
$main_not_present_in_saved = array_diff($main_page_article_urls, $saved_article_urls);

// for each missing article...
foreach ($main_not_present_in_saved as $article_url) {

    // load article page as DOM object
    $article_document = new HTML5DOMDocument();
    $article_document->loadHTMLFile($article_url);
    
    // extract title and perform fixes
    $title = $article_document
        ->querySelector('.field--name-node-title h2')
        ?->textContent;
    $title = trim($title);
    $title = str_replace('html5-dom-document-internal-entity1-quot-end', '"', $title);
    
    // extract date and time and convert to timestamp and rss date
    $date_time = $article_document
        ->querySelector('div.field--name-node-post-date div.field__item span.item')
        ?->textContent;
    $date_time = DateTime::createFromFormat('d.m.Y G:i', $date_time);
    $timestamp = $date_time->getTimestamp();
    $date_time_rss = $date_time->format(DateTime::RSS);

    // extract content and set as description
    $content_body = $article_document->querySelector('div.field--name-field-body');
    $content_formatted = $article_document->querySelector('div.field--name-field-text-formatted');
    $content_object = ($content_body ?? $content_formatted);
    $description = $content_object->innerHTML;

    // extract image path
    $image_path = $article_document
        ->querySelector('img.image-style-article-cover')
        ?->getAttribute('src');
    $image_url = $image_path ? $base_url . $image_path : null;

    // append image to description if present
    if ($image_url) {
        $description = '<img src="' . $image_url . '"><br>' . $description;
    }

    // add new item to database (url, title, timestamp, date_time_rss, description, image_url,)
    $query = <<<EOD
    INSERT INTO articles VALUES 
    ('$article_url', '$title', $timestamp, '$date_time_rss', '$description', '$image_url')
    EOD;
    $database->prepare($query)->execute();
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
