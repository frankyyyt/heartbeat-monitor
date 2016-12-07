<?php
namespace Ginja;

require "vendor/autoload.php";

use Carbon\Carbon;
use Dotenv\Dotenv;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class HeartMonitor
{
    public function __construct()
    {
        $this->env = new Dotenv(__DIR__);
        $this->env->load();

        $this->apiUrl = getenv('APP_URL');

        $this->log = new Logger('heartbeat');
        $this->log->pushHandler(new StreamHandler('logs/heartbeat.log', Logger::INFO));
    }

    public function check()
    {
        $this->guestToken = $this->getGuestToken();
        $newestHeartBeatString = $this->getNewestHeartBeatDate();
        $timestampFile = $this->getEnvWithDefault('TIMESTAMP_FILE', '.heartbeat');
        $lastStatusFile = $this->getEnvWithDefault('TIMESTAMP_FILE', '.heartbeat').('.last-status');
        $lastStatus = file_exists($lastStatusFile) ? file_get_contents($lastStatusFile) : null;

        error_log('LAST STATUS: ' . $lastStatus);

        // no timestamp file means first time this has run
        if (!file_exists($timestampFile)) {
            // Write the contents back to the file
            file_put_contents($timestampFile, $newestHeartBeatString);
            file_put_contents($lastStatusFile, 'up');
            $this->log->info('First hearbeat for ' . $this->apiUrl);
        } else {
            // heartbeat monitor has run previously
            $previousHeartBeatString = file_get_contents($timestampFile);

            // if the timestamps are identical then the queue has failed
            if ($previousHeartBeatString == $newestHeartBeatString) {
                $this->log->error('Heartbeat down:' . $this->apiUrl);
                $this->notifyQueueBroken();
                file_put_contents($lastStatusFile, 'down');

            } else {
                $this->log->info('Heartbeat up:' . $this->apiUrl);

                // save the new timestamp
                file_put_contents($timestampFile, $newestHeartBeatString);

                // reset error notifications if the queue goes down again
                $this->deletePropertiesFile();

                ;
                if ($lastStatus == 'down') {
                    $message = '<!channel|channel> The queue for API server ' . $this->apiUrl . ' has restarted.';
                    $this->sendSlackNotifiations($message, true);
                    file_put_contents($lastStatusFile, 'up');
                }
            }
        }
    }

    /*
     * Get a property from .env, but default if not specified
     */
    private function getEnvWithDefault($key, $default)
    {
        $result = getenv($key);
        if (empty($result)) {
            $result = $default;
        }
        return $result;
    }

    /*
     * Send a slack notification, but avoid notification spam by only sending every 60 mins
     */
    private function notifyQueueBroken()
    {
        $propertiesFile = $this->getEnvWithDefault('PROPERTIES_FILE', '.properties');
        $properties = file_exists($propertiesFile) ? json_decode(file_get_contents($propertiesFile)) : [];
        $sendMessage = true;

        if (isset($properties->last_error_notification)) {
            $now = Carbon::now();
            $lastErrorNotifiedAt = new Carbon($properties->last_error_notification);
            $deltaMins = $now->diffInMinutes($lastErrorNotifiedAt);
            if ($deltaMins<60) {
                $sendMessage = false;
                $this->log->info('Throttled error notification');
            } else {
                // delete the file, resetting error notifications
                $this->deletePropertiesFile();
            }
        } else {
            // save time the last error message was sent
            $properties['last_error_notification'] = Carbon::now()->toDateTimeString();
            file_put_contents($propertiesFile, json_encode($properties));
        }

        if ($sendMessage) {
            $message = '<!channel|channel> The queue for API server ' . $this->apiUrl . ' appears to have stopped working';
            $this->sendSlackNotifiations($message, false);
        }
    }

    /*
     * Delete the properties file if it exists
     */
    private function deletePropertiesFile()
    {
        $propertiesFile = $this->getEnvWithDefault('PROPERTIES_FILE', '.properties');
        if (file_exists($propertiesFile)) {
            unlink($propertiesFile);
        }
    }

    /*
     * Send a slack notification
     */
    protected function sendSlackNotifiations($message, $beating=true)
    {
        $url = getenv('SLACK_WEBHOOK_URL');
        $emoji = $this->getEnvWithDefault('SLACK_EMOJI_BEATING', ':heart:');
        if (!$beating) {
            $emoji = $this->getEnvWithDefault('SLACK_EMOJI_STOPPED', ':heart:');
        }

        if (!empty($url)) {
            $slack = new \Maknz\Slack\Client(
                $url,
                [
                    'username' => $this->getEnvWithDefault('SLACK_USERNAME', 'heartbeat_monitor'),
                    'icon' => $emoji
                ]
            );
            $messageObj = $slack->createMessage();
            $messageObj->send($message);
        }
    }

    /*
	 * Get the oauth guest token
	 */
    private function getGuestToken()
    {
        $clientSecret = getenv('STORE_FRONT_CLIENT_SECRET');
        $params = [
            'grant_type'    => 'guest',
            'client_id'     => 'store-front',
            'client_secret' => $clientSecret,
            'scope'         => 'store-front-guest',
        ];

        $guzzle = new \GuzzleHttp\Client();
        $endPoint = getenv('OAUTH_ENDPOINT');
        $response = $guzzle->request('POST', $this->apiUrl . $endPoint, ['json' => $params]);

        // need to get the access token
        $decoded = json_decode($response->getBody());
        return $decoded->access_token;
    }

    /*
     * Get the heartbeat date from an API end point
     */
    private function getNewestHeartBeatDate()
    {
        $endpoint = $this->apiUrl . getenv('SETTINGS_ENDPOINT') . '?access_token=' . $this->guestToken;
        $guzzle = new \GuzzleHttp\Client();
        $response = $guzzle->request('GET', $endpoint);
        $settings = json_decode($response->getBody(), true);
        return $settings['data']['heartbeat_date'];
    }
}


$monitor = new HeartMonitor();
$monitor->check();
