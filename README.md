# RSSR (spelled RSSer)

I consider RSS a foundation of free (as in freedom) internet. I am in pain, because many websites either do not support RSS at all, or do it very poorly, eg. without proper timestamps, icons, or uuids.

This repository is a bunch of PHP files, that generate RSS using PHP's DOMDocument, CSS accessors and SimpleXMLElement. The basic idea is to load the page, parse HTML, access proper elements, convert them to the RSS specs, and put into a RSS-compliant XML.

I am not interested in creating a generic engine, because every site has its quirks, and those PHP files are simple as you know what. Why PHP? Because 99.999% of public hosting services support PHP, so using this magic boils down to copying the file on your server, and adding the URL into your favorite reader.

My favorite reader is [NetNewsWire](https://netnewswire.com/).

## Setup

Project uses PHP Composer to setup dependencies.

```
brew install --formula php
brew install --formula composer
composer install
```
