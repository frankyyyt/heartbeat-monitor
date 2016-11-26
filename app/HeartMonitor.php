<?php
namespace Ginja;

require "vendor/autoload.php";

use Dotenv\Dotenv;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class HeartMonitor {
	public function __construct() {
		$this->env = new Dotenv(__DIR__);
		$this->env->load();

        $this->apiUrl = getenv('APP_URL');

        $this->log = new Logger('heartbeat');
        $this->log->pushHandler(new StreamHandler('logs/heartbeat.log', Logger::INFO));

    }

	public function check() {
		$this->guestToken = $this->getGuestToken();
		$heartbeatDate = $this->getNewestHeartBeatDate();
        $newestHeartBeatString = $this->getNewestHeartBeatDate();
        $timestampFile = getenv('TIMESTAMP_FILE');

        // no timestamp file means first time this has run
        if (!file_exists($timestampFile)) {
            // Write the contents back to the file
            file_put_contents($timestampFile, $newestHeartBeatString);
            $this->log->info('First hearbeat for ' . $this->apiUrl);

        } else {
            $previousHeartBeatString = file_get_contents($timestampFile);

            // if the timestamps are identical then the queue has failed
            if ($previousHeartBeatString == $newestHeartBeatString) {
                $this->log->error('Heartbeat down:' . $this->apiUrl);

                $this->notifyQueueBroken();
            } else {
                $this->log->info('Heartbeat up:' . $this->apiUrl);

                // save the new timestamp
                file_put_contents($timestampFile, $newestHeartBeatString);
            }
        }
    }

    /*
     * 1) Email list of developers
     * 2) Slack notification
     */
    private function notifyQueueBroken() {
        $message = 'The queue for API server ' . $this->apiUrl . 'appears to have stopped working';
        $this->sendSlackNotifiations($message);
    }

    /*
     * Send a slack notification
     */
    protected function sendSlackNotifiations($message)
    {
        $url = getenv('SLACK_WEBHOOK_URL');
        if (!empty($url)) {
            $slack = new \Maknz\Slack\Client(
                $url,
                [
                    'username' => 'nontgor',
                    'icon' => ':exclamation',
                ]
            );
            $messageObj = $slack->createMessage();
            $messageObj->send($message);
        }
    }

    protected function sendEmails($subject, $message) {
        $transport = \Swift_SmtpTransport::newInstance("smtp.fake.com", 25);
        $transport->setUsername("Username");
        $transport->setPassword("Password");

        $emails = explode(',', getenv('EMAIL_TO'));
        $to = array_shirt($emails);
        $bcc = $emails;

        $email = getenv('EMAIL_FROM');
        $name = getenv('EMAIL_NAME_FROM');

        $message = Swift_Message::newInstance()
            // Give the message a subject
            ->setSubject($subject)
            // Set the From address with an associative array
            ->setFrom(array($email => $name))

            // Set the To addresses with an associative array
            ->setTo(array($to))

            // Give it a body
            ->setBody($message)
        ;

        if (!empty($bcc)) {
            $message->setBody($bcc);
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
