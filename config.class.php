<?php

	// Config Class
	class Config
	{
		// Protected Fields
		protected $_audioQualities = array(64, 128, 320);

		// Constants
		const _TEMPVIDDIR = 'videos/';
		const _SONGFILEDIR = 'mp3/';
		const _FFMPEG = 'ffmpeg.exe'; //Path to ffmpeg executable. If you using it on Linux enter the full path.
		const _LOGSDIR = 'logs/';
		const _VOLUME = '256';  // 256 is normal, 512 is  1.5x louder, 768 is 2x louder, 1024 is 2.5x louder
		const _ENABLE_CONCURRENCY_CONTROL = true;  // Set value to 'true' to prevent possible errors when two users simultaneously download & convert the same video. Note: Enabling this feature will use up more server disk space.
	}

?>
