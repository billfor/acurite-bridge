<?php 


  require 'resolver.php';
  require 'logger.php';
  require 'database.php';

  use Mosquitto\Client;

  $FILENAME="logfile.txt"; // status log in directory where this script lives
  $ALLOWCONFIG=false; // allow acurite to send its response/update to the bridge. This is ignore after the march update.
  $FAKEVERSION=224; // if ALLOWCONFIG is true, even when sending acurites response, change the version to this.
  $PARANOID=true; // if ALLOWCONFIG, only send time and checkversion to the bridge. 
  // setting the following two parameters to FALSE will disable most logging, but not severe errors
  $LOGRESPONSE=false; // show response from provider and possibly modified response to bridge
  $LOGGING=false; // enable verbose logging
  //
  $DB_TIMESERIES=true; // enable sqlite database to store data
  $DB_HISTORY=31; // days of history to keep when using database. false/0 for infinite
  $DB_SAMPLE=900; // store a sample every X seconds 
  //
  $PUBLISH=true; // publish MQTT
  $RAIN_CORRECTION=true; // fix rainin sawtooth with 15 min average from accumlated.
  $DISABLE_WIND=true;  // do not send wind data when the 5-1 decides to break
  $ENABLE_TOWER=true;  // send tower sensors to mqtt (you have to edit the code below)


  logToFile ("======================");
  $method = $_SERVER['REQUEST_METHOD'];
  logToFile ("method: $method");


  if($method == "GET" || $method == "PUT" || $method == "PATCH" || $method == "POST")
  {
    $headers = getallheaders();
    $headers_str = [];
    
    foreach ( $headers as $key => $value){
      // if($key == 'Host') continue;
      $headers_str[]=$key.":".$value;
      if ($value === "hubapi.myacurite.com") $UPDATE=$value;
      if ($value === "rtupdate.wunderground.com") $UPDATE=$value;
      logToFile($key.":".$value);
    }

    if (!$UPDATE)
    {
      logToFile("No host specified",true);
      exit;
    } 

    data_save($_SERVER["QUERY_STRING"],$DB_TIMESERIES,$DB_SAMPLE,$DB_HISTORY);

    $iplist=real_address($UPDATE);

    foreach ($iplist as $ip)
    {
      $url = "http://$ip" . $_SERVER['REQUEST_URI'];

      if ($UPDATE === "rtupdate.wunderground.com")  
      {
        parse_str($_SERVER["QUERY_STRING"],$qarray);

        // substitute external temp from remote if present
	      // the wunderground post is always with 5x1
        $tempf=data_getval("ProOut|tempf");
        $humidity=data_getval("ProOut|humidity");
        if ($tempf && $humidity) 
        {
          $qarray["tempf"]=($tempf+$qarray["tempf"])/2.0;
         // $qarray["humidity"]=($humidity+$qarray["humidity"])/2.0; 
          $qarray["humidity"]=$humidity; 
        }

        // fix the accumulated rain to be based on 15 minute change in accumulated daily counter
        if ($RAIN_CORRECTION)
        {
          //logToFile("rainin before $qarray[rainin]");
          //logToFile("dailyrainin  $qarray[dailyrainin]");
          $rain=data_getrain(); //get accumulated rain from 15 minutes ago
          if ($rain !== false)
          {
            logToFile("dailyrainin from 15 min ago $rain");
            $qarray["rainin"]=($qarray["dailyrainin"]-$rain)*4;
            if ($qarray["rainin"] < 0 ) $qarray["rainin"] = 0;
          }
          //logToFile("rainin after $qarray[rainin]");
        }

        // unset these until the windspeed indicator is fixed.
        if ($DISABLE_WIND)
        {
          if (isset($qarray["windspeedmph"])) unset($qarray["windspeedmph"]);
          if (isset($qarray["winddir"])) unset($qarray["winddir"]);
        }

        $url = "http://$ip" . "/weatherstation/updateweatherstation.php?" .
                  http_build_query($qarray);

        // get optional soil probe temp and send it
        $soil=data_getval("ProOut|ptempf");
        if ($soil) $url .= "&soiltempf=".$soil;
        $uv=data_getUV();
        if ($uv !== false) 
        {
          //logToFile("got uv $uv");
          $url .= "&UV=".$uv; // add UV from UVN800
        }
        //$url=$url."&leafwetness=25.0&soilmoisture=66.3&UV=0&solarradiation=0";

        if ($PUBLISH)  //for openHab, etc....
        {
          $client = new Client();
          $client->connect('localhost');

          $client->publish("sensors/temp", $qarray["tempf"]);
          $client->publish("sensors/humidity", $qarray["humidity"]);
          $client->publish("sensors/barometer", $qarray["baromin"]);
          if (isset($qarray["windspeedmph"])) $client->publish("sensors/wind", $qarray["windspeedmph"]);
          if (isset($qarray["rainin"])) $client->publish("sensors/rain", $qarray["rainin"]);
          if (isset($qarray["dailyrainin"])) $client->publish("sensors/raintotal", $qarray["dailyrainin"]);
          $client->publish("sensors/5N1battery", (data_getval("5N1x31|battery")=="normal"?0:1));        
          $client->publish("sensors/ProOutbattery", (data_getval("ProOut|battery")=="normal"?0:1));      

          if ($ENABLE_TOWER)
          {
            $client->publish("sensors/BasementBattery", (data_getval("tower-00007524|battery")=="normal"?0:1));        
            $client->publish("sensors/tempf-basement", (data_getval("tower-00007524|tempf")));       //tower hack, add serials here.
            $client->publish("sensors/humidity-basement", (data_getval("tower-00007524|humidity")));       
            $client->publish("sensors/dewpoint-basement", (data_getval("tower-00007524|tempf") -  (100.0-(data_getval("tower-00007524|humidity"))) /5.0)) ;       

            $client->publish("sensors/KegBattery", (data_getval("tower-00002909|battery")=="normal"?0:1));        
            $client->publish("sensors/tempf-keg", (data_getval("tower-00002909|tempf")));       
            $client->publish("sensors/humidity-keg", (data_getval("tower-00002909|humidity")));       


            $client->publish("sensors/garageBattery", (data_getval("tower-00000915|battery")=="normal"?0:1));        
            $client->publish("sensors/garageTempf", (data_getval("tower-00000915|tempf")));       
            $client->publish("sensors/garageHumidity", (data_getval("tower-00000915|humidity")));       
          } 
         //$client->disconnect(); 
        }

        // as of march 1st the only thing we send to is weather underground

        logToFile ("Sending to $url");

        $ch = curl_init($url);

        curl_setopt($ch,CURLOPT_URL, $url);
        if( $method !== 'GET') {
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if($method == "PUT" || $method == "PATCH" || ($method == "POST" && empty($_FILES))) {
          $data_str = file_get_contents('php://input');
          curl_setopt($ch, CURLOPT_POSTFIELDS, $data_str);
          logToFile($method.': '.$data_str.serialize($_POST));
        }
        elseif($method == "POST") {
          $data_str = array();
          if(!empty($_FILES)) {
            foreach ($_FILES as $key => $value) {
              $full_path = realpath( $_FILES[$key]['tmp_name']);
              $data_str[$key] = '@'.$full_path;
            }
          }
          logToFile($method.': '.serialize($data_str+$_POST));

          curl_setopt($ch, CURLOPT_POSTFIELDS, $data_str+$_POST);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers_str );

        $result = curl_exec($ch);

        if ($result === false)
        {
          $err = "Curl error: $UPDATE:$ip: " . curl_error($ch);
          logToFile($err,true);
          curl_close($ch);
        }
        else
        {
          $result = str_replace(PHP_EOL, '', $result);
          curl_close($ch);
          break;
        }
      } 
      else
      {
        logToFile ("Old Hub string $url");
      }
    }

    //logToFile("raw result  $result",$LOGRESPONSE );
    //$json=json_decode($result);
    // as of march 1, there is no json returned from acurite.
/*
    if ($json)
    {
      //header('Content-Type: application/json');
      logToFile("acurite_from: $result",$LOGRESPONSE);

      if ($ALLOWCONFIG)
      {
        if (($FAKEVERSION) && isset($json->checkversion))
        {
          if ($json->checkversion != $FAKEVERSION) 
          {
            logToFile("Warning FIRMWARE update to $json->checkversion.",true);
            $json->checkversion="$FAKEVERSION";
            $result=json_encode($json);
          }
        }
        if ($PARANOID)
        {
	        if (count((array)$json) > 2) logToFile("Warning got more than expected return vars $result",true);
          $ary=array();
          if (isset($json->localtime)) $ary['localtime']=$json->localtime;
          if (isset($json->checkversion)) $ary['checkversion']=$json->checkversion;
          $result=json_encode($ary);
        }
        echo $result;
        logToFile ("acurite_brdg: $result",$LOGRESPONSE);
      }

      else
      {
        logToFile ("acurite: bridge update supressed",$LOGRESPONSE);
      }
    }
    else
    {
      echo $result;
      logToFile ("wunderground: $result",$LOGRESPONSE);
    }
    */

    if ($UPDATE === "rtupdate.wunderground.com")  
    {
      logToFile("Wunderground $ip result is: $result",$LOGRESPONSE);
      echo $result;
    }
    else
    {
      logToFile("Faking acurite response",$LOGRESPONSE);
      header('Content-Type: application/json');
      echo '{"localtime":"04:00:00","checkversion":"224"}';
    }

  }
  else 
  {
    logToFile("Error");
    echo $method;
    var_dump($_POST);
    var_dump($_GET);
    $data_str = file_get_contents('php://input');
    echo $data_str;
    print_r($_SERVER);
    error_log(__FILE__." Invalid request");
  }
?>
