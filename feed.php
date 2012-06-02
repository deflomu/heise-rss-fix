<?php
// Heise Feed Version 0.5
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

if( isset($_GET['do']) ) {
	if( $_GET["do"]=="reload" ) $do_reload = true;
	if( $_GET["do"]=="sync" ) $do_sync = true;
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
	$newDoc = new DOMDocument();
	if(!$forceReload and file_exists(CACHEFOLDER."/".$date."-".$id.".txt")) {
		$articleFertig = file_get_contents(CACHEFOLDER."/".$date."-".$id.".txt");
	} else {
		$doc = new DOMDocument();
		$article = mb_convert_encoding(file_get_contents($url), 'HTML-ENTITIES', "UTF-8");
		$doc->loadHTML($article);
		$divs = $doc->getElementsByTagName('div');

		$articleFertig = 'Konnte Articleinhalt nicht finden.';

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
	$newDoc->loadHTML($articleFertig);
	return $newDoc->getElementsByTagName('div')->item(0);
}

$cacheFile = CACHEFOLDER."/".$feedUrl.".txt";

if($do_reload or $do_sync or !file_exists($cacheFile) or time() - filemtime($cacheFile) > FEEDINTERVAL) {
	$xml = DOMDocument::load(urldecode($feedUrl));
	$entries = $xml->getElementsByTagName('entry');

	# Set self link
	$xml->getElementsByTagName('link')->item(1)->attributes->item(1)->nodeValue=getCurrentPageUrl();

	for ($i=0; $i < $entries->length; $i++) {
		$entry = $entries->item($i);
		$url = str_replace('/from/atom10', '', $entry->getElementsByTagName('link')->item(0)->getAttribute('href'));
		$date = $entry->getElementsByTagName('updated')->item(0)->nodeValue;
	
		# Get the id
		$id = getID($entry->getElementsByTagName('id')->item(0)->nodeValue);

		$forceReload = false;
		if($do_reload or preg_match("/update/i",$entry->getElementsByTagName('title')->item(0)->nodeValue)) {
			$forceReload = true;
		}
			
		# Create content element and fill it with content
		if ($entry->getElementsByTagName('content')->length == 0) {
			$content = $xml->createElement('content');
			$content->setAttribute('type', 'xhtml');
			$entry->appendChild($content);
		} else {
			$content = $entry->getElementsByTagName('content')->item(0);
		}

		$newContentDiv = getArticle($url, $id, $date, $forceReload);
		if ($content->hasChildNodes()) {
			while ($content->hasChildNodes()) {
				$content->removeChild($content->firstChild);
			}
		}
		$content->appendChild($xml->importNode($newContentDiv, true));
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
