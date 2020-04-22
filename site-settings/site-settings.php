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

	private $configPath;
	private $config;
	private $fullRequest;
	private $sitePath;

	private $matchedServer;
	private $matchedServerNdx;
	private $matchedBaseUrl;
	private $matchedBlogId;

	public function __construct() {
		$this->readConfig();
		$this->analyzeEnvironment();
		$this->patchWordPress();
	}

	private function readConfig() {
		$this->configPath = __DIR__ . '/site-settings-config.php';

		if (!file_exists($this->configPath)) {
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
			file_put_contents($this->configPath, $license . trim($configTemplate));

			die('Please add your database connection info to site-settings-config.php');
		}

		require_once($this->configPath);

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
						$this->matchedServerNdx = $serverNdx;
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

			// define('WP_ALLOW_MULTISITE', true);
			define('MULTISITE', true);
			define('SUBDOMAIN_INSTALL', $this->matchedServer['url_parts'][$this->matchedBaseUrl]['path'] == '/');
			define('DOMAIN_CURRENT_SITE', $_SERVER['SERVER_NAME']);
			define('PATH_CURRENT_SITE', $this->sitePath . '/');
			define('SITE_ID_CURRENT_SITE', 1);
			define('BLOG_ID_CURRENT_SITE', 1);

			// Take over how WP determines the site ID
			global $current_site;
			$current_site = new stdClass();
			$current_site->id = 1;
			$current_site->domain = $this->matchedServer['url_parts'][$this->matchedBaseUrl]['host'];
			$current_site->path = $this->matchedServer['url_parts'][$this->matchedBaseUrl]['path'];
			$current_site->blog_id = 1;

			global $current_blog;
			$current_blog = new stdClass();
			$current_blog->blog_id = $this->matchedBlogId;
			$current_blog->domain = $this->matchedServer['url_parts'][$this->matchedBaseUrl]['host'];
			$current_blog->path = $this->matchedServer['url_parts'][$this->matchedBaseUrl]['path'];
			$current_blog->site_id = 1;
			$current_blog->registered = '0000-00-00 00:00:00';
			$current_blog->last_updated = '0000-00-00 00:00:00';
			$current_blog->public = '1';
			$current_blog->archived = '0';
			$current_blog->mature = '0';
			$current_blog->spam = '0';
			$current_blog->deleted = '0';
			$current_blog->lang_id = '0';

			global $blog_id;
			$blog_id = $this->matchedBlogId;
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

	public function shutdownFunction() {
		$html = ob_get_clean();

		foreach ($this->config['servers'] as $server) {
			if ($server['blogIds'][$this->matchedBlogId] == $this->matchedBaseUrl) {
				continue;
			}

			$html = str_replace($server['blogIds'][$this->matchedBlogId], $this->matchedBaseUrl, $html);
			$html = str_replace(str_replace('/', '\\/', $server['blogIds'][$this->matchedBlogId]), $this->matchedBaseUrl, $html);	// json encoded too?
		}

		// Handle multisite config issues
		if (defined('WP_ALLOW_MULTISITE') && WP_ALLOW_MULTISITE == true) {
			$this->multisiteInstallation($html);
		}

		echo $html;
	}

	private function multisiteInstallation(&$html) {
		// Installation phase
		if ($this->config['multisite'] == false) {
			if ($this->endswith($_SERVER['REQUEST_URI'], '/wp-admin/network.php')) {
				if (!$_POST) {
					$html = preg_replace('/<h3>Server Address.*?We recommend you change your siteurl.*?<\/p>/s', '', $html);
					$html = preg_replace('/<tr>.*?Because your?.*?<\/tr>/s', '', $html);
	
					// Check if config is read/writable
					if (is_writable($this->configPath)) {
						$message = 'Continue as normal... and follow all prompts. Your config will be updated automatically';
					}
					else {
						$message = '<strong style="color: red;">PHP does not have access to the <code>site-settings-config.php</code>. Halting installation...</strong>';
						$html = preg_replace('/value="Install"/', 'value="Install" disabled="disabled"', $html);
					}
	
					$html = preg_replace('/<tr>.*?Server Address.*?<\/tr>/s', '<tr><th scope="row">Wordpress Multi Environment</th><td>' . $message . '</td></tr>', $html);
				}
				else {
					$configStr = file_get_contents($this->configPath);
					$configStr = preg_replace('/(([\'"])multisite\\2\s*=>\s*)[^,)]+(.)/', '$1true$3', $configStr);
					file_put_contents($this->configPath, $configStr);
	
					$html = preg_replace('/<p>\s*Add the following.*?wp-config\.php.*?<\/textarea>/s', '<p><strong>Wordpress Multi Environment:</strong> Your config files have been patched. Skip to step 2.</p>', $html);
				}
			}
		}

		// Adding site phase
		else {
			if ($this->endswith($_SERVER['SCRIPT_NAME'], '/wp-admin/network/site-new.php') && isset($_GET['id'])) {
				$blogId = intval($_GET['id']);

				if (!isset($this->matchedServer['blogIds'][$blogId])) {
					global $wpdb;

					$record = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->blogs} WHERE blog_id = %d", array(
						$blogId
					)));
	
					if ($record) {
						$record = $record[0];

						if ($this->matchedServer['subdomains']) {
							$partial = explode('.', $record['domain']);
							$partial = reset($partial);
						}
						else {
							$partial = rtrim(substr($record->path, strlen($this->matchedServer['url_parts'][$this->matchedServer['blogIds'][1]]['path'])), '/');
						}

						// Prepare new config
						$config = $this->config;
						foreach ($config['servers'] as $ndx => $server) {
							unset($config['servers'][$ndx]['url_parts']);
							unset($config['servers'][$ndx]['blogIds']);

							$urlParts = $server['url_parts'][$server['blogIds'][1]];
							if ($server['subdomains']) {
								$newBaseUrl = $urlParts['scheme'] . '://' . $partial . '.' . $urlParts['host'];
							}
							else {
								$newBaseUrl = rtrim($urlParts['scheme'] . '://' . $urlParts['host'] . $urlParts['path'] . $partial, '/');
							}

							$config['servers'][$ndx]['sites'][$newBaseUrl] = intval($_GET['id']);
						}

						// Load pretty encoder
						require_once(__DIR__ . '/Kit-PHPEncoder-2.4.0/autoload.php');

						// Generate pretty output
						$encoder = new \Riimu\Kit\PHPEncoder\PHPEncoder();
						$config = $encoder->encode($config, array(
							'array.inline' => false,
							'array.indent' => "\t",
							'array.short'  => false,
							'array.align'  => true
						));
						unset($encoder);

						// Indent by 1 tab
						$config = explode("\n", $config);
						$config = implode("\n\t", $config);

						// Add spacing and comments back
						$config = preg_replace('/(([\'"])multisite\\2[^,]+,)/', "$1\n", $config);
						$config = preg_replace('/(([\'"])host\\2)/', "// DB Arguments\n\t\t\t\t$1", $config);
						$config = preg_replace('/(\\t+([\'"])subdomains\\2)/', "\n\t\t\t\t// Multisite Arguments\n$1", $config);
						$config = preg_replace('/(\\t+([\'"])sites\\2)/', "\n\t\t\t\t// Site(s) on this server\n$1", $config);

						// Add resulting config to file
						$configStr = file_get_contents($this->configPath);
						$configStr = preg_replace('/function\\s+getConfig_WPSiteSettings\\s*\\(\\)(?:\\s|\\n)*\\{(?:\\s|\\n)*return[^;]+?;/', "function getConfig_WPSiteSettings() {\n\treturn {$config};", $configStr);
						file_put_contents($this->configPath, $configStr);
					}
				}
			}
		}
	}

	private function endsWith($haystack, $needle)
	{
		$length = strlen($needle);
		if ($length == 0) {
			return true;
		}
	
		return (substr($haystack, -$length) === $needle);
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
