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
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = 'FCM: JSON decode error: ' . json_last_error_msg();
            \Log::error($errorMsg);
            error_log($errorMsg);
            throw new \Exception("Failed to decode Firebase credentials JSON: " . json_last_error_msg());
        }

        if (isset($jsonKey['private_key'])) {
            // Log the key before replacement (masked)
            $msg1 = 'FCM: Private key before processing: ' . substr($jsonKey['private_key'], 0, 50) . '...';
            \Log::info($msg1);
            error_log($msg1);
            
            $jsonKey['private_key'] = str_replace('\\n', "\n", $jsonKey['private_key']);
            
            // Log the key after replacement (masked) and check for newlines
            $msg2 = 'FCM: Private key after processing: ' . substr($jsonKey['private_key'], 0, 50) . '...';
            $msg3 = 'FCM: Private key contains newlines: ' . (strpos($jsonKey['private_key'], "\n") !== false ? 'Yes' : 'No');
            \Log::info($msg2);
            error_log($msg2);
            \Log::info($msg3);
            error_log($msg3);

            // Hex dump to see exact bytes (first 100 chars)
            $hex = bin2hex(substr($jsonKey['private_key'], 0, 100));
            $msgHex = 'FCM: Private key hex dump (first 100 chars): ' . $hex;
            \Log::info($msgHex);
            error_log($msgHex);

            // Verify if OpenSSL accepts it
            $pkey = openssl_pkey_get_private($jsonKey['private_key']);
            if ($pkey === false) {
                $sslError = 'FCM: OpenSSL could not parse the key: ' . openssl_error_string();
                \Log::error($sslError);
                error_log($sslError);
            } else {
                $sslSuccess = 'FCM: OpenSSL successfully parsed the key.';
                \Log::info($sslSuccess);
                error_log($sslSuccess);

                // --- SELF-TEST: Sign and Verify ---
                $details = openssl_pkey_get_details($pkey);
                if ($details && isset($details['key'])) {
                    $publicKey = $details['key'];
                    $testData = "test_signature_data";
                    $signature = '';
                    
                    // Sign
                    $signResult = openssl_sign($testData, $signature, $pkey, "SHA256");
                    if ($signResult) {
                        $signMsg = 'FCM: Self-test signature generated successfully.';
                        \Log::info($signMsg);
                        error_log($signMsg);

                        // Verify
                        $verifyResult = openssl_verify($testData, $signature, $publicKey, "SHA256");
                        if ($verifyResult === 1) {
                            $verifyMsg = 'FCM: Self-test signature VERIFIED successfully. OpenSSL is working correctly.';
                            \Log::info($verifyMsg);
                            error_log($verifyMsg);
                        } elseif ($verifyResult === 0) {
                            $verifyMsg = 'FCM: Self-test signature verification FAILED. Signature invalid.';
                            \Log::error($verifyMsg);
                            error_log($verifyMsg);
                        } else {
                            $verifyMsg = 'FCM: Self-test signature verification ERROR: ' . openssl_error_string();
                            \Log::error($verifyMsg);
                            error_log($verifyMsg);
                        }
                    } else {
                        $signMsg = 'FCM: Self-test signature generation FAILED: ' . openssl_error_string();
                        \Log::error($signMsg);
                        error_log($signMsg);
                    }
                } else {
                    $pubMsg = 'FCM: Could not extract public key for self-test.';
                    \Log::error($pubMsg);
                    error_log($pubMsg);
                }
                // ----------------------------------
            }
        } else {
            $msg = 'FCM: private_key not found in credentials JSON';
            \Log::error($msg);
            error_log($msg);
        }

        // Log Server Time
        $timeMsg = 'FCM: Server Time: ' . date('Y-m-d H:i:s P');
        \Log::info($timeMsg);
        error_log($timeMsg);

        if (isset($jsonKey['client_email'])) {
            $msg = 'FCM: Using client_email: ' . $jsonKey['client_email'];
            \Log::info($msg);
            error_log($msg);
        }

        if (isset($jsonKey['private_key_id'])) {
            $idMsg = 'FCM: Using private_key_id: ' . $jsonKey['private_key_id'];
            \Log::info($idMsg);
            error_log($idMsg);
            // EXPERIMENT: Unset private_key_id to see if Google accepts the token without the kid header
            // or if the ID was mismatched.
            unset($jsonKey['private_key_id']);
            \Log::info("FCM: Unset private_key_id for testing.");
            error_log("FCM: Unset private_key_id for testing.");
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
