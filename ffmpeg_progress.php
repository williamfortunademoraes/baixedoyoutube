<?php

include 'config.class.php';
$newLogLength = 0;
$progress = 0;
$conversionSuccess = 2;
$error = 2;

if (isset($_POST['uniqueId']) && isset($_POST['logLength']) && isset($_POST['mp3File']))
{
	$uniqueId = $_POST['uniqueId'];
	$logLength = $_POST['logLength'];
	$mp3File = urldecode($_POST['mp3File']);
	$logFile = realpath(Config::_LOGSDIR . $uniqueId .".txt");
	if (is_file($logFile))
	{
		$count = 0;
		while (filesize($logFile) == $logLength && $count < 500)
		{
			$count++;
			clearstatcache();
			time_nanosleep(0, 10000000);
		}
		$log = file_get_contents($logFile);
		$file_size = filesize($logFile);
		if (preg_match('/(Duration: )(\d\d):(\d\d):(\d\d\.\d\d)/i', $log, $matches) == 1)
		{
			$totalTime = ((int)$matches[2] * 60 * 60) + ((int)$matches[3] * 60) + (float)$matches[4];
			$numTimes = preg_match_all('/(time=)(.+?)(\s)/i', $log, $times);
			if ($numTimes > 0)
			{
				$lastTime = end($times[2]);
				if (preg_match('/(\d\d):(\d\d):(\d\d\.\d\d)/', $lastTime, $timeParts) == 1)
				{
					$lastTime = ((int)$timeParts[1] * 60 * 60) + ((int)$timeParts[2] * 60) + (float)$timeParts[3];
				}
				$currentTime = (float)$lastTime;
				$progress = round(($currentTime / $totalTime) * 100);
				if ($progress < 100 && preg_match('/muxing overhead/i', $log) != 1)
				{
					$newLogLength = $file_size;
				}
				else
				{
					$progress = 100;
					unlink($logFile);
					if (is_file(realpath(Config::_TEMPVIDDIR . $uniqueId .'.flv')))
					{
						unlink(realpath(Config::_TEMPVIDDIR . $uniqueId .'.flv'));
					}
					if (is_file(realpath(Config::_SONGFILEDIR . $mp3File)))
					{
						$conversionSuccess = 1;
					}
				}
			}
			else
			{
				$error = 1;
			}

		}
		else
		{
			$error = 1;
		}
	}
	else
	{
		$error = 1;
	}
}
echo $newLogLength . "|" . $progress . "|" . $conversionSuccess . "|" . $error;

?>
