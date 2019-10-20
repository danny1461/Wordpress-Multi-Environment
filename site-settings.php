<?php

/**
 * Multi-Environment Setup System for WordPress
 * Developed by Daniel Flynn
 * http://www.dandi.dev/
 * 
 * Copyright (c) 2019 Dandi
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

class WPSiteSettings
{
	private static $instance = null;

	private $config;
	private $fullRequest;
	private $sitePath;

	private $matchedServer;
	private $matchedBaseUrl;
	private $matchedBlogId;

	public function __construct() {
		$this->readConfig();
		$this->analyzeEnvironment();
		$this->patchWordPress();
	}

	private function readConfig() {
		if (!file_exists(__DIR__ . '/site-settings-config.php')) {
			// Get license
			if (preg_match('/\/\\*\\*(?:.|\\s)*?\\*\\//', @file_get_contents(__FILE__), $license)) {
				$license = $license[0] . "\n\n";
			}
			else {
				$license = '';
			}
			$license = "<?php\n\n{$license}";

			$configTemplate = <<<CONFIG
function getConfig_WPSiteSettings() {
	return array(
		'multisite' => false,

		'servers' => array(
			array (
				// DB Arguments
				'host'   => 'mysql.host.com',
				'user'   => 'mysqluser',
				'pass'   => 'password',
				'dbname' => 'wordpress_db',

				// Multisite Arguments
				'subdomains' => false,

				// Site(s) on this server
				'sites' => array(
					// DOMAIN => BLOG_ID
					'https://www.example.com' => 1
				)
			)
		)
	);
}
CONFIG;
			file_put_contents(__DIR__ . '/site-settings-config.php', $license . trim($configTemplate));

			die('Please add your database connection info to site-settings-config.php');
		}

		require_once(__DIR__ . '/site-settings-config.php');

		$this->config = getConfig_WPSiteSettings();
		foreach ($this->config['servers'] as $serverNdx => $server) {
			if (isset($server['sites'][0])) {
				foreach ($server['sites'] as $i => $baseUrl) {
					unset($this->config['servers'][$serverNdx]['sites'][$i]);
					$this->config['servers'][$serverNdx]['sites'][$baseUrl] = $i + 1;
				}
			}

			$this->config['servers'][$serverNdx]['url_parts'] = array();

			foreach ($server['sites'] as $baseUrl => $blogId) {
				$this->config['servers'][$serverNdx]['url_parts'][$baseUrl] = parse_url(rtrim($baseUrl, '/') . '/');
			}

			$this->config['servers'][$serverNdx]['blogIds'] = array_flip($this->config['servers'][$serverNdx]['sites']);
		}
	}

	private function analyzeEnvironment() {
		$this->fullRequest = $_SERVER['SERVER_PORT'] == '443' ? 'https' : 'http';
		$this->fullRequest .= '://' . $_SERVER['SERVER_NAME'];
		if (!in_array($_SERVER['SERVER_PORT'], array('80', '443'))) {
			$this->fullRequest .= ':' . $_SERVER['SERVER_PORT'];
		}
		$this->fullRequest .= $_SERVER['REQUEST_URI'];

		clearstatcache(true);
		$docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
		$thisDir = realpath(__DIR__);
		$this->sitePath = substr($thisDir, strlen($docRoot));
		$this->sitePath = str_replace('\\', '/', $this->sitePath);

		$bestMatchScore = 0;
		foreach ($this->config['servers'] as $serverNdx => $server) {
			foreach ($server['sites'] as $baseUrl => $siteId) {
				if (strpos($this->fullRequest, $baseUrl) === 0) {
					$matchScore = strlen($baseUrl);
					if ($matchScore > $bestMatchScore) {
						$bestMatchScore = $matchScore;
						$this->matchedServer = $server;
						$this->matchedBaseUrl = $baseUrl;
						$this->matchedBlogId = $siteId;
					}
				}
			}
		}
	}

	private function patchWordPress() {
		$filters = array();

		// Handle multisite stuff
		// We define the constants because they are domain specific
		if ($this->config['multisite']) {
			// Define multisite constants

			define('WP_ALLOW_MULTISITE', true);
			define('MULTISITE', true);
			define('SUBDOMAIN_INSTALL', $this->matchedServer['subdomains']);
			define('DOMAIN_CURRENT_SITE', $_SERVER['SERVER_NAME']);
			define('PATH_CURRENT_SITE', $this->sitePath . '/');
			define('SITE_ID_CURRENT_SITE', 1);
			define('BLOG_ID_CURRENT_SITE', 1);

			// Take over how WP determines the site ID
			$filters['pre_get_site_by_path'] = array(
				1 => array(
					array(
						'function' => array($this, 'getSiteByPath'),
						'accepted_args' => 5
					)
				)
			);
		}

		// Tweak how WP reads it's baseurl. This prevents WP from sending redirects before it even starts
		$filters = array_merge($filters, array(
			'option_home' => array(
				1 => array(
					array(
						'function' => array($this, 'baseUrlFilter'),
						'accepted_args' => 1
					)
				)
			),
			'option_siteurl' => array(
				1 => array(
					array(
						'function' => array($this, 'baseUrlFilter'),
						'accepted_args' => 1
					)
				)
			)
		));

		global $wp_filter;
		$wp_filter = $filters;

		// We register a shutdown function to correct urls from content stored in db
		// Internal links and such
		register_shutdown_function(array($this, 'shutdownFunction'));
		ob_start();
	}

	/** Returns the proper base url for the current site
	 * @param $value
	 *
	 * @return mixed
	 */
	public function baseUrlFilter($value) {
		$forBlogId = get_current_blog_id();

		if (isset($this->matchedServer['blogIds'][$forBlogId])) {
			$value = $this->matchedServer['blogIds'][$forBlogId];
		}

		return $value;
	}

	/**
	 * Runs as part of the pre_get_site_by_path filter
	 *
	 * @param StdClass|null $site
	 * @param [type] $domain
	 * @param [type] $path
	 * @param [type] $segments
	 * @param [type] $paths
	 * @return StdClass
	 */
	public function getSiteByPath($site, $domain, $path, $segments, $paths) {
		global $wpdb;

		if ($site) {
			return $site;
		}

		// Match my domain
		$matchedBaseUrl = '';
		foreach ($this->matchedServer['url_parts'] as $baseUrl => $parts) {
			if ($parts['host'] == $domain && $parts['path'] == $paths[0]) {
				$matchedBaseUrl = $baseUrl;
			}
		}

		if (!$matchedBaseUrl) {
			return null;
		}

		$siteObj = $wpdb->get_results(/** @lang MySQL */
			"
			SELECT
				*
			FROM
				{$wpdb->base_prefix}blogs
			WHERE
				blog_id = {$this->matchedServer['sites'][$matchedBaseUrl]} AND
				site_id = 1
		");

		if (!$siteObj) {
			return null;
		}

		$siteObj = $siteObj[0];
		$siteObj->domain = $this->matchedServer['url_parts'][$matchedBaseUrl]['host'];
		$siteObj->path = rtrim($this->matchedServer['url_parts'][$matchedBaseUrl]['path'], '/') . '/';

		return $siteObj;
	}

	public function shutdownFunction() {
		$html = ob_get_clean();

		foreach ($this->config['servers'] as $server) {
			if ($server['blogIds'][$this->matchedBlogId] == $this->matchedBaseUrl) {
				continue;
			}

			$html = str_replace($server['blogIds'][$this->matchedBlogId], $this->matchedBaseUrl, $html);
			$html = str_replace(str_replace('/', '\\/', $server['blogIds'][$this->matchedBlogId]), $this->matchedBaseUrl, $html);	// json encoded too?
		}

		echo $html;
	}

	public static function getInstance() {
		if (is_null(self::$instance)) {
			self::$instance = new WPSiteSettings();
		}

		return self::$instance;
	}

	public static function getSetting($name) {
		return self::getInstance()->matchedServer[$name];
	}
}

WPSiteSettings::getInstance();