<?php
// thanks to http://www.alfredforum.com/topic/1788-prevent-flash-of-no-result/?p=10197
mb_internal_encoding("UTF-8");
include_once('include/helper.php');
include_once('include/OhAlfred.php');
include_once('include/spotifious.php');

/**
 * Spotifious (v0.7)
 * 	a natural Spotify controller for Alfred 
 *  <https://github.com/citelao/Spotify-for-Alfred/>
 * 	an Alfred extension by Ben Stolovitz <http://github.com/citelao/>
 *
 * 'main.php'
 *  For sanity's sake, here is in plain English what this file does.
 *
 *  This file determines the correct menu to show and passes pure *data* to the
 *  Spotifious menus class. If I used MVC (which is unwarranted for such a tiny
 *  project), this would be the controller passing output to the view.
 *
 *  The idea is to do all the query parsing code here and have the spotifious
 *  file do the display work. Therefore this isn't legit MVC because the display
 *  code does a lot of work; it doesn't just display strings handed to it from 
 *  this file.
 *
 *  So here's what we do here:
 *
 *  First, if we haven't fully configured Spotifious, show configuration.
 *  If we need to load the country code submenu of configuration, do that, too.
 *
 *  Now if we are configured and the query is tiny, we have three options:
 *   1. First letter is 'c': show the control panel
 *   2. First letter is 's': show settings
 *   3. Otherwise:           show the main menu
 *
 *  Handle searches containing Spotifious-gen'd content (anything with `⟩`):
 * 	 Deal with detail menus (last char is `⟩`)
 *    spotify:album:5XGQ4L4XsTI3uIZiAfeAum ⟩ Transatlanticism ⟩
 *    spotify:artist:0YrtvWJMgSdVrk3SfNjTbx ⟩ spotify:album:0uzgpzN1ZsCNSwnsVUh4bQ ⟩ Death Cab for Cutie ⟩⟩
 *    spotify:artist:0YrtvWJMgSdVrk3SfNjTbx ⟩ spotify:album:0uzgpzN1ZsCNSwnsVUh4bQ ⟩ Death Cab for Cutie ⟩
 *    spotify:artist:0YrtvWJMgSdVrk3SfNjTbx ⟩ spotify:album:0uzgpzN1ZsCNSwnsVUh4bQ ⟩ Death Cab for Cutie ⟩ Transatlanticism ⟩
 *
 *   Also our filter searches, narrowing a detail menu (# of ⟩ > # of URIs)
 *    spotify:album:5XGQ4L4XsTI3uIZiAfeAum ⟩ Transatlanticism ⟩ Lightness
 *    spotify:artist:0YrtvWJMgSdVrk3SfNjTbx ⟩ spotify:album:0uzgpzN1ZsCNSwnsVUh4bQ ⟩ Death Cab for Cutie ⟩⟩ Expo '86
 *    spotify:artist:0YrtvWJMgSdVrk3SfNjTbx ⟩ spotify:album:0uzgpzN1ZsCNSwnsVUh4bQ ⟩ Death Cab for Cutie ⟩ Transatlanticism
 *
 *   Then regular searches including gen'd content (0 < # of ⟩ <= # of URIs)
 *    spotify:album:5XGQ4L4XsTI3uIZiAfeAum ⟩ The Shins
 *    spotify:artist:0YrtvWJMgSdVrk3SfNjTbx ⟩ spotify:album:0uzgpzN1ZsCNSwnsVUh4bQ ⟩ Tally Hall
 *
 *  Now handle URLS & URIs
 *   URL: http://open.spotify.com/artist/5lsC3H1vh9YSRQckyGv0Up
 *   URI: spotify:artist:7lqaPghwYv2mE9baz5XQmL
 *
 *  Everything else is a search.
 */

/* Instantiate OhAlfred output class */
$alfred = new OhAlfred();

/* Parse the query. */
$results = array();
$query   = $alfred->normalize($argv[1]);

/* If Spotifious isn't configured yet, show the checklist. */
if(!hotkeysConfigured() || !helperAppConfigured() || !countryCodeConfigured()) {

	if(mb_strstr($query, 'Country Code ⟩')) {
		$results = Spotifious::countries();
	} else {
		$results = Spotifious::configure(hotkeysConfigured(), helperAppConfigured(), countryCodeConfigured());
	}
	
	$alfred->alfredify($results);
	return;
}

if (mb_strlen($query) <= 3) {
	if(substr($query, 0, 1) == "c") {
		$results = Spotifious::controlPanel();
	} else {
		$results = Spotifious::mainMenu();
	}
} elseif(contains($query, '⟩')) {
	// if the query contains any machine-generated text 
	// (the unicode `⟩` is untypeable so we check for it)
	// we need to parse the query and extract the URLs.
	
	// So split based on the delimeter `⟩` and excise the delimeter and blanks.
	$splitQuery  = array_filter(str_replace("⟩", "", explode("⟩", $query)));
	               array_walk($splitQuery, 'trim_value');

	$URIs = array_filter($splitQuery, 'is_spotify_uri');
	$args = array_diff($splitQuery, $URIs);

	// Find which URI to use (by count, not by array index).
	// Arrows should be twice the number of URIs for the last URI.
	// For every one arrow fewer, traverse one URI backwards. 
	$arrows = mb_substr_count($query, "⟩");
	$depth = count($URIs) - (2 * count($URIs) - $arrows); // equiv to $arrows - count($URIs).

	if (mb_substr($query, -1) == "⟩") { // Machine-generated
		$results = Spotifious::detail($URIs, $args, $depth);
	} elseif($depth > 0) {
		$search = array_pop($args);
		$results = Spotifious::filteredSearch($URIs, $args, $depth, $search);
	} else {
		$results = Spotifious::search(end($args), $alfred->options('country'));
	}
} elseif (contains($query, 'http://')) {
	// Explode the URL and arguments into bits for harvesting.

	// Regex for URLs: http://open\.spotify\.com/(album|track|user/[^/]+/playlist)/([a-zA-Z0-9]+)
	// https://github.com/felixtriller/spotify-embed/blob/master/spotify-embed.php 
	$trimmedQuery = preg_replace('/http:\/\/[^\/]+\/|\//', ' ', $query);
	$splitQuery = explode(' ', $trimmedQuery); // TODO replace with preg_match

	// Craft a URI from the URL.
	// TODO make work for apps and playlists
	$URI = 'spotify:' . $splitQuery[1] . ':' . $splitQuery[2];

	$results = Spotifious::convertableInfo($URI);

} elseif(contains($query, 'spotify:')) {
	// Based off https://github.com/felixtriller/spotify-embed/blob/master/spotify-embed.php
	// TODO: "app:" URLS
	// TODO use 'is_spotify_uri()'
	$parts = preg_contains($query, '/^(spotify:(?:album|artist|app|track|user:[^:]+:playlist):[a-zA-Z0-9]+)(?: )+([^\n]*)$/x');

	if($parts === false) 
		throw new AlfredableException("Invalid Spotify URI", get_defined_vars());

	$URI = $parts[1];

	$results = Spotifious::convertableInfo($URI);

} else {
	$results = Spotifious::search($query, $alfred->options('country'));

}

$alfred->alfredify($results);