#Heartbeat Monitor
In order to test if our Laravel queue is still working, a job is added every minute that simply
updates a timestamp called 'heartbeat_date'.  This monitoring script checks for a lack of change
in that value, and if so trigger slack notifications.  Notifications of errors are only
repeated every 60 mins to avoid notification spam.

#Install
```bash
git clone git@github.com:ginja-th/heartbeat-monitor.git
cd heartbeat-monitor
composer install
```
#Prerequisites
* Your API should have a guest oauth layer
* Your API has an endpoint that returns payload in this format 
```
{"data":{"heartbeat_date":"2016-11-26 10:22:50"}}
```
#Configuration
Copy app/.env.example to app/.env and update the values for your API as appropriate.

#Execute the Script
```
php app/HeartMonitor.php

```

#Add to Cron
Add this entry to your cron using crontab -e

```
* * * * * cd /path/to/montir && php app/HeartMonitor.php
```