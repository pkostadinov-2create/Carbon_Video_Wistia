<?php

/**
 * Extend available video providers
 */
add_filter('crb_video_providers', 'crb_video_providers_extend');
function crb_video_providers_extend($video_providers) {
	$video_providers[] = 'Wistia';

	return $video_providers;
}

/**
 * Wistia handling code. 
 */
class Carbon_Video_Wistia extends Carbon_Video {
	protected $default_width = '640';
	protected $default_height = '360';

	/**
	 * The default domain name for youtube videos
	 */
	const DEFAULT_DOMAIN = 'www.youtube.com';

	/**
	 * The original domain name of the video: either youtube.com or youtube-nocookies.com
	 * @var string
	 */
	public $domain = self::DEFAULT_DOMAIN;

	/**
	 * Check whether video code looks remotely like youtube link, short link or embed code. 
	 * Returning true here doesn't guarantee that the code will be actually paraseable. 
	 * 
	 * @param  string $video_code
	 * @return boolean
	 */
	static function test($video_code) {
		return preg_match('~(https?:)?//(.+)?(wistia\.com|wistia\.net|wi\.st)/.*~i', $video_code);
	}

	function __construct() {
		$this->regex_fragments = array_merge($this->regex_fragments, array(
			/**
			 * Desribe Wistia video ID 
			 * \p{L} matches anything unicode that qualifies as a Letter (note: not a word character, thus no underscores), 
			 * while \p{N} matches anything that looks like a number (including roman numerals and more exotic things).
			 * \- is just an escaped dash. Although not strictly necessary, I tend to make it a point to escape dashes in character classes... 
			 * Note, that there are dozens of different dashes in unicode, thus giving rise to the following version:
			 */
			"video_id" => '(?P<video_id>[\w\-]+)',

			// Describe GET args list
			"args" => '(?:\?(?P<params>.+?))?',

			// Describe GET args list
			"type" => '(?:(?P<type>.+?)/)?',
		));
		parent::__construct();
	}

	/**
	 * Retrieve Video Image from Wistia api, cache in transients
	 */
	function retrieve_video_info() {
		$transient_key = 'crb_wistia_video_' . $this->get_id();
		$cache = get_transient($transient_key);
		if ( !empty($cache) ) {
			return $cache;
		}

		$url = add_query_arg('url', 'http:' . $this->get_embed_url(), 'http://fast.wistia.net/oembed');

		$result = wp_remote_get($url);
		$result = wp_remote_retrieve_body($result);
		$result = json_decode($result);

		set_transient( $transient_key, $result, 6 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Constructs new object from various video inputs. 
	 */
	function parse($video_code) {
		$regexes = array(
			// Wistia single video embed URL:
			// http://fast.wistia.net/embed/iframe/b0767e8ebb
			"single_video_embed_url" =>
				'~'.
					$this->regex_fragments['protocol'] . 
					'(?P<domain>(.+)?(wistia\.com|wistia\.net|wi\.st))/' . 
					'embed/' .
					$this->regex_fragments['type'] .
					$this->regex_fragments['video_id'] .
					$this->regex_fragments['args'] . 
				'/?$~i',

			// Wistia playlist embed URL:
			// http://fast.wistia.net/embed/playlists/fbe3880a4e
			"playlist_embed_url" =>
				'~'.
					$this->regex_fragments['protocol'] . 
					'(?P<domain>(.+)?(wistia\.com|wistia\.net|wi\.st))/' . 
					'embed/' .
					$this->regex_fragments['type'] .
					$this->regex_fragments['video_id'] .
					$this->regex_fragments['args'] . 
				'/?$~i',

			// Wistia Public Media URL:
			// http://home.wistia.com/medias/e4a27b971d
			"public_url" =>
				'~'.
					$this->regex_fragments['protocol'] . 
					'(?P<domain>(.+)?(wistia\.com|wistia\.net|wi\.st))/' . 
					'medias/' .
					$this->regex_fragments['video_id'] .
					$this->regex_fragments['args'] . 
				'/?$~i',

			// Wistia Embed Code Iframe version:
			// <iframe src="//fast.wistia.net/embed/iframe/tku5yxdmqa" ...></iframe>
			"embed_iframe" =>
				'~'.
					'iframe src="//' .
					'(?P<domain>(.+)?(wistia\.com|wistia\.net|wi\.st))/' . 
					'embed/' .
					$this->regex_fragments['type'] .
					$this->regex_fragments['video_id'] .
					$this->regex_fragments['args'] . 
				'[\'"]~i',

			// Wistia Embed Code Iframe version:
			// <iframe src="//fast.wistia.net/embed/iframe/tku5yxdmqa" ...></iframe>
			"embed_playlists" =>
				'~'.
					'iframe src="//' .
					'(?P<domain>(.+)?(wistia\.com|wistia\.net|wi\.st))/' . 
					'embed/' .
					$this->regex_fragments['type'] .
					$this->regex_fragments['video_id'] .
					$this->regex_fragments['args'] . 
				'[\'"]~i',

			// Wistia Embed Code Async script vestion:
			// <div class="wistia_embed wistia_async_tku5yxdmqa" style="height:360px;width:640px">&nbsp;</div>
			"embed_async" =>
				'~'.
					'div class="' .
					'wistia_embed wistia_async_' . 
					$this->regex_fragments['video_id'] .
					$this->regex_fragments['args'] . 
				'[\'"]~i'
		);

		$args = array();
		$video_input_type = null;

		foreach ($regexes as $regex_type => $regex) {
			$preg_match = preg_match($regex, $video_code, $matches);

			if (preg_match($regex, $video_code, $matches)) {
				$video_input_type = $regex_type;
				$this->video_id = $matches['video_id'];

				if (isset($matches['params'])) {
					// & in the URLs is encoded as &amp;, so fix that before parsing
					$args = htmlspecialchars_decode($matches['params']);
					parse_str($args, $params);

					foreach ($params as $arg_name => $arg_val) {
						$this->set_param($arg_name, $arg_val);
					}
				}

				if (isset($matches['domain'])) {
					$this->domain = $matches['domain'];
				}

				if (isset($matches['type'])) {
					$this->type = $matches['type'];
				} else {
					$this->type = 'iframe';
				}

				// Stop after the first match
				break;
			}
		}

		// For embed codes, width and height should be extracted
		$is_embed_code = in_array($video_input_type, array(
			'embed_iframe',
			'embed_playlists',
			'embed_async'
		));

		if ($is_embed_code) {
			if (preg_match_all('~(?P<dimension>width|height)(=[\'"]|:)(?P<val>\d+)(px)?([\'"]|;)~', $video_code, $matches)) {
				$this->dimensions = array_combine(
					$matches['dimension'],
					$matches['val']
				);
			}
		}

		if (!isset($this->video_id)) {
			return false;
		}
		return true;
	}
	/**
	 * Returns share link for the video, e.g. http://youtu.be/6jCNXASjzMY?t=1s
	 */
	function get_share_link() {
		return $this->get_link();
	}

	function get_link() {
		if ( $this->type == 'playlists' ) {
			return false;
		}

		$url = '//' . $this->domain . '/medias/' . $this->video_id;

		return $url;
	}
	function get_embed_url() {
		$url = '//fast.wistia.net/embed/' . $this->type . '/' . $this->get_id();

		if (!empty($this->params)) {
			$url .= '?' . htmlspecialchars(http_build_query($this->params));
		}

		return $url;
	}
	/**
	 * Returns iframe-based embed code.
	 */
	function get_embed_code($width=null, $height=null) {
		$video_info = $this->retrieve_video_info();

		if ( empty($video_info->html) ) {
			return false;
		}

		return $video_info->html;
	}

	/**
	 * Returns image for the video
	 **/
	function get_image() {
		$video_info = $this->retrieve_video_info();

		if ( empty($video_info->thumbnail_url) ) {
			return false;
		}

		return $video_info->thumbnail_url;
	}

	/**
	 * Returns thumbnail for the video
	 **/
	function get_thumbnail() {
		return $this->get_image();
	}

	/**
	 * Returns the video type - playlist or iframe
	 **/
	function get_type() {
		return $this->type;
	}
}