<?php

/**
 * Used to combine css and js files in to a single compressed file
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0 Beta 2
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Used to combine css or js files in to a single file
 * Checks if the files have changed, and if so rebuilds the amalgamation
 * Calls minification classes to reduce size of css and js file saving bandwidth
 * Can creates a .gz file, be would require .htaccess or the like to use
 */
class Site_Combiner
{
	/**
	 * Holds all the files contents that we have joined in to one
	 *
	 * @var array
	 */
	private $_combine_files = array();

	/**
	 * Holds the file name of our newly created file
	 *
	 * @var string
	 */
	private $_archive_name = null;

	/**
	 * Holds the file names of the files in the compilation
	 *
	 * @var string
	 */
	private $_archive_filenames = null;

	/**
	 * Holds the comment line to add at the start of the compressed compilation
	 *
	 * @var string
	 */
	private $_archive_header = null;

	/**
	 * Holds the file data of the combined files
	 *
	 * @var string
	 */
	private $_cache = null;

	/**
	 * Holds the minified data of the combined files
	 *
	 * @var string
	 */
	private $_minified_cache = null;

	/**
	 * The directory where we will save the combined and packed files
	 *
	 * @var string
	 */
	private $_archive_dir = null;

	/**
	 * The url where we will save the combined and packed files
	 *
	 * @var string
	 */
	private $_archive_url = null;

	/**
	 * The stale parameter added to the url
	 *
	 * @var string
	 */
	private $_archive_stale = '';

	/**
	 * All the cache-stale params added to the file urls
	 *
	 * @var string[]
	 */
	private $_stales = array();

	/**
	 * Nothing much to do but start
	 *
	 * @param string $cachedir
	 * @param string $cacheurl
	 */
	public function __construct($cachedir, $cacheurl)
	{
		// Init
		$this->_archive_dir = $cachedir;
		$this->_archive_url = $cacheurl;
	}

	/**
	 * Combine javascript files in to a single file to save requests
	 *
	 * @param mixed[] $files -- array created by loadjavascriptfile function
	 * @param bool $do_defered
	 */
	public function site_js_combine($files, $do_defered)
	{
		// No files or missing or not writable directory then we are done
		if (empty($files) || !file_exists($this->_archive_dir) || !is_writable($this->_archive_dir))
			return false;

		// Get the filenames and last modified time for this batch
		foreach ($files as $id => $file)
		{
			// Get the ones that we would load locally so we can merge them
			if (!empty($file['options']['local']) && (!$do_defered && empty($file['options']['defer'])) || ($do_defered && !empty($file['options']['defer'])))
				$this->_addFile($file['options']);
			// One off's get output now
			elseif ((!$do_defered && empty($file['options']['defer'])) || ($do_defered && !empty($file['options']['defer'])))
				echo '
	<script src="', $file['filename'], '" id="', $id,'"' , !empty($file['options']['async']) ? ' async="async"' : '' ,'></script>';
		}

		// Nothing to do, then we are done
		if (count($this->_combine_files) === 0)
			return;

		// Create the archive name
		$this->_buildName('.js');

		// No file, or a stale one, so we create a new compilation
		if ($this->_isStale())
		{
			// Our buddies will be needed for this to work.
			require_once(EXTDIR . '/jsminplus.php');
			require_once(SUBSDIR . '/Package.subs.php');

			$this->_archive_header = '// ' . $this->_archive_filenames . "\n";
			$this->_combineFiles('js');

			// Minify these files to save space,
			$this->_minified_cache = $this->_jsCompiler();

			// And save them for future users
			$this->_saveFiles();
		}

		// Return the name for inclusion in the output
		return $this->_archive_url . '/' . $this->_archive_name . $this->_archive_stale;
	}

	/**
	 * Combine css files in to a single file
	 *
	 * @param string[] $files
	 */
	public function site_css_combine($files)
	{
		// No files or missing dir then we are done
		if (empty($files) || !file_exists($this->_archive_dir))
			return false;

		// Get the filenames and last modified time for this batch
		foreach ($files as $id => $file)
		{
			// Get the ones that we would load locally so we can merge them
			if (!empty($file['options']['local']))
				$this->_addFile($file['options']);
		}

		// Nothing to do so return
		if (count($this->_combine_files) === 0)
			return;

		// Create the css archive name
		$this->_buildName('.css');

		// No file, or a stale one, so we create a new css compilation
		if ($this->_isStale())
		{
			$this->_archive_header = '/* ' . $this->_archive_filenames . " */\n";
			$this->_combineFiles('css');

			// CSSmin it to save some space
			require_once(EXTDIR . '/cssmin.php');
			$compressor = new CSSmin($this->_cache);
			$this->_minified_cache = $compressor->run($this->_cache);

			$this->_saveFiles();
		}

		// Return the name
		return $this->_archive_url . '/' . $this->_archive_name . $this->_archive_stale;
	}

	/**
	 * Just add all the file parameters to the $_combine_files array
	 * If the file has a 'stale' option defined it will be added to the
	 * $_stales array as well to be used later
	 *
	 * @param string[] $options An array with all the file options:
	 *			- dir
	 *			- basename
	 *			- file
	 *			- url
	 *			- stale (optional)
	 */
	private function _addFile($options)
	{
		$filename = $options['dir'] . $options['basename'];
		$this->_combine_files[$options['basename']] = array(
			'file' => $filename,
			'basename' => $options['basename'],
			'url' => $options['url'],
			'filemtime' => filemtime($filename),
		);

		$this->_stales[] = $this->_combine_files[$options['basename']]['filemtime'];
	}

	/**
	 * Determines if the existing combined file is stale
	 * If any date of the files that make up the archive are newer than the archive, its considered stale
	 */
	private function _isStale()
	{
		// If any files in the archive are newer than the archive file itself, then the archive is stale
		$filemtime = file_exists($this->_archive_dir . '/' . $this->_archive_name) ? filemtime($this->_archive_dir . '/' . $this->_archive_name) : 0;

		foreach ($this->_combine_files as $file)
		{
			if ($file['filemtime'] > $filemtime)
				return true;
		}
		return false;
	}

	/**
	 * Creates a new archive name
	 *
	 * @param string $type - should be one of '.js' or '.css'
	 */
	private function _buildName($type)
	{
		global $settings;

		// Create this groups archive name
		foreach ($this->_combine_files as $file)
			$this->_archive_filenames .= $file['basename'] . ' ';

		// Add in the actual theme url to make the sha1 unique to this hive
		$this->_archive_filenames = $settings['actual_theme_url'] . '/' . trim($this->_archive_filenames);

		// Save the hive, or a nest, or a conglomeration. Like it was grown
		$this->_archive_name = 'hive-' . sha1($this->_archive_filenames) . $type;

		if (!empty($this->_stales))
			$this->_archive_stale = '?' . hash('crc32', implode(' ', $this->_stales));
	}

	/**
	 * Combines files into a single compilation
	 * @param string $type one of css or js
	 */
	private function _combineFiles($type)
	{
		$i = '';

		// Remove any old cache file(s)
		@unlink($this->_archive_dir . '/' . $this->_archive_name);
		@unlink($this->_archive_dir . '/' . $this->_archive_name . '.gz');

		// Now build the new compilation
		foreach ($this->_combine_files as $key => $file)
		{
			$tempfile = file_get_contents($file['file']);
			$this->_combine_files[$key]['content'] = $tempfile;

			// CSS needs relative locations converted for the moved hive to work
			if ($type === 'css')
			{
				$tempfile = str_replace(array('../../images', '../images'), $file['url'] . '/images', $tempfile);
				$tempfile = str_replace(array('../../webfonts', '../webfonts'), $file['url'] . '/webfonts', $tempfile);
			}

			$this->_cache .= $i . $tempfile;
			$i = "\n";
		}
	}

	/**
	 * Save a compilation as both a text and optionally a compressed .gz file
	 */
	private function _saveFiles()
	{
		// Add in the file header if available
		if (!empty($this->_archive_header))
			$this->_minified_cache = $this->_archive_header . $this->_minified_cache;

		// First the plain text version
		file_put_contents($this->_archive_dir . '/' . $this->_archive_name, $this->_minified_cache, LOCK_EX);

		// And now the compressed version, just uncomment the below
		/*
		$fp = gzopen($this->_archive_dir . $this->_archive_name . '.gz', 'w9');
		gzwrite ($fp, $this->_minified_cache);
		gzclose($fp);
		*/
	}

	/**
	 * Takes a js file and compresses it to save space, will try several methods to
	 * minimize the code
	 *
	 * 1) Attempt to use the closure-compiler API using code_url
	 * 2) Failing that will use jsminplus
	 * 3) Failing that it will use the closure-compiler API using js_code
	 *		a) single block if it can or b) as multiple calls
	 * 4) Failing that will return original uncompressed file
	 */
	private function _jsCompiler()
	{
		global $context;

		$post_data = '';

		// Details of the closure request
		$url = 'http://closure-compiler.appspot.com/compile';
		$post_header = 'output_info=compiled_code&output_format=text&compilation_level=SIMPLE_OPTIMIZATIONS';

		// Build the closure request using code_url param, this allows us to do a single request
		foreach ($this->_combine_files as $file)
			$post_data .= '&code_url=' . urlencode($file['url'] . '/scripts/' . $file['basename']);
		$fetch_data = fetch_web_data($url, $post_header . $post_data);

		// Nothing returned or an error then we try our internal minimizer
		if ($fetch_data === false || trim($fetch_data) == '' || preg_match('/^Error\(\d{1,2}\):\s/m', $fetch_data))
		{
			// To prevent a stack overflow segmentation fault, which silently kills Apache, we need to limit
			// recursion on windows.  This may cause jsminplus to fail, but at least its then catchable.
			if ($context['server']['is_windows'])
				@ini_set("pcre.recursion_limit", "524");

			$fetch_data = JSMinPlus::minify($this->_cache);
		}

		// If we still have no data, then lets try the post js_code method
		if ($fetch_data === false || trim($fetch_data) == '')
		{
			// As long as we are below 200000 in post data size we can do this in one request
			if (Util::strlen(urlencode($post_header . $this->_cache)) <= 200000)
			{
				$post_data = '&js_code=' . urlencode($this->_cache);
				$fetch_data = fetch_web_data($url, $post_header . $post_data);
			}
			else
			{
				// Simply to much data for a single post so break it down in to as few as possible
				$combine_files = array_values($this->_combine_files);
				for ($i = 0, $filecount = count($combine_files); $i < $filecount; $i++)
				{
					$post_len = 0;
					$post_data = '';
					$post_data_raw = '';

					// Combine data in to chunks of < 200k to minimize http posts
					while($i < $filecount)
					{
						// Get the details for this file
						$file = $combine_files[$i];
						$data = urlencode($file['content']);
						$data_len = Util::strlen($data);

						// If we can add it in, do so
						if ($data_len + $post_len < 200000)
						{
							$post_data .= $data;
							$post_data_raw .= $file['content'];
							$post_len = $data_len + $post_len;
							$i++;
						}
						// No more room in this request, so back up and make this request
						else
						{
							$i--;
							break;
						}
					}

					// Send it off and get the results
					$post_data = '&js_code=' . $post_data;
					$data = fetch_web_data($url, $post_header . $post_data);

					// Use the results or the raw data
					$fetch_data .= ($data === false || trim($data) == '' || preg_match('/^Error\(\d{1,2}\):\s/m', $data)) ? $post_data_raw : $data;
				}
			}
		}

		return $fetch_data === false ? $this->_cache : $fetch_data;
	}
}