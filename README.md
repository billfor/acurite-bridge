## AcuRite and Ecowitt Weather Scripts
These scripts intercept and log the sensor data from the acurite weatherbridge or Ecowitt GW1000, providing access to all metrics.

### Acurite specific options:
The following options may be turned on/off through variables in the script.

- if an external temperature and humidity sensor is present then that data is substituted for the 5 in 1 sensors values. 
- add soil temperature to weather underground if sensor is available
- recalculate/smooth the "rainin" parameter sent to wunderground based on the 15minute accumulated rain rather than the sensor metric which resets every hour.
- added support for the tower sensors (but you have to edit the code with your specific serial numbers)

See the "upateweatherstation.php" script for further parameters.

### Generic options common to Acurite and Ecowitt:

- store all sensor data in a sqlite database
- publish sensor data to MQTT (for use in openhab, etc....)
- log all low-level request data to/from the bridge


See the "upateweatherstation.php" script for further parameters.


### Requirements:

- a webserver
- dnsmasq or equiv if you want to intercept the Acurite bridge

### Installation:

- edit the variables in the begining of the weathertation.php script as desired
- place all scripts inside a directory "weatherstation" on your web server directly below $HTDOCS. 
- for Acurite, make the ip address of the weatherbridge static by using dhcp reservations and fake entries (dnsmasq, etc...) for hubapi.myacurite.com and rtupdate.wunderground.com so that your webserver receives the weatherbridge GET instead of the real DNS hosts.

One easy way to add dnsmasq entries is with a router that supports it  (dd-wrt, tomato, merlin, etc...), for example (assuming 192.168.1.250 is the acurite bridge) add the following to /tmp/etc/hosts and /jffs/config/hosts.add on the router:	

	192.168.1.250 mylocalwebserver hubapi.myacurite.com

### Testing:

If all is working, and logging is enabled,  you should see the following files under $HTDOCS/weatherstation:

- **logfile.txt** logging information
- **weather.dat** key-values containing the most recent metrics and values
- **weather.db3** database, if you have enabled the database (use something like *sqlite3 weather.db3*)


### Notes on Apache Configuration:

The configuration for the weatherstation directory should include something like the following (based on modrewrite) because acurite does not use a script extension on their URI. 

	RewriteCond %{REQUEST_FILENAME} !-d
	RewriteCond %{REQUEST_FILENAME}\.php -f
	RewriteRule ^(.*)$ $1.php

The weatherbridge uses http so you need to have Apache listening on port 80. If running with SSL remember to disable it for the weatherstation script, and also consider not logging the constant traffic.

	RewriteRule "^/weatherstation" - [L]
	# other rules that redirect traffic to 443

        # don't log the constant bridge traffic 
        SetEnvIf Request_URI "^/weatherstation.*$" dontlog
        CustomLog ${APACHE_LOG_DIR}/access.log combined env=!dontlog

### Openhab Samples:
The directory openhab contains a sample configuration using the MQTT bindings.

![alt text](screenshots/image1.jpg)
![alt text](screenshots/image2.jpg)
