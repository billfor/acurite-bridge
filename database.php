<?php

function createDB($file)
{
  	try
  	{
	    $file_db = new PDO("sqlite:$file");
	    $file_db->setAttribute(PDO::ATTR_ERRMODE, 
	                            PDO::ERRMODE_EXCEPTION);
	    $file_db->exec("CREATE TABLE IF NOT EXISTS weather (
	                    station INTEGER , 
	                    metric TEXT, 
	                    value INTEGER,
	                    time INTEGER)");
	    logToFile("created new database ".$file,true);
    }
    catch(PDOException $e) 
    {
	  	logToFile("create error ".$e->getMessage(),true);
	}
	$file_db=null;
}

function openDB($file)
{
	if (!file_exists("$file")) createDB($file);

 	try
  	{
	    $file_db = new PDO("sqlite:$file");
	    $file_db->setAttribute(PDO::ATTR_ERRMODE, 
	                            PDO::ERRMODE_EXCEPTION);
    }
    catch(PDOException $e) 
    {
	  	logToFile("open error ".$e->getMessage(),true);
	}
	return ($file_db);
}

function data_getval($parm)
{
	$fh = fopen("weather.dat", 'r');
	if ($fh)
	{
		while (($buffer = fgets($fh)) !== false) 
		{
			$buffer = trim(preg_replace('/\s\s+/', ' ', $buffer));
			$parts=explode(' ',$buffer);
			if ("$parts[0]|$parts[1]"==="$parm") return $parts[2];
		}
		fclose($fh);
	}
	else
	{
		logToFile("datagetval: cannot open weatherdatfile ",true);
	}
	logToFile("datagetval: unable to get for: ".$parm,true);
	return(false);
}

function data_getval2($station,$metric)
{
	// added to DB externally via rtl_433
    $file_db=openDB("weather.db3");
    try
    {
        $select = "select value from weather where metric=\"".$metric."\"  and
                            station=\"".$station."\"  order by time desc limit 1";
        $stmt = $file_db->query($select);
        $row =$stmt->fetchObject();
        if (!$row) return false;
        else return $row->value;
    }
    catch(PDOException $e)
    {
        echo("getval2 error ".$e->getMessage());
    }
	return false;
}

function data_getuv()
{
	// added to DB externally via rtl_433
    $file_db=openDB("weather.db3");
    try
    {
        $select = "select value from weather where metric=\"uv\"  and
                            station=\"UV800\" and
                            time < ".(time()-1200)." order by time desc limit 1";
        $stmt = $file_db->query($select);
        $row =$stmt->fetchObject();
        if (!$row) return false;
        else return $row->value;
    }
    catch(PDOException $e)
    {
        echo("getuv error ".$e->getMessage());
    }
	return false;
}

function data_getrain()
{
    $file_db=openDB("weather.db3");
    try
    {
        $select = "select value from weather where metric=\"dailyrainin\"  and
                            station=\"5N1x31\" and
                            time < ".(time()-1500)." order by time desc limit 1";
        $stmt = $file_db->query($select);
        $row =$stmt->fetchObject();
        if (!$row) return false;
        else return $row->value;
    }
    catch(PDOException $e)
    {
        echo("getrain error ".$e->getMessage());
    }
	return false;
}


function data_save($querystring,$keep,$sample,$history)
{

	parse_str($querystring,$qarray);
	
	$array = array();
	$tarray = array();
	$sampleOK=false;
    $station=$qarray["mt"];
    $sensor=$qarray["sensor"];
    if ($station === "tower") $station=$station."-".$sensor;

    $time=time();

	$fh = fopen("weather.dat", 'r');
	if ($fh)
	{
		while (($buffer = fgets($fh)) !== false) 
		{
			$buffer = trim(preg_replace('/\s\s+/', ' ', $buffer));
			$parts=explode(' ',$buffer);
			$array["$parts[0]|$parts[1]"]=$parts[2];
			if (isset($parts[3])) $tarray["$parts[0]|$parts[1]"]=$parts[3];
		}
		fclose($fh);
	}
	foreach ($qarray as $key => $value)
    {
      $array["$station|$key"]=$value;
      $tarray["$station|$key"]=$time;
    }
/*
    if (!isset($qarray['windgustmph'])) // no longer being sent
    {
    	unset($array['5N1x38|windgustmph']);
    	unset($array['5N1x31|windgustmph']);
    	unset($array['5N1x38|windgustdir']);
    	unset($array['5N1x31|windgustdir']); // this one never seems to exist anyway, but just in case
    }
 */

    $fh=fopen("weather.dat.tmp","w");
    foreach ( $array as $key => $value)
    {
      if ($key==='ETIME|xxx')
      { 
      	if ( ($time-$value) > $sample) 
      	{
      		$value=time();
      		$sampleOK=true;
      		fwrite($fh,"ETIME xxx ".$time."\n");
      	}
      	else
      		fwrite($fh,"ETIME xxx ".$value."\n");
      	continue;
      }
	  $parts=explode('|',$key);
	  if (($time-$tarray[$key]) < 600) fwrite($fh,"$parts[0] $parts[1] $value $tarray[$key]\n");
    }
    if (!isset($array['ETIME|xxx'])) 
    {
 		fwrite($fh,"ETIME xxx ".$time."\n");
 	}
    fclose($fh);
    rename("weather.dat.tmp","weather.dat");

    
    if ($keep && $sampleOK)
    {
    	try
		{
	    	$file_db=openDB("weather.db3");

		    $insert = "INSERT INTO weather (station, metric, value, time) 
		                VALUES (:station, :metric, :value, :time)";
		    $stmt = $file_db->prepare($insert);

		  	$stmt->bindParam(':station', $station);
		    $stmt->bindParam(':metric', $metric);
		    $stmt->bindParam(':value', $mvalue);
		    $stmt->bindParam(':time', $time);

		    foreach ( $array as $key => $value) //NOT qarray because its incomplete given sampling
		    {
		      if ($key === "ETIME|xxx"
		      	|| strpos($key,"|id")
		        || strpos($key,"|dateutc")
		        || strpos($key,"|action")
		      	|| strpos($key,"|realtime")
		      	|| strpos($key,"|sensor")
		        || strpos($key,"|battery")   			      	
		      	|| strpos($key,"|rssi")
		        || strpos($key,"|rtfreq")
		      	|| strpos($key,"|ID")
		        || strpos($key,"|PASSWORD")
		        || strpos($key,"|mt")
		        || strpos($key,"|probe")
		      	|| strpos($key,"|check")) continue;
		      $parts=explode('|',$key);
		  	  $station=$parts[0];
		      $metric=$parts[1];
		      $mvalue=$value;
		      //$time=date("Y/m/d H:i:s", time());
		      $stmt->execute();
	    	}

	    	// try to execute once a day after 1st sample assuming acurite posts
	    	// at least once every 42 seconds

	    	if (time()%86400 < 43)
	    	{
				$seconds=$history*86400;
	            $delete = "DELETE FROM weather WHERE TIME < ".(time()-$seconds);
	            logToFile("Purging data",true);
	        	$stmt = $file_db->query($delete);
	        }
		}

    	catch(PDOException $e) 
		{
	  		logToFile("db error ".$e->getMessage(),true);
		}
	}
	$file_db=null;
	return;
}

?>
