<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FCMService
{
    protected $serverKey;

    public function __construct()
    {
        $this->serverKey = env('FIREBASE_SERVER_KEY');
    }

    /**
     * Send a notification to a specific device token or topic.
     *
     * @param string $to Device token or topic (e.g., "/topics/teachers")
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data payload
     * @return bool
     */
    public function sendNotification($to, $title, $body, $data = [])
    {
        $projectId = env('FIREBASE_PROJECT_ID');
        
        // If no project ID, we can't use V1. 
        // But for the Mock Server, we still want to allow it.
        $endpoint = env('FCM_ENDPOINT');

        // MOCK BYPASS
        if ($endpoint && str_contains($endpoint, 'mock')) {
             Log::info('MOCK FCM BYPASS (V1):', [
                'to' => $to,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => $data
            ]);
            return true;
        }

        if (empty($projectId)) {
             Log::error('FIREBASE_PROJECT_ID is missing in .env');
             return false;
        }

        // Get OAuth 2.0 Token
        $credentialsPath = storage_path('app/firebase_credentials.json');
        if (!file_exists($credentialsPath)) {
            Log::error("Firebase credentials file not found at: $credentialsPath");
            return false;
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
        $middleware = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $credentialsPath);
        $token = $middleware->fetchAuthToken(\Google\Auth\HttpHandler\HttpHandlerFactory::build());
        $accessToken = $token['access_token'];

        // V1 Endpoint
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        // V1 Payload Structure
        $messagePayload = [
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'data' => $data,
        ];

        // Determine if $to is a topic or a token
        if (str_starts_with($to, '/topics/')) {
            $messagePayload['topic'] = str_replace('/topics/', '', $to);
        } else {
            $messagePayload['token'] = $to;
        }

        $payload = ['message' => $messagePayload];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        if ($response->successful()) {
            Log::info("FCM V1 notification sent to {$to}");
            return true;
        } else {
            Log::error("FCM V1 notification failed: " . $response->body());
            return false;
        }
    }

    public function subscribeToTopic($token, $topic)
    {
        $credentialsPath = storage_path('app/firebase_credentials.json');
        if (!file_exists($credentialsPath) && file_exists('/etc/secrets/firebase_credentials.json')) {
            $credentialsPath = '/etc/secrets/firebase_credentials.json';
        }

        if (!file_exists($credentialsPath)) {
            throw new \Exception("Firebase credentials file not found at: " . $credentialsPath);
        }

        $jsonKey = json_decode(file_get_contents($credentialsPath), true);
        if (isset($jsonKey['private_key'])) {
            $jsonKey['private_key'] = str_replace('\\n', "\n", $jsonKey['private_key']);
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
        $middleware = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $jsonKey);
        $authToken = $middleware->fetchAuthToken(\Google\Auth\HttpHandler\HttpHandlerFactory::build());
        $accessToken = $authToken['access_token'];

        $url = 'https://iid.googleapis.com/iid/v1:batchAdd';
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
            'access_token_auth' => 'true',
        ])->post($url, [
            'to' => '/topics/' . $topic,
            'registration_tokens' => [$token],
        ]);

        if ($response->successful()) {
            Log::info("Subscribed {$token} to {$topic}");
            return true;
        }

        Log::error("Subscription failed: " . $response->body());
        return false;
    }
}
