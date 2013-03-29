<?php
/**
* Heise Feed Version 0.6
*
* Copyright (c) 2013 elm@skweez.net, http://skweez.net/
*
* Permission is hereby granted, free of charge, to any person obtaining
* a copy of this software and associated documentation files (the
* "Software"), to deal in the Software without restriction, including
* without limitation the rights to use, copy, modify, merge, publish,
* distribute, sublicense, and/or sell copies of the Software, and to
* permit persons to whom the Software is furnished to do so, subject to
* the following conditions:
*
* The above copyright notice and this permission notice shall be
* included in all copies or substantial portions of the Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
* EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
* MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
* NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
* LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
* OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
* WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
**/

header("Content-type: text/xml; charset=UTF-8");
define('CACHEFOLDER','feed');
define('FEEDINTERVAL',600);
define('MAXARTICLES',100);

$feedUrl = urlencode('http://www.heise.de/newsticker/heise-atom.xml');

if( isset($_GET["url"]) ) {
    $feedUrl = urlencode($_GET["url"]);
}

# This script can be called with the option do and the values reload or sync 
# (feed.php?do=reload). "reload" forces a complete reload of the feed source 
# and the content of every feed item from the website. "sync" does only force
# the feed source to be reloaded but not the content of the feed items from the 
# website.
$do_reload = false;
$do_sync = false;
if( isset($_GET['do']) ) {
    if( $_GET["do"]=="reload" ) $do_reload = true;
    if( $_GET["do"]=="sync" ) $do_sync = true;
}

# Do not diplay xml errors
libxml_use_internal_errors(true);

$articlesPath = CACHEFOLDER . '/articles/';
# Create cache folder if it does not exist
if (!is_dir(CACHEFOLDER)) {
    mkdir(CACHEFOLDER);
    mkdir($articlesPath);
}

function getCurrentPageUrl() {
    $pageURL = (@$_SERVER["HTTPS"] == "on") ? "https://" : "http://";
    $urlPath = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if ($_SERVER["SERVER_PORT"] != "80") {
        $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$urlPath;
    } 
    else {
        $pageURL .= $_SERVER["SERVER_NAME"].$urlPath;
    }
    return $pageURL;
}

function getID($string) {
    preg_match('/-([0-9]+)$/', $string, $id);
    return $id[1];
}

function getArticle($url, $id, $date, $forceReload) {
    global $articlesPath;

    if(!$forceReload and file_exists($articlesPath.$id.".txt")) {
        $article = file_get_contents($articlesPath.$id.".txt");
    } else {
        $doc = new DOMDocument();
        $article = mb_convert_encoding(file_get_contents($url), 'HTML-ENTITIES', "UTF-8");
        $doc->loadHTML($article);
        $divs = $doc->getElementsByTagName('div');

        $article = 'Konnte Artikelinhalt nicht finden.';

        $newDoc = new DOMDocument();
        foreach ( $divs as $div ) {
            if( $div->hasAttribute('class') && $div->getAttribute('class') == 'meldung_wrapper' ) {
                $newDoc->appendChild($newDoc->importNode($div,true));
            }
        }
        $article = $newDoc->saveHTML();
        $article = strip_tags($article, '<span><a><pre><b><br><em><ul><li><hr><p><img><strong><table><tbody><td><tr><object><param>');
        $article = str_replace("\"/","\"http://www.heise.de/",$article);
        $article = '<div xmlns="http://www.w3.org/1999/xhtml">' . $article . '</div>';
        if(!file_put_contents($articlesPath.$id.".txt",$article)) die();
    }

    # Delete lowest id files from cache folder
    $files = glob($articlesPath . '*.txt');
    while(count($files) > MAXARTICLES) {
        unlink($files[0]);
        unset($files[0]);
        $files = array_values($files);
    }
    return $article;
}

$cacheFile = CACHEFOLDER."/".$feedUrl.".txt";

if($do_reload or $do_sync or !file_exists($cacheFile) or time() - filemtime($cacheFile) > FEEDINTERVAL) {
    $xml = DOMDocument::load(urldecode($feedUrl));
    $entries = $xml->getElementsByTagName('item');

    # Set self link
    $xml->getElementsByTagName('link')->item(0)->nodeValue=getCurrentPageUrl();

    for ($i=0; $i < $entries->length; $i++) {
        $entry = $entries->item($i);
        $url = $entry->getElementsByTagName('guid')->item(0)->nodeValue;
        $date = $entry->getElementsByTagName('pubDate')->item(0)->nodeValue;

        # Get the post id
        $id = getID($entry->getElementsByTagName('guid')->item(0)->nodeValue);

        $forceReload = false;
        # If we find the word "update" in the title of a post we reload the 
        # article from the source
        if($do_reload or preg_match("/update/i",$entry->getElementsByTagName('title')->item(0)->nodeValue)) {
            $forceReload = true;
        }

        # Remove description element as nearly all feed readers do not show the 
        # content if there is a discription element
        $entry->removeChild($entry->getElementsByTagName('description')->item(0));

        # Remove <content:encoded> element
        $content = $entry->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/content/', 'encoded')->item(0);
        if($content) {
            $entry->removeChild($content);
        }
        # Create content element and fill it with content
        $content = $xml->createElementNS('http://purl.org/rss/1.0/modules/content/','encoded');
        $newContentDiv = getArticle($url, $id, $date, $forceReload);
        $cdata = $xml->createCDATASection($newContentDiv);
        $content->appendChild($cdata);
        $entry->appendChild($content);
    }
    $feed = $xml->saveXML();
    file_put_contents($cacheFile, $feed);
    echo $feed;
} else {
    echo file_get_contents($cacheFile);
}

# Clear xml errors
libxml_clear_errors();
?>
