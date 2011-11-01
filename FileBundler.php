<?php

/**
 * FileBundler.php - version 1.0
 *
 * Copyright (c) 2008 Aleksandar Kolundzija (subchild.com)
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * 
 *
 * FileBundler is used for concatenating multiple JavaScript or CSS files into 
 * a single, optimized file (bundle). Processing is done in real-time. If a 
 * requested collection of files is already bundled that bundle is used, 
 * otherwise a new one is created and and written to disk for future use.
 * Changes to source code therefore require deleting old, outdated bundles.
 */
 
class FileBundler 
{
		define("ENABLE_HEAD_INCLUDE_BUNDLES", true); // this should go into a config file and be used to dis/en-able bundling
	
		private $type;  // "js" | "css"
		private $files = array();
		private $approot = ""; // root directory of web application
		private $sourceDir = ""
		private $bundleDir = "";
		private $debugMode;
		private $compress;
		private $optimizer; // "jsmin" | "packer"
		
		
		
		/* PRIVATE METHODS ------------------------------------------------ */
		
		private function log($message){
			if ($this->debugMode){ error_log("*** FileBundler ***: $message"); }
		}
		
		private function getBundleWebDir(){
			return $this->bundleDir;
		}
			
		private function getBundleSysDir(){
			return $approot.$this->getBundleWebDir();
		}
	
		/**
		* Method generateBundleFilename() sorts filenames, concatenates them 
		* into a string and hashes it. Resulting string is used as a unique 
		* filename for this set of files.
		*/
		private function generateBundleFilename(){
			$files = $this->files;
			sort($files);
			$generatedFilename = md5(implode(".",$files)) . "." . $this->type;
			return $generatedFilename;
		}
		
		private function compressJavaScriptBundle($code){
			$compressedCode;
			switch ($this->optimizer){
				case "jsmin" :
					require_once 'jsmin-1.1.1.php';
					$compressedCode = JSMin::minify($code);
					break;
				case "packer" :
					require_once 'class.JavaScriptPacker.php';
					$packer = new JavaScriptPacker($code, 'Normal', true, false);
					$compressedCode = $packer->pack();
					break;
				default : 
					$this->log("Unknown JavaScript optimizer was specified");
					break;
			}
			return $compressedCode;
		}
		
		private function compressCSSBundle($css){
			$css = preg_replace('/[\r\n\t\s]+/s', ' ', $css);           // new lines, multiple spaces/tabs/newlines
			$css = preg_replace('#/\*.*?\*/#', '', $css);               // comments
			$css = preg_replace('/[\s]*([\{\},;:])[\s]*/', '\1', $css); // spaces before and after marks
			$css = preg_replace('/^\s+/', '', $css);                    // spaces on the beginning
			return $css;
		}
		
		
		
		/* PUBLIC METHODS ------------------------------------------------- */
		
		/**
		 * Constructs a FileBundler, used for bundling JavaScript and CSS includes 
		 * into a single file. File optimization (minifying) is supported. 
		 *
		 * @param array $props
		 */
		public function __construct($props){
			$this->type      = isset($props['type'])      ? $props['type']      : "js";  // default file type is js (JavaScript)
			$this->debugMode = isset($props['debugMode']) ? $props['debugMode'] : false; // set debugMode to true for useful trace messages
			$this->compress  = isset($props['compress'])  ? $props['compress']  : true;  // when compress is set to false no bundling takes place.  individual files are included
			$this->optimizer = isset($props['optimizer']) ? $props['optimizer'] : "jsmin"; // "jsmin" or "packer"
			$this->approot   = isset($props['approot'])   ? $props['approot']   : ""; // application root
			$this->sourceDir = isset($props['sourceDir']) ? $props['sourceDir'] : "/{$this->type}"; // source directory, ex: "/scripts"
			$this->bundleDir = isset($props['bundleDir']) ? $props['bundleDir'] : "/{$this->type}/bundles";  // bundle directory 
			$this->showList  = isset($props['showList'])  ? $props['showList']  : true;  // include file list into comment in bundle
			if (isset($props['files'])){
				$this->addFiles($props['files']);
			}
		}
		
		
		/**
		 * Adds a single file to bundler.  This is really a shortcut for the addFiles()
		 * method intended to be used when only a single file is being added.
		 *
		 * @param string $file
		 */
		public function addFile($file){
			$this->addFiles(array($file));
		}
		
		
		/**
		 * Adds files (js or css) to be bundled.
		 *
		 * @param array $files
		 */
		public function addFiles($files){
			$this->log("Adding files: " . implode(",",$files));
			$filesWithRootRelativePaths = array();
			for ($i=0; $i<count($files); $i++){
				if (substr($files[$i],0,1)!="/"){
					$filesWithRootRelativePaths[] = $this->sourceDirs[$this->type].$files[$i];
				}
				else {
					$filesWithRootRelativePaths[] = $files[$i];
				}
				
				if (!is_file($approot.$filesWithRootRelativePaths[count($filesWithRootRelativePaths)-1])){
					$this->log($filesWithRootRelativePaths[count($filesWithRootRelativePaths)-1] . " doesn't exist. removing from bundle.");
					array_pop($filesWithRootRelativePaths);
				}
			}
			$this->files = array_merge($this->files, $filesWithRootRelativePaths);
			$this->files = array_unique($this->files);
		}
		
		
		/**
		 * Writes the HTML tag (script or link) for including the bundled file.
		 * If the requested bundle already exists, it is reused.  Otherwise, a new
		 * bundle is created (written to disk).  Generated bundle can be optimized
		 * (compressed).
		 *
		 * @param boolean $compress
		 */
		public function writeBundle($overwrite = false){

			$mtime = microtime();
			$mtime = explode(" ",$mtime);
			$mtime = $mtime[1] + $mtime[0];
			$starttime = $mtime;
			$newBundle = false;
						
			if (ENABLE_HEAD_INCLUDE_BUNDLES){
				
				$bundleName    = $this->generateBundleFilename();
				$bundleSysPath = $this->getBundleSysDir().$bundleName;
				
				if (!is_file($bundleSysPath) || $overwrite){ // if bundle doesn't exist
					$newBundle = true;
					$this->log(">>> CREATING A NEW BUNDLE <<<");

					$fileList = ($this->showList) ? "/*\n" . implode("\n",$this->files) . "\n*/\n" : "";
					$code     = "";

					foreach ($this->files as $file){
						$code .= file_get_contents($approot.$file);
					}
					
					if ($this->compress){
						switch ($this->type){
							case "js" :
								$this->log("Compressing JS bundle...");
								$code = $this->compressJavaScriptBundle($code);
								break;
							case "css" :
								$this->log("Compressing CSS bundle...");
								$code = $this->compressCSSBundle($code);
								break;
							default : 
								$this->log("writeBundle(): Unknown bundle type");
								break;
						}
					}
					file_put_contents($bundleSysPath, $fileList.$thirdPartyCode.$code);
				}
				
				switch ($this->type){
					case "js"  : print "<script src=\"{$this->getBundleWebDir()}{$bundleName}\" type=\"text/javascript\"></script>\n"; break;
					case "css" : print "<link href=\"{$this->getBundleWebDir()}{$bundleName}\" type=\"text/css\" rel=\"stylesheet\"/>\n"; break;
					default    : $this->log("writeBundle(): Unknown bundle type"); break;
				}

			}
			else {
				foreach ($this->files as $file){
					switch ($this->type){
						case "js"  : print "<script src=\"$file\" type=\"text/javascript\"></script>\n"; break;
						case "css" : print "<link href=\"$file\" type=\"text/css\" rel=\"stylesheet\"/>\n"; break;
						default    : $this->log("writeBundle(): Unknown bundle type (not bundling)"); break;
					}
				}
				
			}
				
			$mtime = microtime();
			$mtime = explode(" ",$mtime);
			$mtime = $mtime[1] + $mtime[0];
			$endtime = $mtime;
			$totaltime = ($endtime - $starttime);
			$this->log("writeBundle(): new bundle created in: $totaltime");
		}
		
	}

?>
