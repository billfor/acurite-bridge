<?php
function logToFile($msg,$override=false)
{ 
	global $FILENAME,$LOGGING;
	if (!$LOGGING && !$override) return;
	$str = "[" . date("Y/m/d H:i:s", time()) . "] " . $msg . PHP_EOL;	
	//file_put_contents($FILENAME, $str, FILE_APPEND );
	file_put_contents("logfile.txt", $str, FILE_APPEND );
}
?>
