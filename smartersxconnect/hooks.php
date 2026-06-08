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

add_hook('ClientLogin', 1, function ($vars) {
    // Example: update last_seen for devices when client logs in
    // $userId = $vars['userid'] ?? null;
    return;
});

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
            'amount'         => number_format($rawAmount, 2),
            'event'          => 'payment_received',
            '__filter_by'    => 'payment_alerts',
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
    if (!Capsule::schema()->hasTable('mod_smartersxconnect_notification_devices')) {
        Capsule::schema()->create('mod_smartersxconnect_notification_devices', function ($table) {
            $table->increments('id');
            $table->string('devicetoken', 255)->default('');
            $table->boolean('status')->default(true);
            $table->integer('device_table_id')->nullable();
            $table->string('mobile_device_id', 128)->nullable();
            $table->boolean('payment_alerts')->default(true);
            $table->timestamp('datetime')->nullable();
        });
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

function smartersxconnect_invoice_transaction_id($invoiceId)
{
    $transactionId = Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->orderBy('date', 'desc')
        ->value('transid');

    return $transactionId ? (string) $transactionId : '';
}

function smartersxconnect_get_service_account_credentials()
{
    try {
        smartersxconnect_ensure_notification_infrastructure_tables();
        $row = Capsule::table('mod_smartersxconnect_notification_credentials')->orderBy('id', 'asc')->first();
        if ($row && !empty($row->service_account_json)) {
            $decoded = json_decode($row->service_account_json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    } catch (\Throwable $e) {
        // fall through to the legacy constant below
    }

    if (defined('FCMSmartersxconnect_CRED')) {
        $decoded = json_decode(FCMSmartersxconnect_CRED, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
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

    smartersxconnect_ensure_notification_infrastructure_tables();
    $payload = [
        'accesstoken' => null,
        'service_account_json' => $serviceAccountJson,
        'datetime' => date('Y-m-d H:i:s'),
    ];

    $row = Capsule::table('mod_smartersxconnect_notification_credentials')->orderBy('id', 'asc')->first();
    if ($row) {
        Capsule::table('mod_smartersxconnect_notification_credentials')
            ->where('id', $row->id)
            ->update($payload);
    } else {
        Capsule::table('mod_smartersxconnect_notification_credentials')->insert($payload);
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
        // persist token in credentials table if available
        try {
            Capsule::table('mod_smartersxconnect_notification_credentials')->update(['accesstoken' => $responseData['access_token'], 'datetime' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $_) {}
    }
    return $responseData['access_token'] ?? null;
}

// Send FCM notification using HTTP v1 API. Accepts optional devices list to target.
function smartersxconnect_sendFCMNotification($title, $body, $data = [], $devicesOverride = null) {
    try {
        global $CONFIG;
        smartersxconnect_ensure_notification_infrastructure_tables();
        $servercredentials = Capsule::table('mod_smartersxconnect_notification_credentials')->first();
        $title = ($CONFIG['CompanyName'] ?? '') . ' - ' . $title;
        $serviceAccount = smartersxconnect_get_service_account_credentials();
        if (!is_array($serviceAccount) || empty($serviceAccount['project_id']) || empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert(['request' => 'Missing FCM service account', 'response' => 'Missing FCM service account', 'type' => $title, 'datetime' => date('Y-m-d H:i:s')]);
            return;
        }

        $devices = $devicesOverride;
        if ($devices === null) {
            $devices = Capsule::table('mod_smartersxconnect_notification_devices')->where('status', 1)->where('devicetoken', '!=', '')->get();
        }
        if (!$devices || count($devices) == 0) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert(['request' => 'No devices found', 'response' => 'No devices found', 'type' => $title, 'datetime' => date('Y-m-d H:i:s')]);
            return;
        }

        // obtain access token — regenerate only if older than 55 minutes (tokens last 60 min)
        $tokenAge = $servercredentials && $servercredentials->accesstoken
            ? (time() - strtotime($servercredentials->datetime))
            : PHP_INT_MAX;
        if ($tokenAge >= 3300) {
            $accessToken = smartersxconnect_generateAccessToken($serviceAccount);
        } else {
            $accessToken = $servercredentials->accesstoken;
        }
        $projectId = $serviceAccount['project_id'] ?? null;
        if (!$accessToken || !$projectId) {
            Capsule::table('mod_smartersxconnect_notification_logs')->insert(['request' => 'No access token or project id', 'response' => 'No access token or project id', 'type' => $title, 'datetime' => date('Y-m-d H:i:s')]);
            return;
        }

        $lastResponse = null;
        for ($i = 0; $i < count($devices); $i++) {
            $device = $devices[$i];
            // if filtering by payment_alerts requested and device has column, respect it
            if (isset($data['__filter_by']) && $data['__filter_by'] === 'payment_alerts') {
                if (isset($device->payment_alerts) && intval($device->payment_alerts) !== 1) {
                    continue;
                }
            }
            $deviceToken = $device->devicetoken;
            // Strip internal routing keys before sending to FCM
            $fcmData = array_merge(
                ['click_action' => 'FLUTTER_NOTIFICATION_CLICK', 'id' => (string) ($data['id'] ?? '0')],
                array_diff_key($data, ['__filter_by' => true])
            );
            $message = ['message' => ['token' => $deviceToken, 'notification' => ['title' => $title, 'body' => $body], 'data' => $fcmData]];
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
        $enable = isset($_POST['enable_payment_alerts']) ? intval($_POST['enable_payment_alerts']) : null;
        $fcm = $_POST['fcm_token'] ?? null;
        $deviceTableId = $_POST['deviceTableId'] ?? null;
        $mobileDeviceId = $_POST['mobileDeviceId'] ?? null;

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
            if ($fcm !== null) $update['devicetoken'] = $fcm;
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
        if ($enable !== null) $insert['payment_alerts'] = $enable;
        Capsule::table('mod_smartersxconnect_notification_devices')->insert($insert);
        echo json_encode(['status' => 'created', 'global_notifications_enabled' => $globalEnabled]);
        exit;
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
