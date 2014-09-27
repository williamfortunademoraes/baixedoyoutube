<?php

	// Conversion Class
	include 'config.class.php';
	class YouTubeToMp3Converter extends Config
	{
		// Private Fields
		private $_songFileName = '';
		private $_flvUrls = array();
		private $_tempVidFileName;
		private $_uniqueID = '';
		private $_vidSrcTypes = array('source_code', 'url');
		private $_percentVidDownloaded = 0;
		private $_ytCypherUsed = false;

		#region Public Methods
		function __construct()
		{
			$this->_uniqueID = time() . "_" . uniqid('', true);
		}

		function DownloadVideo($youTubeUrl)
		{
			$file_contents = file_get_contents($youTubeUrl);
			if ($file_contents !== false)
			{
				$this->SetSongFileName($file_contents);
				$this->SetFlvUrls($file_contents, $youTubeUrl);
				if ($this->GetSongFileName() != '' && count($this->GetFlvUrls()) > 0)
				{
					return $this->SaveVideo($this->GetFlvUrls());
				}
			}
			return false;
		}

		function GenerateMP3($audioQuality)
		{
			$qualities = $this->GetAudioQualities();
			$quality = (in_array($audioQuality, $qualities)) ? $audioQuality : $qualities[1];
			$exec_string = parent::_FFMPEG.' -i '.$this->GetTempVidFileName().' -vol '.parent::_VOLUME.' -y -acodec libmp3lame -ab '.$quality.'k '.$this->GetSongFileName() . ' 2> logs/' . $this->_uniqueID . '.txt';
			$ffmpegExecUrl = preg_replace('/(([^\/]+?)(\.php))$/', "exec_ffmpeg.php", "http://".$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']);
			$postData = "cmd=".urlencode($exec_string);
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $ffmpegExecUrl);
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_TIMEOUT, 1);
			curl_exec($ch);
			curl_close($ch);
		}

		function DownloadMP3($file)
		{
			$filepath = parent::_SONGFILEDIR . urldecode($file);
			$filename = urldecode($file);
			if (parent::_ENABLE_CONCURRENCY_CONTROL)
			{
				$filename = preg_replace('/((_uuid-)(\w{13})(\.mp3))$/', "$4", $filename);
			}
			if (is_file($filepath))
			{
				header('Content-Type: audio/mpeg3');
				header('Content-Length: ' . filesize($filepath));
				header('Content-Disposition: attachment; filename="'.$filename.'"');
				ob_clean();
				flush();
				readfile($filepath);
				die();
			}
			else
			{
				$redirect = explode("?", $_SERVER['REQUEST_URI']);
				header('Location: ' . $redirect[0]);
			}
		}

		function ExtractSongTrackName($vidSrc, $srcType)
		{
			$name = '';
			$vidSrcTypes = $this->GetVidSrcTypes();
			if (in_array($srcType, $vidSrcTypes))
			{
				$vidSrc = ($srcType == $vidSrcTypes[1]) ? file_get_contents($vidSrc) : $vidSrc;
				if ($vidSrc !== false)
				{
					if (preg_match('/(<title>)(.+?)( - YouTube)(<\/title>)/', $vidSrc, $matches) == 1)
					{
						$name = trim($matches[2]);
						$name = iconv("UTF-8", "ISO-8859-1//TRANSLIT", $name);
						$name = (!empty($name)) ? html_entity_decode($name) : 'unknown_'.time();
					}
				}
			}
			return $name;
		}

		function ExtractVideoId($youTubeUrl)
		{
			$v = '';
			$urlQueryStr = parse_url(trim($youTubeUrl), PHP_URL_QUERY);
			if ($urlQueryStr !== false && !empty($urlQueryStr))
			{
				parse_str($urlQueryStr);
			}
			return $v;
		}

		function UpdateVideoDownloadProgress($downloadSize, $downloaded, $uploadSize, $uploaded)
		{
			$percent = round($downloaded/$downloadSize, 2) * 100;
			if ($percent > $this->_percentVidDownloaded)
			{
				$this->_percentVidDownloaded++;
				echo '<script type="text/javascript">updateVideoDownloadProgress("'. $percent .'");</script>';
				ob_end_flush();
				ob_flush();
				flush();
			}
		}
		#endregion

		#region Private "Helper" Methods
		private function SaveVideo(array $urls)
		{
			$success = false;
			$vidCount = -1;
			while (!$success && ++$vidCount < count($urls))
			{
				$this->_percentVidDownloaded = 0;
				$this->SetTempVidFileName();
				$file = fopen($this->GetTempVidFileName(), 'w');
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_FILE, $file);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_URL, $urls[$vidCount]);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				curl_setopt($ch, CURLOPT_NOPROGRESS, false);
				curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, array($this, 'UpdateVideoDownloadProgress'));
				curl_setopt($ch, CURLOPT_BUFFERSIZE, 4096000);
				curl_exec($ch);
				if (curl_errno($ch) == 0)
				{
					$responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					if ($this->_ytCypherUsed && $responseCode == '403')
					{
						$this->FixYouTubeDecryption();
					}
				}
				curl_close($ch);
				fclose($file);
				if (is_file($this->GetTempVidFileName()))
				{
					if (!filesize($this->GetTempVidFileName()) || filesize($this->GetTempVidFileName()) < 10000)
					{
						unlink($this->GetTempVidFileName());
					}
					else
					{
						$success = true;
					}
				}
			}
			return $success;
		}

		private function FixYouTubeDecryption()
		{
			if (is_file('software.xml'))
			{
				$softwareInfo = file_get_contents('software.xml');
				$sxe = new SimpleXMLElement($softwareInfo);
				$info = $sxe->xpath('/software/info');
				if ($info !== false && !empty($info))
				{
					$lastError = (int)$info[0]->lasterror;
					$currentTime = time();
					if ($currentTime - $lastError > 3600)
					{
						$version = $info[0]->version;
						$updateResponse = file_get_contents('http://rajwebconsulting.com/update-video-converter-v2/v:'.$version);
						if ($updateResponse != "You have the newest version.")
						{
							$sxe2 = new SimpleXMLElement($updateResponse);
							$sxe2->info[0]->lasterror = $currentTime;
							$newXmlContent = $sxe2->asXML();
						}
						else
						{
							$sxe->info[0]->lasterror = $currentTime;
							$newXmlContent = $sxe->asXML();
						}
						$fp = fopen('software2.xml', 'w');
						$lockSucceeded = false;
						if (flock($fp, LOCK_EX))
						{
							$lockSucceeded = true;
							fwrite($fp, $newXmlContent);
							flock($fp, LOCK_UN);
						}
						fclose($fp);
						if ($lockSucceeded)
						{
							rename("software2.xml", "software.xml");
							chmod("software.xml", 0777);
						}
					}
				}
				else
				{
					unlink('software.xml');
				}
			}
			else
			{
				$updateResponse = file_get_contents('http://rajwebconsulting.com/update-video-converter-v2/v:0');
				$sxe3 = new SimpleXMLElement($updateResponse);
				$sxe3->info[0]->lasterror = time();
				$fp = fopen('software.xml', 'w');
				$lockSucceeded = false;
				if (flock($fp, LOCK_EX))
				{
					$lockSucceeded = true;
					fwrite($fp, $sxe3->asXML());
					flock($fp, LOCK_UN);
				}
				fclose($fp);
				if ($lockSucceeded)
				{
					chmod("software.xml", 0777);
				}
			}
		}

        private function DecryptYouTubeCypher($signature)
        {
			$s = $signature;
			if (is_file('software.xml'))
			{
				$algos = file_get_contents('software.xml');
				$sxe = new SimpleXMLElement($algos);
				$algo = $sxe->xpath('/software/decryption/funcgroup[@siglength="' . strlen($s) . '"]/func');
				if ($algo !== false && !empty($algo))
				{
					//die(print_r($algo));
					foreach ($algo as $func)
					{
						$funcName = (string)$func->name;
						if (!function_exists($funcName))
						{
							eval('function ' . $funcName . '(' . (string)$func->args . '){' . preg_replace('/self::/', "", (string)$func->code) . '}');
						}
					}
					$s = call_user_func((string)$algo[0]->name, $s);
				}
			}
			$s = ($s == $signature) ? $this->LegacyDecryptYouTubeCypher($s) : $s;
			return $s;
		}

        // Deprecated - Will be removed in future versions!
        private function LegacyDecryptYouTubeCypher($signature)
        {
            $s = $signature;
            $sigLength = strlen($s);
            switch ($sigLength)
            {
                case 93:
                	$s = strrev(substr($s, 30, 57)) . substr($s, 88, 1) . strrev(substr($s, 6, 23));
                	break;
                case 92:
                    $s = substr($s, 25, 1) . substr($s, 3, 22) . substr($s, 0, 1) . substr($s, 26, 16) . substr($s, 79, 1) . substr($s, 43, 36) . substr($s, 91, 1) . substr($s, 80, 3);
                    break;
                case 90:
                	$s = substr($s, 25, 1) . substr($s, 3, 22) . substr($s, 2, 1) . substr($s, 26, 14) . substr($s, 77, 1) . substr($s, 41, 36) . substr($s, 89, 1) . substr($s, 78, 3);
                	break;
                case 89:
                	$s = strrev(substr($s, 79, 6)) . substr($s, 87, 1) . strrev(substr($s, 61, 17)) . substr($s, 0, 1) . strrev(substr($s, 4, 56));
                	break;
                case 88:
                    $s = substr($s, 7, 21) . substr($s, 87, 1) . substr($s, 29, 16) . substr($s, 55, 1) . substr($s, 46, 9) . substr($s, 2, 1) . substr($s, 56, 31) . substr($s, 28, 1);
                    break;
                case 87:
                	$s = substr($s, 6, 21) . substr($s, 4, 1) . substr($s, 28, 11) . substr($s, 27, 1) . substr($s, 40, 19) . substr($s, 2, 1) . substr($s, 60);
                    break;
                case 84:
                    $s = strrev(substr($s, 71, 8)) . substr($s, 14, 1) . strrev(substr($s, 38, 32)) . substr($s, 70, 1) . strrev(substr($s, 15, 22)) . substr($s, 80, 1) . strrev(substr($s, 0, 14));
                    break;
                case 81:
					$s = substr($s, 56, 1) . strrev(substr($s, 57, 23)) . substr($s, 41, 1) . strrev(substr($s, 42, 14)) . substr($s, 80, 1) . strrev(substr($s, 35, 6)) . substr($s, 0, 1) . strrev(substr($s, 30, 4)) . substr($s, 34, 1) . strrev(substr($s, 10, 19)) . substr($s, 29, 1) . strrev(substr($s, 1, 8)) . substr($s, 9, 1);
                    break;
                case 80:
					$s = substr($s, 1, 18) . substr($s, 0, 1) . substr($s, 20, 48) . substr($s, 19, 1) . substr($s, 69, 11);
                    break;
                case 79:
					$s = substr($s, 54, 1) . strrev(substr($s, 55, 23)) . substr($s, 39, 1) . strrev(substr($s, 40, 14)) . substr($s, 78, 1) . strrev(substr($s, 35, 4)) . substr($s, 0, 1) . strrev(substr($s, 30, 4)) . substr($s, 34, 1) . strrev(substr($s, 10, 19)) . substr($s, 29, 1) . strrev(substr($s, 1, 8)) . substr($s, 9, 1);
                	break;
                default:
                    $s = $signature;
            }
            return $s;
        }
		#endregion

		#region Properties
		public function GetSongFileName()
		{
			return $this->_songFileName;
		}
		private function SetSongFileName($file_contents)
		{
			$vidSrcTypes = $this->GetVidSrcTypes();
			$trackName = $this->ExtractSongTrackName($file_contents, $vidSrcTypes[0]);
			if (!empty($trackName))
			{
				$fname = parent::_SONGFILEDIR . preg_replace('/_{2,}/','_',preg_replace('/ /','_',preg_replace('/[^A-Za-z0-9 _-]/','',$trackName)));
				$fname .= (parent::_ENABLE_CONCURRENCY_CONTROL) ? uniqid('_uuid-') : '';
				$this->_songFileName = $fname . '.mp3';
			}
		}

		public function GetFlvUrls()
		{
			return $this->_flvUrls;
		}
		private function SetFlvUrls($file_contents, $youTubeUrl)
		{
			$vidUrls = array();
			$vidSrcTypes = $this->GetVidSrcTypes();
			$vidInfoTypes = array('&el=embedded', '&el=detailpage', '&el=vevo', '');
			$vidId = $this->ExtractVideoId($youTubeUrl);
			foreach ($vidInfoTypes as $infotype)
			{
				$content = file_get_contents('https://www.youtube.com/get_video_info?&video_id='.$vidId.$infotype.'&ps=default&eurl=&gl=US&hl=en');
				parse_str($content, $output);
				if (isset($output['status']) && $output['status'] == 'ok')
				{
					//die(print_r($output));
					$this->_ytCypherUsed = isset($output['use_cipher_signature']) && $output['use_cipher_signature'] == 'True';
					break;
				}
			}
			if (preg_match('/;ytplayer\.config = ({.*?});/s', $file_contents, $matches) == 1)
			{
				$jsonObj = json_decode($matches[1]);
				if (isset($jsonObj->args->url_encoded_fmt_stream_map))
				{
					$urls = urldecode(urldecode($jsonObj->args->url_encoded_fmt_stream_map));
					//$urls = urldecode(urldecode($jsonObj->args->adaptive_fmts));
					//die($urls);
					if (preg_match('/^((.+?)(=))/', $urls, $matches) == 1)
					{
						$urlsArr = preg_split('/,'.preg_quote($matches[0], '/').'/', $urls, -1, PREG_SPLIT_NO_EMPTY);
						foreach ($urlsArr as $url)
						{
							if ($matches[0] != 'url=')
							{
								$url = ($url != $urlsArr[0]) ? $matches[0].$url : $url;
								$urlBase = preg_replace('/(.+?)(url=)(.+?)(\?)(.+)/', "$3$4", $url);
								$urlParams = preg_replace('/(.+?)(url=)(.+?)(\?)(.+)/', "$1$5", $url);
								$url = $urlBase . "&" . $urlParams;
							}
							else
							{
								$url = preg_replace('/^(url=)/', "", $url);
							}
							$url = preg_replace('/(.*)(itag=\d+&)(.*?)/', '$1$3', $url, 1);
							if (preg_match('/quality=small/', $url) != 1)
							{
								$url = preg_replace('/&sig=|&s=/', "&signature=", $url);
								$url = trim($url, ',');
								$url .= '&title=' . urlencode($this->ExtractSongTrackName($file_contents, $vidSrcTypes[0]));
								$url = preg_replace_callback('/(&type=)(.+?)(&)/', function($match){return $match[1].urlencode($match[2]).$match[3];}, $url);
								if ($this->_ytCypherUsed)
								{
									$urlParts = parse_url($url);
									parse_str($urlParts['query'], $vars);
									$vars['signature'] = $this->DecryptYouTubeCypher($vars['signature']);
									$queryStr = http_build_query($vars, '', '&');
									$url = $urlParts['scheme'] . '://' . $urlParts['host'] . $urlParts['path'] . '?' . $queryStr;
								}
								$vidUrls[] = $url;
							}
						}
						$vidUrls = array_reverse($vidUrls);
						//die(print_r($vidUrls));
					}
				}
			}
			$this->_flvUrls = $vidUrls;
		}

		public function GetAudioQualities()
		{
			return $this->_audioQualities;
		}

		private function GetTempVidFileName()
		{
			return $this->_tempVidFileName;
		}
		private function SetTempVidFileName()
		{
			$this->_tempVidFileName = parent::_TEMPVIDDIR . $this->_uniqueID .'.flv';
		}

		public function GetVidSrcTypes()
		{
			return $this->_vidSrcTypes;
		}

		public function GetUniqueID()
		{
			return $this->_uniqueID;
		}
		#endregion
	}

?>
