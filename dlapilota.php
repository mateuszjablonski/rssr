<?php
$php_version = phpversion(); 
if ($php_version < 8) {
    throw new ErrorException('Invalid PHP version: expected >=8.0.0, got ' . $php_version);
}

require 'vendor/autoload.php';
use IvoPetkov\HTML5DOMDocument;

$base_url = 'https://dlapilota.pl';
$document_url = $base_url . '/wiadomosci';
$document = new HTML5DOMDocument();
$document->loadHTMLFile($document_url);
$main = $document->querySelector('#block-dlapilota-theme-content');
$articles = $main->querySelectorAll('.art-box');
$logo_url = 'https://dlapilota.pl/themes/custom/dlapilota/logo.svg';

$rss = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" />');
$channel = $rss->addChild('channel');
$channel->addChild('title', 'Dla Pilota');
$channel->addChild('link', $base_url);
$channel->addChild('description', 'Najpopularniejsze źródło informacji na temat małego i dużego lotnictwa');
$logo = $channel->addChild('image');
$logo->addChild('title', 'DlaPilota.pl logo');
$logo->addChild('url', $logo_url);
$logo->addChild('link', $logo_url);

foreach ($articles as $article) {

    // extract data from html
    $title = $article
        ->querySelector('.field--name-node-title h2 a')
        ?->innerHTML;
    $url = $article
        ->querySelector('.field--name-node-title h2 a')
        ?->getAttribute('href');
    $description = $article
        ->querySelector('.field--name-field-text')
        ?->innerHTML;
    $image_src = $article
        ->querySelector('.field--name-field-media-image a img')
        ?->getAttribute('src');
    $category = $article
        ->querySelector('.field--name-field-category-news a')
        ?->innerHTML;
    $subcategory = $article
        ->querySelector('.field--name-field-category-article div span')
        ?->innerHTML;
    $date = $article
        ->querySelector('.field--name-node-post-date span')
        ?->innerHTML;
    
    // adapt data if needed
    $url = $base_url . $url;
    $category = isset($subcategory) ? $category . ', ' . $subcategory : $category;
    $date = DateTime::createFromFormat('d.m.Y', $date)->format(DateTime::RSS);
    if (isset($image_src)) {
        $image_src = $base_url . $image_src;
        $description = $description . '<br><img src=' . $image_src . '>'; 
    }

    // create RSS item
    $item = $channel->addChild('item');
    $item->addChild('title', $title);
    $item->addChild('link', $url);
    $item->addChild('guid', $url);
    $item->addChild('description'); $item->description = $description; // because <img/>
    $item->addChild('pubDate', $date);
}

$rss_xml = $rss = $rss->asXML();

if (isset($_GET['debug'])) {
    header('Content-Type: text/html');
    $preview = new DOMDocument;
    $preview->preserveWhiteSpace = false;
    $preview->formatOutput = true;
    $preview->loadXML($rss_xml);
    echo "<pre>" . htmlentities($preview->saveXML())."</pre>";
} else {
    header('Content-Type: application/rss+xml');
    echo $rss_xml;
}
