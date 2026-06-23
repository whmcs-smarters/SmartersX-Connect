<?php
/**
 * Hooks placeholder for Device Manager addon.
 * Register hooks here if you want to react to WHMCS events.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
require_once __DIR__ . '/version.php';

// AddTransaction hook: send payment notification when a transaction is recorded.
// Using AddTransaction instead of InvoicePaid so that amountin, fees, rate, and
// transid are available directly in $vars — no secondary tblaccounts query needed,
// which eliminates the race condition that caused amount = 0 on some gateways.
add_hook('AddTransaction', 1, function ($vars) {
    try {
        smartersxconnect_ensure_notification_infrastructure_tables();

        // WHMCS docs show both 'invoiceid' and the misspelled 'invocieid' — accept both.
        $invoiceId = (int) ($vars['invoiceid'] ?? $vars['invocieid'] ?? 0);
        $amountIn  = (float) ($vars['amountin'] ?? 0);

        // Only notify for transactions linked to an invoice with a positive inbound amount.
        // Log skipped cases so admins can see the hook IS firing.
        if ($invoiceId <= 0) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert([
                'request'  => 'AddTransaction hook: skipped (no invoiceid). vars keys: ' . implode(', ', array_keys($vars)),
                'response' => 'skipped',
                'type'     => 'hook_debug',
                'datetime' => date('Y-m-d H:i:s'),
            ]);
            return;
        }
        if ($amountIn <= 0) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert([
                'request'  => 'AddTransaction hook: skipped (amountin=' . $amountIn . ', invoiceid=' . $invoiceId . ')',
                'response' => 'skipped',
                'type'     => 'hook_debug',
                'datetime' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        // Respect the global notifications toggle set in the module admin UI.
        $globalEnabled = Capsule::table('tblconfiguration')
            ->where('setting', 'smartersx_notifications_enabled')
            ->value('value');
        if ($globalEnabled !== null && (string) $globalEnabled !== '1') {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert([
                'request'  => 'AddTransaction hook: skipped (global notifications disabled)',
                'response' => 'skipped',
                'type'     => 'hook_debug',
                'datetime' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        smartersxconnect_ensure_payment_notification_pipeline_table();
        $notification = Capsule::table('mod_smartersxconnect_payment_notifications')
            ->where('event_key', 'payment_received')
            ->first();
        if (!$notification || (int) $notification->enabled !== 1) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert([
                'request'  => 'AddTransaction hook: skipped (payment_received notification disabled or not found)',
                'response' => 'skipped',
                'type'     => 'hook_debug',
                'datetime' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        $userId = (int) ($vars['userid'] ?? 0);
        $user   = localAPI('GetClientsDetails', ['clientid' => $userId]);
        $client = $user['client'] ?? [];
        $name   = trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? ''));
        if ($name === '') {
            $name = 'Client #' . $userId;
        }

        // amountin is already in the customer's own currency — use it as-is.
        $rawAmount  = $amountIn;
        $currencyId = (int) ($vars['currency'] ?? ($client['currency'] ?? 0));
        $amount     = smartersxconnect_format_currency_amount($rawAmount, $currencyId);

        $paymentMethod = (string) ($vars['gateway'] ?? 'N/A');
        $date          = isset($vars['date']) ? (string) $vars['date'] : date('Y-m-d H:i:s');
        $transactionId = (string) ($vars['transid'] ?? '');

        $replacements = [
            '{amount}'         => $amount,
            '{total}'          => $amount,
            '{transaction_id}' => $transactionId,
            '{transactionid}'  => $transactionId,
            '{invoice_id}'     => (string) $invoiceId,
            '{invoiceid}'      => (string) $invoiceId,
            '{client_name}'    => $name,
            '{name}'           => $name,
            '{userid}'         => (string) $userId,
            '{email}'          => (string) ($client['email'] ?? ''),
            '{payment_method}' => $paymentMethod,
            '{date}'           => $date,
        ];

        $subject = strtr($notification->title_template, $replacements);
        $message = strtr($notification->body_template, $replacements);

        $query = Capsule::table('mod_smartersxconnect_notification_devices')->where('status', 1)->where('devicetoken', '!=', '');
        try {
            if (Capsule::schema()->hasColumn('mod_smartersxconnect_notification_devices', 'payment_alerts')) {
                $query = $query->where('payment_alerts', 1);
            }
        } catch (\Throwable $_) {
            // ignore
        }

        $devices = $query->get();
        if (!$devices || count($devices) == 0) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert([
                'request'  => 'AddTransaction hook: no devices with payment_alerts enabled and a valid token',
                'response' => 'skipped',
                'type'     => 'hook_debug',
                'datetime' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        smartersxconnect_sendFCMNotification($subject, $message, [
            'id'             => (string) $invoiceId,
            'invoice_id'     => (string) $invoiceId,
            'transaction_id' => $transactionId,
            'amount'         => sprintf('%.2f', $rawAmount),
            'event'          => 'payment_received',
        ], $devices);
    } catch (\Throwable $th) {
        try {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert([
                'request'  => 'AddTransaction hook exception: ' . $th->getMessage(),
                'response' => $th->getTraceAsString(),
                'type'     => 'hook_error',
                'datetime' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $_) {}
    }
});

function smartersxconnect_ensure_payment_notification_pipeline_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    smartersxconnect_ensure_notification_infrastructure_tables();

    if (!Capsule::schema()->hasTable('mod_smartersxconnect_payment_notifications')) {
        Capsule::schema()->create('mod_smartersxconnect_payment_notifications', function ($table) {
            $table->increments('id');
            $table->string('event_key', 64)->unique();
            $table->string('label', 128);
            $table->boolean('enabled')->default(true);
            $table->string('title_template', 255);
            $table->text('body_template');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    $exists = Capsule::table('mod_smartersxconnect_payment_notifications')
        ->where('event_key', 'payment_received')
        ->first();
    if (!$exists) {
        Capsule::table('mod_smartersxconnect_payment_notifications')->insert([
            'event_key' => 'payment_received',
            'label' => 'Payment Received',
            'enabled' => 1,
            'title_template' => 'New Payment Received: {amount} for Invoice #{invoice_id}',
            'body_template' => 'New Payment Received: {total} for Invoice #{invoice_id}.',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

function smartersxconnect_ensure_notification_infrastructure_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (!Capsule::schema()->hasTable('mod_smartersxconnect_notification_devices')) {
        Capsule::schema()->create('mod_smartersxconnect_notification_devices', function ($table) {
            $table->increments('id');
            $table->string('devicetoken', 255)->default('');
            $table->boolean('status')->default(true);
            $table->integer('device_table_id')->nullable();
            $table->string('mobile_device_id', 128)->nullable();
            $table->string('fcm_project_id', 128)->nullable();
            $table->string('fcm_sender_id', 64)->nullable();
            $table->string('fcm_app_id', 128)->nullable();
            $table->string('ios_bundle_id', 191)->nullable();
            $table->boolean('payment_alerts')->default(true);
            $table->timestamp('datetime')->nullable();
        });
    } else {
        foreach ([
            'fcm_project_id' => 128,
            'fcm_sender_id' => 64,
            'fcm_app_id' => 128,
            'ios_bundle_id' => 191,
        ] as $column => $length) {
            if (!Capsule::schema()->hasColumn('mod_smartersxconnect_notification_devices', $column)) {
                Capsule::schema()->table('mod_smartersxconnect_notification_devices', function ($table) use ($column, $length) {
                    $table->string($column, $length)->nullable();
                });
            }
        }
    }

    if (!Capsule::schema()->hasTable('mod_smartersxconnect_notification_credentials')) {
        Capsule::schema()->create('mod_smartersxconnect_notification_credentials', function ($table) {
            $table->increments('id');
            $table->text('accesstoken')->nullable();
            $table->text('service_account_json')->nullable();
            $table->timestamp('datetime')->nullable();
        });
    } elseif (!Capsule::schema()->hasColumn('mod_smartersxconnect_notification_credentials', 'service_account_json')) {
        Capsule::schema()->table('mod_smartersxconnect_notification_credentials', function ($table) {
            $table->text('service_account_json')->nullable();
        });
    }

    if (!Capsule::schema()->hasTable('mod_smartersxconnect_notification_logs')) {
        Capsule::schema()->create('mod_smartersxconnect_notification_logs', function ($table) {
            $table->increments('id');
            $table->text('request')->nullable();
            $table->text('response')->nullable();
            $table->string('type', 255)->nullable();
            $table->timestamp('datetime')->nullable();
        });
    }
}

function smartersxconnect_get_configured_ios_bundle_id()
{
    if (!Capsule::schema()->hasTable('mod_smartersxconnect_firebase_config')) {
        return '';
    }

    $plist = (string) Capsule::table('mod_smartersxconnect_firebase_config')
        ->orderBy('id', 'asc')
        ->value('ios_google_service_plist');
    if ($plist === '') {
        return '';
    }

    if (preg_match('/<key>\s*BUNDLE_ID\s*<\/key>\s*<string>\s*([^<]+)\s*<\/string>/i', $plist, $matches)) {
        return trim(html_entity_decode($matches[1], ENT_QUOTES));
    }

    return '';
}

function smartersxconnect_format_currency_amount(float $amount, int $currencyId): string
{
    $total = (float) $amount;
    if ($currencyId > 0) {
        $currency = Capsule::table('tblcurrencies')->where('id', $currencyId)->first();
        if ($currency) {
            return (string) $currency->prefix . number_format($total, 2) . (string) $currency->suffix;
        }
    }

    return number_format($total, 2);
}

function smartersxconnect_format_invoice_amount(array $invoice, array $client): string
{
    return smartersxconnect_format_currency_amount(
        (float) ($invoice['total'] ?? 0),
        (int) ($client['currency'] ?? 0)
    );
}

function smartersxconnect_get_service_account_credentials()
{
    $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Missing FCM service account';
    $selectedProjectId = '';

    try {
        require_once __DIR__ . '/lib/FirebaseAuth.php';
        $row = \FirebaseAuth::getRow();
        $selectedProjectId = $row ? (string) ($row->selected_project_id ?? '') : '';
    } catch (\Throwable $e) {}

    try {
        require_once __DIR__ . '/lib/FirebaseAuth.php';
        $json = \FirebaseAuth::getServiceAccountJson();
        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $credentialProjectId = (string) ($decoded['project_id'] ?? '');
                if ($selectedProjectId === '' || $credentialProjectId === $selectedProjectId) {
                    $GLOBALS['smartersxconnect_fcm_credential_error'] = '';
                    return $decoded;
                }
                $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Saved Firebase service account project (' . $credentialProjectId . ') does not match selected Firebase project (' . $selectedProjectId . ').';
            } else {
                $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Saved Firebase service account JSON is invalid.';
            }
        }
    } catch (\Throwable $e) {}

    try {
        smartersxconnect_ensure_notification_infrastructure_tables();
        if (
            Capsule::schema()->hasTable('mod_smartersxconnect_notification_credentials')
            && Capsule::schema()->hasColumn('mod_smartersxconnect_notification_credentials', 'service_account_json')
        ) {
            $json = Capsule::table('mod_smartersxconnect_notification_credentials')
                ->whereNotNull('service_account_json')
                ->orderBy('id', 'desc')
                ->value('service_account_json');
            if ($json) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $credentialProjectId = (string) ($decoded['project_id'] ?? '');
                    if ($selectedProjectId === '' || $credentialProjectId === $selectedProjectId) {
                        $GLOBALS['smartersxconnect_fcm_credential_error'] = '';
                        return $decoded;
                    }
                    $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Saved notification credential project (' . $credentialProjectId . ') does not match selected Firebase project (' . $selectedProjectId . ').';
                } else {
                    $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Saved notification credential JSON is invalid.';
                }
            }
        }
    } catch (\Throwable $e) {}

    try {
        require_once __DIR__ . '/lib/FirebaseAuth.php';
        require_once __DIR__ . '/lib/FirebaseAPI.php';

        $row = \FirebaseAuth::getRow();
        $projectId = $row ? (string) ($row->selected_project_id ?? '') : '';
        if (!$row) {
            $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Firebase OAuth credentials are not saved.';
        } elseif (empty($row->refresh_token)) {
            $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Google account is not connected. Click Connect with Google or Re-authenticate.';
        } elseif ($projectId === '') {
            $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Firebase project is not selected.';
        }

        if ($projectId !== '') {
            $token = \FirebaseAuth::getValidAccessToken();
            if ($token) {
                $keyResult = \FirebaseAPI::getServiceAccountKey($token, $projectId);
                if (empty($keyResult['error']) && !empty($keyResult['content'])) {
                    $storeResult = smartersxconnect_store_service_account_credentials($keyResult['content']);
                    if (!empty($storeResult['ok'])) {
                        $decoded = json_decode($keyResult['content'], true);
                        if (is_array($decoded)) {
                            $GLOBALS['smartersxconnect_fcm_credential_error'] = '';
                            return $decoded;
                        }
                    }
                    $GLOBALS['smartersxconnect_fcm_credential_error'] = $storeResult['error'] ?? 'Unable to save fetched FCM service account.';
                } else {
                    $GLOBALS['smartersxconnect_fcm_credential_error'] = $keyResult['error'] ?? 'Unknown Firebase API error while fetching service account.';
                    Capsule::table('mod_smartersxconnect_notification_logs')->insert([
                        'request'  => 'Auto-fetch FCM service account failed',
                        'response' => $keyResult['error'] ?? 'Unknown Firebase API error',
                        'type'     => 'fcm_setup',
                        'datetime' => date('Y-m-d H:i:s'),
                    ]);
                }
            } elseif ($projectId !== '') {
                $GLOBALS['smartersxconnect_fcm_credential_error'] = 'Google session expired. Click Re-authenticate, then send the test again.';
            }
        }
    } catch (\Throwable $e) {
        $GLOBALS['smartersxconnect_fcm_credential_error'] = $e->getMessage();
        try {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert([
                'request'  => 'Auto-fetch FCM service account exception',
                'response' => $e->getMessage(),
                'type'     => 'fcm_setup',
                'datetime' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $_) {}
    }

    // Fallback: Google account not connected — use hardcoded service account
    $GLOBALS['smartersxconnect_fcm_credential_error'] = '';
    return [
        'type'                        => 'service_account',
        'project_id'                  => 'smarterxtest',
        'private_key_id'              => '615cf16f55614c72703e0c6fb70fe1986ac1a462',
        'private_key'                 => "-----BEGIN PRIVATE KEY-----\nMIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCsxs68V7Dgb1r7\nEeEI8WV/NtAJsFAUqAgCUq7cj8nWrlbLkprRbsVKjdjIfs0Aku/ZcFMq8TG+OTHb\nmkQs8zgDFaeS+ErIRqkZH+AHTl/63pfvx5g9sXVk28UXGlUT1fYyCyxLwSRpUpUX\nUiC62y5iH2Pk5ilP1tBunpcrYlhREkahifJpLSpxru1lDXf2RUAC0DPL/SNVq+Mi\nlfUa980fDFNv+jQMVTeI1p8cgAf07P5BtwU3h9I7S+PDHHhOvyDU3VuVCctQcsCw\nRO2cjRv+S26rZLcjLOEcJv+nj3IY/FLHkGcYkMVQ1ZJ7FbSlCicZc8jcKvV9nc3L\ntD5Gu4vtAgMBAAECggEACp376cR8vMny/g8y9Cz7VvYsEhBY13Ac7+GbV7fpSA02\nDbPwWhLTwllVmzp5iAG/he3aWn7wVtdmai+AZX+7ryrXPZeO5uA6t23HQ0Osb7ra\nfNRX1WCwjVZY9eq7FCk3hAs+OViAz40Q1tpH4xuhbcuuhIwlOUAC1m1d7j9QnIio\nUi56smLOSQh0OiwwTZKd4ZdqIj+jHKtIahi0vVZK6ESVNCEj7kgUM/f0hmaulRc6\npkg3/4gYen6bt1OT3C5vFk6OKAsfENyprMSO8bOE44RfquvluI46NKY+JibYG/IX\nQye8rxs6ZehdEocB96vK/DTYuye35Fv36kKQQmjXyQKBgQDgVZq3lAPu+OMouvbu\n5GYzCljon9kBjaikJ66fqwWMNpBpcbT5/mp273r6knD/rA+eW+6pPq7opztq7r8E\nAzcgMEp4NE/jpvWnFQz0iaGUIX06DPOlGlfnyvywA/TcMNePxnIa+Sc+4jJeIzfQ\nxqsRGxIn6061LKYR71Zf4kaDqQKBgQDFKiU81/U1MGDEvF5uYwkWVV29xfS4SdK1\nFEp+Ih9WuUEh3vk+mreZCp/NhG+PULYbUvONE9dm7To0yQgOXWgbSzy9zbGulHss\nvpAcZo+DB3VHjxoeIZARDR7BNUipuKV4ugTNJEvrJTeKCdVPFC4hIkJ8Hp2qpJlR\nyOqi8XEwpQKBgGkyO1cSpbWOKJeU9O6ZVANjOsX7DzvXPdmcchqVjAhwHdAUbhU8\n5JfZPQX7XdnGyZws6AGdT0/x+77tLc2n5FXHz2QGw9+xD0jGakjRsV9RRPPP1wD5\nFXewjEXN1SjcDnlxSVi0tV6bm5rhUO8p+lYPJ7hoc4Qp58ZJQWu9I+vhAoGAEaaL\nPN4scn2JPDOM1J8DEj/EK5gMJ29ccJ+HZ7FQUug0v36Bm6woIYhE9BYWEqNsGhgb\n+5Y6I1m7azxP/1E3X7IlluxSKsnaGRBaQGCiGl3Rjv1tniLtDcm55hwKDD+eeKdW\nhLLqJPvo8++ba//nfUne39Ox07P2kc7Fyp6Ivo0CgYAr8qGwSJwgkouh1W3AVBAG\nkveIFMk0bu4V9Ou7UsYxIFmy1Y2rYihho8HCZrGM+g6DH17TnqNQNPr+NVppwB+c\nMsKkD1Ao68VczPMBxPAcsf4m+YYAdzJBSSa36RDuXqCZOQT9KPXKZGGasbp9llnm\ntmXs5Pu2ICyx+QNlZia1ow==\n-----END PRIVATE KEY-----\n",
        'client_email'                => 'firebase-adminsdk-5elkd@smarterxtest.iam.gserviceaccount.com',
        'client_id'                   => '117462810986934238843',
        'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri'                   => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url'        => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-5elkd%40smarterxtest.iam.gserviceaccount.com',
        'universe_domain'             => 'googleapis.com',
    ];
}

function smartersxconnect_store_service_account_credentials($serviceAccountJson)
{
    $decoded = json_decode($serviceAccountJson, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid Google FCM JSON file.'];
    }

    foreach (['type', 'project_id', 'client_email', 'private_key'] as $requiredField) {
        if (empty($decoded[$requiredField])) {
            return ['ok' => false, 'error' => 'Missing required field: ' . $requiredField];
        }
    }

    try {
        require_once __DIR__ . '/lib/FirebaseAuth.php';
        \FirebaseAuth::saveServiceAccountJson($serviceAccountJson);
        smartersxconnect_ensure_notification_infrastructure_tables();
        if (Capsule::schema()->hasTable('mod_smartersxconnect_notification_credentials')) {
            $existing = Capsule::table('mod_smartersxconnect_notification_credentials')->orderBy('id', 'asc')->first();
            $payload = [
                'service_account_json' => $serviceAccountJson,
                'accesstoken'          => null,
                'datetime'             => date('Y-m-d H:i:s'),
            ];
            if ($existing) {
                Capsule::table('mod_smartersxconnect_notification_credentials')->where('id', $existing->id)->update($payload);
            } else {
                Capsule::table('mod_smartersxconnect_notification_credentials')->insert($payload);
            }
        }
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true];
}

// Helper: base64Url encode
function smartersxconnect_base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Helper: generate OAuth2 access token from service account JSON (FCMSmartersxconnect_CRED constant expected)
function smartersxconnect_generateAccessToken($serviceAccount) {
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $iat = time();
    $exp = $iat + 3600;
    $payload = [
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $iat,
        'exp' => $exp
    ];

    $base64UrlHeader = smartersxconnect_base64UrlEncode(json_encode($header));
    $base64UrlPayload = smartersxconnect_base64UrlEncode(json_encode($payload));
    $signature = '';
    openssl_sign($base64UrlHeader . '.' . $base64UrlPayload, $signature, $serviceAccount['private_key'], 'SHA256');
    $base64UrlSignature = smartersxconnect_base64UrlEncode($signature);
    $jwt = $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Do NOT set CURLOPT_FAILONERROR — Google returns error details as JSON on 4xx,
    // and we need to read that body to log the actual reason token generation failed.
    $response = curl_exec($ch);
    if ($response === false) {
        error_log('SmartersxConnect OAuth token request failed: ' . curl_error($ch));
        $response = null;
    }
    curl_close($ch);
    $responseData = json_decode($response ?? '', true);
    if (isset($responseData['access_token'])) {
        try {
            require_once __DIR__ . '/lib/FirebaseAuth.php';
            $expiresAt = time() + (int)($responseData['expires_in'] ?? 3600);
            \FirebaseAuth::saveFcmAccessToken($responseData['access_token'], $expiresAt);
        } catch (\Throwable $_) {}
    }
    return $responseData['access_token'] ?? null;
}

// Send FCM notification using HTTP v1 API. Accepts optional devices list to target.
function smartersxconnect_sendFCMNotification($title, $body, $data = [], $devicesOverride = null) {
    try {
        global $CONFIG;
        smartersxconnect_ensure_notification_infrastructure_tables();
        $title = ($CONFIG['CompanyName'] ?? '') . ' - ' . $title;
        $serviceAccount = smartersxconnect_get_service_account_credentials();
        if (!is_array($serviceAccount) || empty($serviceAccount['project_id']) || empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
            $credentialError = $GLOBALS['smartersxconnect_fcm_credential_error'] ?? 'Missing FCM service account';
            Capsule::table('mod_smartersxconnect_notification_logs')->insert(['request' => 'Missing FCM service account', 'response' => $credentialError, 'type' => $title, 'datetime' => date('Y-m-d H:i:s')]);
            return json_encode(['error' => ['message' => $credentialError]]);
        }

        $devices = $devicesOverride;
        if ($devices === null) {
            $devices = Capsule::table('mod_smartersxconnect_notification_devices')->where('status', 1)->where('devicetoken', '!=', '')->get();
        }
        if (!$devices || count($devices) == 0) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert(['request' => 'No devices found', 'response' => 'No devices found', 'type' => $title, 'datetime' => date('Y-m-d H:i:s')]);
            return;
        }

        // obtain access token — use cached if still valid, otherwise regenerate
        require_once __DIR__ . '/lib/FirebaseAuth.php';
        $accessToken = \FirebaseAuth::getFcmAccessToken() ?? smartersxconnect_generateAccessToken($serviceAccount);
        $projectId = $serviceAccount['project_id'] ?? null;
        if (!$accessToken || !$projectId) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert(['request' => 'No access token or project id', 'response' => 'No access token or project id', 'type' => $title, 'datetime' => date('Y-m-d H:i:s')]);
            return;
        }

        $lastResponse = null;
        $message = null;
        for ($i = 0; $i < count($devices); $i++) {
            $device = $devices[$i];
            // if filtering by payment_alerts requested and device has column, respect it
            if (isset($data['__filter_by']) && $data['__filter_by'] === 'payment_alerts') {
                if (isset($device->payment_alerts) && intval($device->payment_alerts) !== 1) {
                    continue;
                }
            }
            $deviceToken = $device->devicetoken;
            $deviceProjectId = (string) ($device->fcm_project_id ?? '');
            $deviceIosBundleId = (string) ($device->ios_bundle_id ?? '');
            // Strip internal routing keys before sending to FCM
            $fcmData = array_merge(
                ['click_action' => 'FLUTTER_NOTIFICATION_CLICK', 'id' => (string) ($data['id'] ?? '0')],
                array_diff_key($data, ['__filter_by' => true])
            );
            $message = [
                'message' => [
                    'token'        => $deviceToken,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => $fcmData,
                    'android'      => [
                        'priority'     => 'HIGH',
                        'notification' => [
                            'sound'                 => 'default',
                            'notification_priority' => 'PRIORITY_HIGH',
                        ],
                    ],
                    'apns'         => [
                        'headers' => ['apns-priority' => '10'],
                        'payload' => [
                            'aps' => [
                                'alert' => ['title' => $title, 'body' => $body],
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ],
                ],
            ];
            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
            $headers = ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            // Do NOT set CURLOPT_FAILONERROR — FCM returns structured error JSON on 4xx
            // (e.g. UNREGISTERED, INVALID_ARGUMENT) which we need to log.
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $response = curl_exec($ch);
            if ($response === false) {
                $lastResponse = 'curl error: ' . curl_error($ch);
            } else {
                $lastResponse = $response;
                $responseData = json_decode($response, true);
                $errorCode = $responseData['error']['details'][0]['errorCode']
                    ?? $responseData['error']['status']
                    ?? '';
                if ($errorCode === 'THIRD_PARTY_AUTH_ERROR') {
                    $responseData['error']['local_hint'] =
                        'Firebase could not authenticate with APNs for this iOS app. '
                        . 'In Firebase Console, open Project Settings > Cloud Messaging and upload a valid APNs auth key or certificate for the iOS app bundle ID. '
                        . 'Also verify the iOS bundle ID matches the Firebase iOS app and Apple Developer push capability.';
                    $responseData['error']['smartersx_debug'] = [
                        'firebase_project_id' => (string) $projectId,
                        'device_fcm_project_id' => $deviceProjectId,
                        'device_fcm_app_id' => (string) ($device->fcm_app_id ?? ''),
                        'device_ios_bundle_id' => $deviceIosBundleId,
                        'configured_ios_bundle_id' => $configuredIosBundleId,
                    ];
                    $lastResponse = json_encode($responseData);
                }
                if ($errorCode === 'SENDER_ID_MISMATCH' && !empty($device->id)) {
                    Capsule::table('mod_smartersxconnect_notification_devices')
                        ->where('id', $device->id)
                        ->update(['devicetoken' => '']);
                }
            }
            curl_close($ch);
        }

        Capsule::table('mod_smartersxconnect_notification_logs')->insert(['request' => json_encode($message), 'response' => $lastResponse == '' ? 'No response' : $lastResponse, 'type' => $title, 'datetime' => date('Y-m-d H:i:s')]);
        return $lastResponse;
    } catch (\Throwable $th) {
        return $th->getMessage();
    }
}

// Expose a lightweight sync endpoint for devices to register fcm token and prefs.
if (isset($_GET['action']) && $_GET['action'] === 'syncDeviceSettings') {
    header('Content-Type: application/json');
    try {
        smartersxconnect_ensure_notification_infrastructure_tables();
        $enable         = isset($_POST['enable_payment_alerts']) ? intval($_POST['enable_payment_alerts']) : null;
        $fcm            = isset($_POST['fcm_token']) ? trim((string) $_POST['fcm_token']) : null;
        $fcmProjectId   = isset($_POST['fcm_project_id']) ? trim((string) $_POST['fcm_project_id']) : null;
        $fcmSenderId    = isset($_POST['fcm_sender_id']) ? trim((string) $_POST['fcm_sender_id']) : null;
        $fcmAppId       = isset($_POST['fcm_app_id']) ? trim((string) $_POST['fcm_app_id']) : null;
        $iosBundleId    = isset($_POST['ios_bundle_id']) ? trim((string) $_POST['ios_bundle_id']) : null;
        $clearFcmToken  = isset($_POST['clear_fcm_token']) && (string) $_POST['clear_fcm_token'] === '1';
        $deviceTableId  = isset($_POST['deviceTableId']) ? (int) $_POST['deviceTableId'] : null;
        $mobileDeviceId = isset($_POST['mobileDeviceId']) ? trim((string) $_POST['mobileDeviceId']) : null;

        $rawToken = null;
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = trim($_SERVER['HTTP_AUTHORIZATION']);
            if (stripos($header, 'Bearer ') === 0) {
                $rawToken = substr($header, 7);
            }
        }
        if ($rawToken === null && !empty($_REQUEST['api_token'])) {
            $rawToken = trim($_REQUEST['api_token']);
        }
        if ($rawToken === null || $rawToken === '') {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }

        $hash = hash('sha256', $rawToken);
        $tokenRecord = Capsule::table('mod_smartersxconnect_tokens')
            ->where('token_hash', $hash)
            ->where('revoked', 0)
            ->first();
        if (!$tokenRecord) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }

        if (!$deviceTableId && !$mobileDeviceId) {
            echo json_encode(['error' => 'missing_device_identifier']);
            exit;
        }

        $ownedDevice = null;
        if ($deviceTableId) {
            $ownedDevice = Capsule::table('mod_smartersxconnect_devices')->where('id', $deviceTableId)->first();
        } elseif ($mobileDeviceId) {
            $ownedDevice = Capsule::table('mod_smartersxconnect_devices')->where('device_id', $mobileDeviceId)->first();
        }

        if (!$ownedDevice) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        if (!empty($tokenRecord->label) && strpos((string) $tokenRecord->label, 'paired-device:') === 0) {
            $tokenDeviceId = (int) substr((string) $tokenRecord->label, strlen('paired-device:'));
            if ($tokenDeviceId > 0 && (int) $ownedDevice->id !== $tokenDeviceId) {
                http_response_code(403);
                echo json_encode(['error' => 'forbidden']);
                exit;
            }
        }

        $query = Capsule::table('mod_smartersxconnect_notification_devices');
        if ($deviceTableId) {
            $row = $query->where('device_table_id', $deviceTableId)->first();
        } else {
            $row = $query->where('mobile_device_id', $mobileDeviceId)->first();
        }

        // Read global notifications toggle to return to the mobile app.
        $globalNotificationsEnabled = Capsule::table('tblconfiguration')
            ->where('setting', 'smartersx_notifications_enabled')
            ->value('value');
        // Default to enabled if never set.
        $globalEnabled = ($globalNotificationsEnabled === null || (string) $globalNotificationsEnabled === '1');

        if ($row) {
            $update = [];
            if ($clearFcmToken) $update['devicetoken'] = '';
            if ($fcm !== null) $update['devicetoken'] = $fcm;
            if ($fcmProjectId !== null) $update['fcm_project_id'] = $fcmProjectId;
            if ($fcmSenderId !== null) $update['fcm_sender_id'] = $fcmSenderId;
            if ($fcmAppId !== null) $update['fcm_app_id'] = $fcmAppId;
            if ($iosBundleId !== null) $update['ios_bundle_id'] = $iosBundleId;
            if ($enable !== null) $update['payment_alerts'] = $enable;
            if (!empty($update)) {
                $query->where('id', $row->id)->update($update);
            }
            echo json_encode(['status' => 'updated', 'global_notifications_enabled' => $globalEnabled]);
            exit;
        }

        // create new device record
        $insert = [
            'devicetoken' => $fcm ?? '',
            'status' => 1,
            'datetime' => date('Y-m-d H:i:s'),
        ];
        if ($deviceTableId) $insert['device_table_id'] = $deviceTableId;
        if ($mobileDeviceId) $insert['mobile_device_id'] = $mobileDeviceId;
        if ($fcmProjectId !== null) $insert['fcm_project_id'] = $fcmProjectId;
        if ($fcmSenderId !== null) $insert['fcm_sender_id'] = $fcmSenderId;
        if ($fcmAppId !== null) $insert['fcm_app_id'] = $fcmAppId;
        if ($iosBundleId !== null) $insert['ios_bundle_id'] = $iosBundleId;
        if ($enable !== null) $insert['payment_alerts'] = $enable;
        Capsule::table('mod_smartersxconnect_notification_devices')->insert($insert);
        echo json_encode(['status' => 'created', 'global_notifications_enabled' => $globalEnabled]);
        exit;
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
