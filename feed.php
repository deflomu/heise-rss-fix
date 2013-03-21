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
define('MAXARTIKELS',100);

$feedUrl = urlencode('http://www.heise.de/newsticker/heise-atom.xml');

if( isset($_GET["url"]) ) {
    $feedUrl = urlencode($_GET["url"]);
}

$do_reload = false;
$do_sync = false;
$do_removeDescription = false;

if( isset($_GET['do']) ) {
    if( $_GET["do"]=="reload" ) $do_reload = true;
    if( $_GET["do"]=="sync" ) $do_sync = true;
    if( $_GET["do"]=="removeDescription" ) $do_removeDescription = true;
}

# Do not diplay xml errors
libxml_use_internal_errors(true);

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
    if(!$forceReload and file_exists(CACHEFOLDER."/".$date."-".$id.".txt")) {
        $articleFertig = file_get_contents(CACHEFOLDER."/".$date."-".$id.".txt");
    } else {
        $doc = new DOMDocument();
        $article = mb_convert_encoding(file_get_contents($url), 'HTML-ENTITIES', "UTF-8");
        $doc->loadHTML($article);
        $divs = $doc->getElementsByTagName('div');

        $articleFertig = 'Konnte Articleinhalt nicht finden.';

        $newDoc = new DOMDocument();
        foreach ( $divs as $div ) {
            if( $div->hasAttribute('class') && $div->getAttribute('class') == 'meldung_wrapper' ) {
                $newDoc->appendChild($newDoc->importNode($div,true));
            }
        }
        #TODO: Do not save HTML to read it back in later.
        $articleFertig = $newDoc->saveHTML();
        $articleFertig = strip_tags($articleFertig, '<span><a><pre><b><br><em><ul><li><hr><p><img><strong><table><tbody><td><tr><object><param>');
        $articleFertig = str_replace("\"/","\"http://www.heise.de/",$articleFertig);
        $articleFertig = '<div xmlns="http://www.w3.org/1999/xhtml">' . $articleFertig . '</div>';
        if(!file_put_contents(CACHEFOLDER."/".$date."-".$id.".txt",$articleFertig)) die();
    }
    $files = glob(CACHEFOLDER."/*.txt");
    while(count($files) > MAXARTIKELS) {
        unlink($files[2]);
        $files = glob(CACHEFOLDER."/*.txt");
    }
    return $articleFertig;
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
    
        # Get the id
        $id = getID($entry->getElementsByTagName('guid')->item(0)->nodeValue);

        $forceReload = false;
        if($do_reload or preg_match("/update/i",$entry->getElementsByTagName('title')->item(0)->nodeValue)) {
            $forceReload = true;
        }

        if($do_removeDescription) {
            # Remove description element
            $entry->removeChild($entry->getElementsByTagName('description')->item(0));
        }
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
