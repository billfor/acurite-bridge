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
  $LOGGING=true; // enable verbose logging
  //
  $DB_TIMESERIES=true; // enable sqlite database to store data
  $DB_HISTORY=31; // days of history to keep when using database. false/0 for infinite
  $DB_SAMPLE=60; // store a sample every X seconds 
  //
  $PUBLISH=true; // publish MQTT
  $RAIN_CORRECTION=true; // fix rainin sawtooth with 15 min average from accumlated.
  $DISABLE_WIND=false;  // do not send wind data when the 5-1 decides to break
  

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
      if ($value === "rtupdate.wunderground.com") logToFile("Bridge directly posted to wunderground.",true);
      logToFile($key.":".$value);
    }

    if($method == "PUT" || $method == "PATCH" || ($method == "POST" && empty($_FILES))) {
      $data_str = file_get_contents('php://input');
      logToFile($method.': '.$data_str);
      $UPDATE = "GW1000";
      $GW1000 = true;
      //logToFile($method.': '.$data_str.serialize($_POST));
      $url = "http://" . $data_str;
      parse_str($data_str,$qarray);
    }
    elseif ($method == "GET") {
      data_save($_SERVER["QUERY_STRING"],$DB_TIMESERIES,$DB_SAMPLE,$DB_HISTORY);
      $url = "http://" . $_SERVER['REQUEST_URI'];
      parse_str($_SERVER["QUERY_STRING"],$qarray);
    }
    else {
      logToFile("No host specified or recognized method.",true);
    }

    if (!isset($UPDATE))
    {
      logToFile("No host specified in message.",true);
      exit;
    } 

    /*

    $iplist=real_address($UPDATE);

    foreach ($iplist as $ip)
    {
      $url = "http://$ip" . $_SERVER['REQUEST_URI'];
      parse_str($_SERVER["QUERY_STRING"],$qarray);

      if ($UPDATE === "rtupdate.wunderground.com")  
      {
        // parse_str($_SERVER["QUERY_STRING"],$qarray);

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

    logToFile ("Hub string $url");

//    $time = time() - 60; // or filemtime($fn), etc
//    header('Date: '.gmdate('D, d M Y H:i:s', $time).' EDT');

    if (! isset($GW1000)) {
      logToFile("Faking acurite response",$LOGRESPONSE);
      header('Content-Type: application/json');
      $timestr = date("H:i:s", time()); 
      echo "{\"localtime\":\"$timestr\",\"checkversion\":\"224\"}";
    }
    // echo "{\"localtime\":"."\"$timestr\",\"checkversion\":\"224\"}";

    //  echo "{\"localtime\": \"$timestr\",\"checkversion\":\"224\",\"ID1\":\"KID\",\"PASSWORD1\":\"YOURPASSWORD\",\"sensor1\":\"YOURSENSORID\",\"elevation\":\"185\"}";
  
    if ($PUBLISH)  //for openHab, etc....
    {
      if (isset($qarray["mt"])) {
        $station=$qarray["mt"];
        if ($station === "tower") $station=$station."-".$qarray["sensor"];
      }
      elseif (isset($qarray["stationtype"])) {
         if (!strpos ( $qarray["stationtype"] , "GW1000")) {
            $station="GW1000";
         }
      }
      else {
        logToFile("No station",true);
        exit;
      }

      logToFile ("Publishing MQTT");

      $client = new Client();
      $client->connect('localhost');

    //  $client->publish("sensors/temp", $qarray["tempf"]);
    //  $client->publish("sensors/temp", data_getval("ProOut|tempf"));    
    // $client->publish("sensors/humidity", $qarray["humidity"]);
    //  $client->publish("sensors/barometer", $qarray["baromin"]);

      switch($station)
      {
        case "GW1000":
          $client->publish("ecowitt/humidity",  $qarray["humidity"]); 
          $client->loop();
          $client->publish("ecowitt/barometer", $qarray["baromabsin"]); // WH32 does not have a baro so we take from the GW1000 attached
          $client->loop();
          $client->publish("ecowitt/temp",  $qarray["tempf"]); 
          $client->loop();
          $client->publish("ecowitt/rain",  $qarray["hourlyrainin"]); 
          $client->loop();
          $client->publish("ecowitt/rainrate",  $qarray["rainratein"]); 
          $client->loop();
          $client->publish("ecowitt/raintotal",  $qarray["dailyrainin"]); 
          $client->loop();
          $client->publish("ecowitt/rainratein",  $qarray["rainratein"]); 
          $client->loop();          
          $client->publish("ecowitt/raineventin",  $qarray["eventrainin"]); 
          $client->loop();          
          $client->publish("ecowitt/lightningCount",  $qarray["lightning_num"]); 
          $client->loop();
          $client->publish("ecowitt/lightningTime",  $qarray["lightning_time"]); 
          $client->loop();
          $client->publish("ecowitt/tempf-garage",  $qarray["temp1f"]); 
          $client->loop();
          $client->publish("ecowitt/humidity-garage",  $qarray["humidity1"]); 
          $client->loop();
          $client->publish("ecowitt/winddir",  $qarray["winddir"]); 
          $client->loop();
          $client->publish("ecowitt/wind",  $qarray["windspeedmph"]); 
          $client->loop();
          $client->publish("ecowitt/windgust",  $qarray["windgustmph"]); 
          $client->loop();
          $client->publish("ecowitt/uv",  $qarray["uv"]); 
          $client->loop();
          $client->publish("ecowitt/solar",  $qarray["solarradiation"]); 
          $client->loop();
          $dewpoint=$qarray["tempf"]-9/25.0*(100.0-$qarray["humidity"]);
          $client->publish("ecowitt/dewpoint",  $dewpoint); 
          $client->loop();
          $client->publish("ecowitt/time", time());
          break;

        case "5N1x31":
          $client->publish("sensors/5N1battery", $qarray["battery"]=="normal"?0:1); 
          $client->loop();
          $client->publish("sensors/barometer", $qarray["baromin"]);  
          $client->loop();
          if (isset($qarray["windspeedmph"])) $client->publish("sensors/wind", $qarray["windspeedmph"]);
          $client->loop();
          if (isset($qarray["winddir"]))  $client->publish("sensors/winddir", $qarray["winddir"]);
          $client->loop();
          if (isset($qarray["rainin"]))
          {
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
            $client->publish("sensors/rain", $qarray["rainin"]);   
            $client->loop();
          } 
          if (isset($qarray["dailyrainin"])) $client->publish("sensors/raintotal", $qarray["dailyrainin"]);
          break;    
        case "5N1x38":
          $tempf2=data_getval("ProOut|tempf");
          $tempf=data_getval2("ProOut","tempf");
          logToFile ("ProOut temp1 ".$tempf." temp2 ".$tempf2,true);

          if ($tempf !== false) 
          {
            $client->publish("sensors/temp", ($qarray["tempf"]+$tempf)/2.0);        
          }  
          else
          {
            // skip publish if we don't get both values, for now
            $client->publish("sensors/temp", ($qarray["tempf"]));            
          }        
          $client->loop();
          $client->publish("sensors/tempraw", ($qarray["tempf"]));         
          $client->loop();
          logToFile("Publish timestamp",true);
          $client->publish("sensors/time", time());
          break;
        case "ProOut":
          $client->publish("sensors/ProOutbattery",$qarray["battery"]=="normal"?0:1); 
          $client->loop();
          $client->publish("sensors/soil", $qarray["ptempf"]);
          $client->loop();
          $client->publish("sensors/humidity",  $qarray["humidity"]); 
          $client->loop();
          $client->publish("sensors/protemp",  $qarray["tempf"]); 
          break;    
        case "tower-00007524":  
         // logToFile ("Basement temp".$qarray["tempf"]." bat ".$qarray["battery"],true);
          $client->publish("sensors/BasementBattery",$qarray["battery"]=="normal"?0:1);    
          $client->loop();
          $client->publish("sensors/tempf-basement", $qarray["tempf"]);        //tower hack, add serials here.
          $client->loop();
          $client->publish("sensors/humidity-basement", $qarray["humidity"]);      
          $client->loop();
          $client->publish("sensors/dewpoint-basement", ($qarray["tempf"]-9/25.0*(100.0-$qarray["humidity"])));    
          break;
        case "tower-00002909":  
          $client->publish("sensors/KegBattery",$qarray["battery"]=="normal"?0:1);    
          $client->loop();
          $client->publish("sensors/tempf-keg", $qarray["tempf"]);        //tower hack, add serials here.
          $client->loop();
          $client->publish("sensors/humidity-keg", $qarray["humidity"]);      
          break;
        case "tower-00000915":  
          $client->publish("sensors/garageBattery",$qarray["battery"]=="normal"?0:1);    
          $client->loop();
          $client->publish("sensors/garageTempf", $qarray["tempf"]);        //tower hack, add serials here.
          $client->loop();
          $client->publish("sensors/garageHumidity", $qarray["humidity"]);      
          break;
        default:
           logToFile ("Unknown sensor ".$station." ".$_SERVER["QUERY_STRING"],true);
      }
      $client->disconnect(); 
    }
// ===    
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
