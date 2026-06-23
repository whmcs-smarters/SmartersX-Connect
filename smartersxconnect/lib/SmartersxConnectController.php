<?php

use WHMCS\Database\Capsule;
use WHMCS\Config\Setting;


class SmartersxConnectController
{
    public static function clientarea($vars)
    {
        header('Content-Type: application/json; charset=utf-8');
        // Allow unauthenticated pairRequest so mobile can scan QR and be paired when admin shows QR
        $action = $_REQUEST['action'] ?? '';
        if ($action === 'pairRequest') {
            // lightweight unauthenticated pairing flow: if pairing is system-level (userid=0), auto-authorize
            $pairing = trim($_REQUEST['pairing_code'] ?? '');
            $deviceId = trim($_REQUEST['deviceId'] ?? '');
            if ($pairing === '' || $deviceId === '') {
                http_response_code(400);
                echo json_encode(['error' => 'pairing_code and deviceId required']);
                exit;
            }
            $rec = Capsule::table('mod_smartersxconnect_pairs')->where('pairing_code', $pairing)->first();
            if (!$rec || $rec->state === 'expired') {
                http_response_code(404);
                echo json_encode(['error' => 'pairing not found or has expired. Please regenerate the QR code.']);
                exit;
            }
            // If system-level pairing (created from admin UI), auto-authorize and create device+token
            if ((int) $rec->userid === 0) {
                $label = trim($_REQUEST['label'] ?? '') ?: null;

                // Reuse existing device record for this mobile device_id to avoid duplicates
                // on re-scan or reconnect after module reactivation.
                $existingDevice = Capsule::table('mod_smartersxconnect_devices')
                    ->where('device_id', $deviceId)
                    ->first();

                if ($existingDevice) {
                    $deviceIdDb = $existingDevice->id;
                    if ($label) {
                        Capsule::table('mod_smartersxconnect_devices')
                            ->where('id', $deviceIdDb)
                            ->update(['label' => $label]);
                    }
                } else {
                    $deviceIdDb = Capsule::table('mod_smartersxconnect_devices')->insertGetId([
                        'userid' => 0,
                        'device_id' => $deviceId,
                        'label' => $label,
                        'meta' => json_encode(['paired_via' => 'qr']),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                // Revoke any old tokens for this device before issuing a new one.
                Capsule::table('mod_smartersxconnect_tokens')
                    ->where('label', 'paired-device:' . $deviceIdDb)
                    ->update(['revoked' => 1]);

                $tokenResult = self::createApiToken(0, 'paired-device', $deviceIdDb);
                Capsule::table('mod_smartersxconnect_pairs')->where('id', $rec->id)->update(['device_id' => $deviceId, 'state' => 'authorized', 'authorized_at' => date('Y-m-d H:i:s')]);
                echo json_encode(['status' => 'ok', 'token' => $tokenResult['token'], 'device_id' => $deviceIdDb, 'device_table_id' => $deviceIdDb]);
                exit;
            }
        }

        // Determine authenticated user id via API token only (no session)
        $userId = null;
        $apiToken = null;
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $h = trim($_SERVER['HTTP_AUTHORIZATION']);
            if (stripos($h, 'Bearer ') === 0) {
                $apiToken = substr($h, 7);
            }
        }
        if (empty($apiToken) && !empty($_REQUEST['api_token'])) {
            $apiToken = trim($_REQUEST['api_token']);
        }

        if (!empty($apiToken)) {
            $tokenRecord = self::validateApiToken($apiToken);
            if ($tokenRecord) {
                $userId = (int) $tokenRecord->userid;
            }
        }

        // Public actions — no auth required
        $action = $_REQUEST['action'] ?? '';
        if ($action === 'getFirebaseConfig') {
            self::ensureFirebaseConfigTable();
            $fbRow = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
            $androidOptions = null;
            $iosOptions     = null;
            if ($fbRow && !empty($fbRow->android_google_services_json)) {
                $androidOptions = self::extractAndroidFirebaseOptions($fbRow->android_google_services_json);
            }
            if ($fbRow && !empty($fbRow->ios_google_service_plist)) {
                $iosOptions = self::extractIosFirebaseOptions($fbRow->ios_google_service_plist);
            }
            // Fallback: if DB config not set (Google account not connected), return hardcoded defaults
            if ($androidOptions === null) {
                $androidOptions = [
                    'apiKey'            => 'AIzaSyDzS-nO60pTycFAR-6Koa1PK3psMofqpQE',
                    'appId'             => '1:563298210802:android:220d049cfb976a2a3dd16b',
                    'messagingSenderId' => '563298210802',
                    'projectId'         => 'smarterxtest',
                    'storageBucket'     => 'smarterxtest.firebasestorage.app',
                ];
            }
            if ($iosOptions === null) {
                $iosOptions = [
                    'apiKey'            => 'AIzaSyCin7sDdyesPjPrGWj6wuHEOFqA1Q-hLHM',
                    'appId'             => '1:563298210802:ios:6437abca34f19d163dd16b',
                    'messagingSenderId' => '563298210802',
                    'projectId'         => 'smarterxtest',
                    'storageBucket'     => 'smarterxtest.firebasestorage.app',
                    'bundleId'          => 'com.smarters.managex',
                ];
            }
            echo json_encode(['android' => $androidOptions, 'ios' => $iosOptions]);
            exit;
        }

        if ($userId === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        switch ($action) {
            case 'pairRequest':
                $pairing = trim($_REQUEST['pairing_code'] ?? '');
                $deviceId = trim($_REQUEST['deviceId'] ?? '');
                $label = trim($_REQUEST['label'] ?? '');
                if ($pairing === '' || $deviceId === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'pairing_code and deviceId required']);
                    break;
                }
                $rec = Capsule::table('mod_smartersxconnect_pairs')->where('pairing_code', $pairing)->first();
                if (!$rec) {
                    http_response_code(404);
                    echo json_encode(['error' => 'pairing not found']);
                    break;
                }
                if ((int) $rec->userid !== (int) $userId) {
                    http_response_code(403);
                    echo json_encode(['error' => 'pairing does not belong to this account']);
                    break;
                }
                Capsule::table('mod_smartersxconnect_pairs')->where('id', $rec->id)->update(['device_id' => $deviceId, 'state' => 'requested', 'requested_at' => date('Y-m-d H:i:s')]);
                echo json_encode(['status' => 'requested', 'message' => 'Pairing requested. Please authorize from this device.']);
                break;

            case 'authorizeDevice':
                $pairing = trim($_REQUEST['pairing_code'] ?? '');
                $deviceId = trim($_REQUEST['deviceId'] ?? '');
                if ($pairing === '' || $deviceId === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'pairing_code and deviceId required']);
                    break;
                }
                $rec = Capsule::table('mod_smartersxconnect_pairs')->where('pairing_code', $pairing)->where('device_id', $deviceId)->first();
                if (!$rec || $rec->state !== 'requested') {
                    http_response_code(400);
                    echo json_encode(['error' => 'invalid or unrequested pairing']);
                    break;
                }
                $label = trim($_REQUEST['label'] ?? '') ?: null;
                $deviceIdDb = Capsule::table('mod_smartersxconnect_devices')->insertGetId([
                    'userid' => $rec->userid,
                    'device_id' => $deviceId,
                    'label' => $label,
                    'meta' => json_encode(['paired_via' => 'qr']),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $tokenResult = self::createApiToken($rec->userid, 'paired-device', $deviceIdDb);
                Capsule::table('mod_smartersxconnect_pairs')->where('id', $rec->id)->update(['state' => 'authorized', 'authorized_at' => date('Y-m-d H:i:s')]);
                echo json_encode(['status' => 'ok', 'token' => $tokenResult['token'], 'device_id' => $deviceIdDb, 'device_table_id' => $deviceIdDb]);
                break;

            case 'listDevices':
                $page = max(1, (int) ($_REQUEST['page'] ?? 1));
                $pageSize = min(100, max(1, (int) ($_REQUEST['pageSize'] ?? 20)));
                $offset = ($page - 1) * $pageSize;
                $devices = Capsule::table('mod_smartersxconnect_devices')
                    ->where('userid', $userId)
                    ->orderBy('created_at', 'desc')
                    ->offset($offset)
                    ->limit($pageSize)
                    ->get();
                echo json_encode(['data' => $devices, 'page' => $page, 'pageSize' => $pageSize]);
                break;

            case 'connectDevice':
                $deviceId = trim($_REQUEST['deviceId'] ?? '');
                $qrData = trim($_REQUEST['qrData'] ?? '');
                $label = trim($_REQUEST['label'] ?? '');
                if ($deviceId === '' && $qrData === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'deviceId or qrData required']);
                    break;
                }
                if ($deviceId === '' && $qrData !== '') {
                    $deviceId = $qrData;
                }
                $exists = Capsule::table('mod_smartersxconnect_devices')->where('device_id', $deviceId)->first();
                if ($exists) {
                    echo json_encode(['status' => 'exists', 'id' => $exists->id]);
                    break;
                }
                $id = Capsule::table('mod_smartersxconnect_devices')->insertGetId([
                    'userid' => $userId,
                    'device_id' => $deviceId,
                    'label' => $label ?: null,
                    'meta' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                echo json_encode(['status' => 'ok', 'id' => $id]);
                break;

            case 'deleteDevice':
                $id = (int) ($_REQUEST['id'] ?? 0);
                if ($id <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'id required']);
                    break;
                }
                $deleted = Capsule::table('mod_smartersxconnect_devices')->where('id', $id)->where('userid', $userId)->delete();
                if ($deleted) {
                    Capsule::table('mod_smartersxconnect_tokens')->where('label', 'paired-device:' . $id)->update(['revoked' => 1]);
                }
                echo json_encode(['status' => $deleted ? 'ok' : 'not_found']);
                break;

            case 'logoutDevice':
                $deviceTableId = self::deviceTableIdFromTokenRecord($tokenRecord);
                if ($deviceTableId <= 0) {
                    $deviceTableId = (int) ($_REQUEST['deviceTableId'] ?? $_REQUEST['device_id'] ?? 0);
                }
                $mobileDeviceId = trim($_REQUEST['mobileDeviceId'] ?? '');
                if ($deviceTableId <= 0 && $mobileDeviceId === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'device id required']);
                    break;
                }

                $deviceQuery = Capsule::table('mod_smartersxconnect_devices');
                if ($deviceTableId > 0) {
                    $deviceQuery->where('id', $deviceTableId);
                } else {
                    $deviceQuery->where('device_id', $mobileDeviceId);
                }
                if ((int) $userId > 0) {
                    $deviceQuery->where('userid', $userId);
                }
                $device = $deviceQuery->first();
                if (!$device) {
                    Capsule::table('mod_smartersxconnect_tokens')->where('id', $tokenRecord->id)->update(['revoked' => 1]);
                    echo json_encode(['status' => 'not_found']);
                    break;
                }

                Capsule::table('mod_smartersxconnect_devices')->where('id', $device->id)->delete();
                Capsule::table('mod_smartersxconnect_tokens')->where('label', 'paired-device:' . $device->id)->update(['revoked' => 1]);
                Capsule::table('mod_smartersxconnect_tokens')->where('id', $tokenRecord->id)->update(['revoked' => 1]);
                Capsule::table('mod_smartersxconnect_pairs')->where('device_id', $device->device_id)->delete();
                if (Capsule::schema()->hasTable('mod_smartersxconnect_notification_devices')) {
                    Capsule::table('mod_smartersxconnect_notification_devices')
                        ->where('device_table_id', $device->id)
                        ->orWhere('mobile_device_id', $device->device_id)
                        ->delete();
                }

                echo json_encode(['status' => 'ok']);
                break;

            case 'validateDevice':
                $deviceTableId = (int) ($_REQUEST['deviceTableId'] ?? $_REQUEST['device_id'] ?? 0);
                $mobileDeviceId = trim($_REQUEST['mobileDeviceId'] ?? '');
                if ($deviceTableId <= 0 && $mobileDeviceId === '') {
                    echo json_encode(['status' => 'ok']);
                    break;
                }
                $existsQuery = Capsule::table('mod_smartersxconnect_devices');
                if ($deviceTableId > 0) {
                    $existsQuery->where('id', $deviceTableId);
                } else {
                    $existsQuery->where('device_id', $mobileDeviceId);
                }
                $exists = $existsQuery->first();
                if (!$exists) {
                    http_response_code(401);
                    echo json_encode(['error' => 'Device disconnected']);
                    break;
                }
                echo json_encode(['status' => 'ok']);
                break;

            case 'testNotification':
                // Send a real test FCM notification to the requesting device only.
                if (!Capsule::schema()->hasTable('mod_smartersxconnect_notification_devices')) {
                    echo json_encode(['error' => 'notification_not_configured']);
                    break;
                }
                $notifDeviceQuery = Capsule::table('mod_smartersxconnect_notification_devices')
                    ->where('status', 1)
                    ->where('devicetoken', '!=', '');
                $testDeviceTableId = self::deviceTableIdFromTokenRecord($tokenRecord);
                if ($testDeviceTableId > 0) {
                    $notifDeviceQuery->where('device_table_id', $testDeviceTableId);
                } else {
                    $testMobileDeviceId = trim($_REQUEST['mobileDeviceId'] ?? '');
                    if ($testMobileDeviceId !== '') {
                        $notifDeviceQuery->where('mobile_device_id', $testMobileDeviceId);
                    } else {
                        echo json_encode(['error' => 'device_not_registered']);
                        break;
                    }
                }
                $notifDevice = $notifDeviceQuery->first();
                if (!$notifDevice) {
                    echo json_encode(['error' => 'device_not_registered']);
                    break;
                }
                self::loadNotificationHelpers();
                if (!function_exists('smartersxconnect_sendFCMNotification')) {
                    echo json_encode(['error' => 'notification_not_configured']);
                    break;
                }
                $fcmRaw = smartersxconnect_sendFCMNotification(
                    'Test Notification',
                    'This is a test notification from WHMCS.',
                    ['id' => 'test_notification', 'event' => 'test_notification'],
                    [$notifDevice]
                );
                $fcmDecoded = is_string($fcmRaw) ? json_decode($fcmRaw, true) : null;
                if (is_array($fcmDecoded) && isset($fcmDecoded['error'])) {
                    $errCode = $fcmDecoded['error']['details'][0]['errorCode']
                        ?? $fcmDecoded['error']['status']
                        ?? $fcmDecoded['error']['message']
                        ?? 'FCM_ERROR';
                    echo json_encode(['status' => 'fcm_error', 'fcm_error' => $errCode, 'fcm_raw' => $fcmRaw]);
                } elseif (is_array($fcmDecoded) && isset($fcmDecoded['name'])) {
                    echo json_encode(['status' => 'sent', 'fcm_message_id' => $fcmDecoded['name']]);
                } else {
                    echo json_encode(['status' => 'sent', 'fcm_raw' => $fcmRaw]);
                }
                break;

            case 'transactionTotals':
                $period = $_REQUEST['period'] ?? 'year';
                $currency = self::defaultCurrency();
                echo json_encode([
                    'period' => $period,
                    'total' => self::getIncomeTotalForPeriod($userId, $period),
                    'currency_code' => $currency['code'],
                    'currency_prefix' => $currency['prefix'],
                    'currency_suffix' => $currency['suffix'],
                ]);
                break;

            case 'listClientTransactions':
                $clientId = (int) trim($_REQUEST['clientId'] ?? $_REQUEST['userId'] ?? '');
                if ($clientId <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'clientId required']);
                    break;
                }
                if ((int) $userId > 0 && (int) $clientId !== (int) $userId) {
                    http_response_code(403);
                    echo json_encode(['error' => 'forbidden']);
                    break;
                }
                $pageSize = min(100, max(1, (int) ($_REQUEST['pageSize'] ?? 20)));

                $query = Capsule::table('tblaccounts')
                    ->leftJoin('tblclients', 'tblclients.id', '=', 'tblaccounts.userid')
                    ->select(
                        'tblaccounts.id',
                        'tblaccounts.userid',
                        'tblaccounts.invoiceid',
                        'tblaccounts.date',
                        'tblaccounts.gateway',
                        'tblaccounts.description',
                        'tblaccounts.amountin',
                        'tblaccounts.amountout',
                        'tblaccounts.fees',
                        'tblaccounts.rate',
                        'tblaccounts.transid',
                        'tblclients.firstname',
                        'tblclients.lastname',
                        'tblclients.companyname'
                    )
                    ->where('tblaccounts.userid', $clientId);

                $totalCount = (clone $query)->count();

                $totalAmount = 0.0;
                foreach ((clone $query)->get() as $transaction) {
                    $totalAmount += self::transactionAmountValue($transaction);
                }

                $rows = $query->orderBy('tblaccounts.date', 'desc')
                    ->orderBy('tblaccounts.id', 'desc')
                    ->offset(($page - 1) * $pageSize)
                    ->limit($pageSize)
                    ->get();

                $currency = self::defaultCurrency();
                $items = [];
                foreach ($rows as $transaction) {
                    $items[] = self::mapTransactionForMobile($transaction);
                }

                echo json_encode([
                    'debug_source'     => 'tblaccounts.userid',
                    'debug_client_id'  => $clientId,
                    'data'            => $items,
                    'page'            => $page,
                    'pageSize'        => $pageSize,
                    'total'           => $totalCount,
                    'total_amount'    => $totalAmount,
                    'currency_code'   => $currency['code'],
                    'currency_prefix' => $currency['prefix'],
                    'currency_suffix' => $currency['suffix'],
                ]);
                break;

            case 'listTransactions':
                $period = $_REQUEST['period'] ?? 'year';
                $page = max(1, (int) ($_REQUEST['page'] ?? 1));
                $pageSize = min(100, max(1, (int) ($_REQUEST['pageSize'] ?? 20)));
                $query = self::transactionBaseQuery($userId);
                self::applyTransactionFilters($query, $period);
                $clientId = trim($_REQUEST['clientId'] ?? $_REQUEST['userId'] ?? '');
                if ($clientId !== '') {
                    $query->where('tblaccounts.userid', (int) $clientId);
                }
                $search = trim($_REQUEST['search'] ?? '');
                if ($search !== '') {
                    $query->where(function ($q) use ($search) {
                        $q->where('tblaccounts.id', 'like', '%' . $search . '%')
                            ->orWhere('tblaccounts.userid', 'like', '%' . $search . '%')
                            ->orWhere('tblaccounts.transid', 'like', '%' . $search . '%')
                            ->orWhere('tblaccounts.invoiceid', 'like', '%' . $search . '%')
                            ->orWhere('tblaccounts.description', 'like', '%' . $search . '%')
                            ->orWhere('tblclients.firstname', 'like', '%' . $search . '%')
                            ->orWhere('tblclients.lastname', 'like', '%' . $search . '%')
                            ->orWhere('tblclients.companyname', 'like', '%' . $search . '%');
                    });
                }
                if (isset($_REQUEST['minAmount']) && $_REQUEST['minAmount'] !== '') {
                    $query->whereRaw('(tblaccounts.amountin - tblaccounts.amountout) >= ?', [(float) $_REQUEST['minAmount']]);
                }
                if (isset($_REQUEST['maxAmount']) && $_REQUEST['maxAmount'] !== '') {
                    $query->whereRaw('(tblaccounts.amountin - tblaccounts.amountout) <= ?', [(float) $_REQUEST['maxAmount']]);
                }
                if (!empty($_REQUEST['fromDate'])) {
                    $query->whereDate('tblaccounts.date', '>=', $_REQUEST['fromDate']);
                }
                if (!empty($_REQUEST['toDate'])) {
                    $query->whereDate('tblaccounts.date', '<=', $_REQUEST['toDate']);
                }
                $totalCount = (clone $query)->count();
                $totalAmount = (clone $query)->get()->reduce(function ($sum, $transaction) {
                    return $sum + self::transactionAmountValue($transaction);
                }, 0.0);
                $items = $query->orderBy('tblaccounts.date', 'desc')->offset(($page - 1) * $pageSize)->limit($pageSize)->get()->map(function ($transaction) {
                    return self::mapTransactionForMobile($transaction);
                });
                $currency = self::defaultCurrency();
                echo json_encode([
                    'data' => $items,
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $totalCount,
                    'total_amount' => $totalAmount,
                    'currency_code' => $currency['code'],
                    'currency_prefix' => $currency['prefix'],
                    'currency_suffix' => $currency['suffix'],
                ]);
                break;

            case 'listTransactionGroups':
                $period = $_REQUEST['period'] ?? 'year';
                $groupBy = $_REQUEST['groupBy'] ?? 'day';
                $query = Capsule::table('tblaccounts');
                if ((int) $userId > 0) {
                    $query->where('userid', (int) $userId);
                }
                self::applyTransactionFilters($query, $period);
                $filterYear  = !empty($_REQUEST['year'])  ? (int) $_REQUEST['year']  : null;
                $filterMonth = !empty($_REQUEST['month']) ? (int) $_REQUEST['month'] : null;
                if ($filterYear !== null) {
                    $query->whereYear('date', $filterYear);
                }
                if ($filterMonth !== null) {
                    $query->whereMonth('date', $filterMonth);
                }

                $incomeExpr = 'SUM((amountin - fees - amountout) / IF(rate = 0, 1, rate)) as total_amount';
                $currency = self::defaultCurrency();

                if ($groupBy === 'year') {
                    $rows = $query
                        ->selectRaw('YEAR(date) as year, COUNT(*) as count, ' . $incomeExpr)
                        ->groupByRaw('YEAR(date)')
                        ->orderByRaw('YEAR(date) desc')
                        ->get();

                    $byYear = [];
                    foreach ($rows as $g) {
                        $byYear[(int) $g->year] = [
                            'year'            => (int) $g->year,
                            'count'           => (int) $g->count,
                            'total_amount'    => (float) $g->total_amount,
                            'currency_code'   => $currency['code'],
                            'currency_prefix' => $currency['prefix'],
                            'currency_suffix' => $currency['suffix'],
                        ];
                    }

                    $currentYear = (int) (new \DateTime())->format('Y');
                    $firstYear   = $byYear ? min(array_keys($byYear)) : $currentYear;

                    $data = [];
                    for ($y = $currentYear; $y >= $firstYear; $y--) {
                        $data[] = isset($byYear[$y]) ? $byYear[$y] : [
                            'year'            => $y,
                            'count'           => 0,
                            'total_amount'    => 0,
                            'currency_code'   => $currency['code'],
                            'currency_prefix' => $currency['prefix'],
                            'currency_suffix' => $currency['suffix'],
                        ];
                    }
                } elseif ($groupBy === 'month') {
                    $rows = $query
                        ->selectRaw('YEAR(date) as year, MONTH(date) as month, COUNT(*) as count, ' . $incomeExpr)
                        ->groupByRaw('YEAR(date), MONTH(date)')
                        ->orderByRaw('YEAR(date) desc, MONTH(date) desc')
                        ->get();

                    $byMonth = [];
                    foreach ($rows as $g) {
                        $byMonth[(int) $g->month] = [
                            'year'            => $g->year,
                            'month'           => (int) $g->month,
                            'count'           => (int) $g->count,
                            'total_amount'    => (float) $g->total_amount,
                            'currency_code'   => $currency['code'],
                            'currency_prefix' => $currency['prefix'],
                            'currency_suffix' => $currency['suffix'],
                        ];
                    }

                    $now = new \DateTime();
                    $targetYear     = $filterYear ?? (int) $now->format('Y');
                    $isCurrentYear  = ($targetYear === (int) $now->format('Y'));
                    $lastMonth      = $isCurrentYear ? (int) $now->format('n') : 12;

                    $data = [];
                    for ($m = $lastMonth; $m >= 1; $m--) {
                        $data[] = isset($byMonth[$m]) ? $byMonth[$m] : [
                            'year'            => $targetYear,
                            'month'           => $m,
                            'count'           => 0,
                            'total_amount'    => 0,
                            'currency_code'   => $currency['code'],
                            'currency_prefix' => $currency['prefix'],
                            'currency_suffix' => $currency['suffix'],
                        ];
                    }
                } else {
                    // day groupBy — fill all days of the target month with zeros where missing
                    $rows = $query
                        ->selectRaw('DATE(date) as day, YEAR(date) as year, MONTH(date) as month, COUNT(*) as count, ' . $incomeExpr)
                        ->groupByRaw('DATE(date), YEAR(date), MONTH(date)')
                        ->orderByRaw('DATE(date) desc')
                        ->get();

                    $byDay = [];
                    foreach ($rows as $g) {
                        $byDay[$g->day] = [
                            'day'             => $g->day,
                            'year'            => $g->year,
                            'month'           => $g->month,
                            'count'           => (int) $g->count,
                            'total_amount'    => (float) $g->total_amount,
                            'currency_code'   => $currency['code'],
                            'currency_prefix' => $currency['prefix'],
                            'currency_suffix' => $currency['suffix'],
                        ];
                    }

                    // Determine which month to fill
                    $now = new \DateTime();
                    $targetYear  = $filterYear  ?? (int) $now->format('Y');
                    $targetMonth = $filterMonth ?? (int) $now->format('n');
                    $isCurrentMonth = ($targetYear === (int) $now->format('Y') && $targetMonth === (int) $now->format('n'));
                    $lastDay = $isCurrentMonth
                        ? (int) $now->format('j')
                        : (int) (new \DateTime("$targetYear-$targetMonth-01"))->format('t');

                    $data = [];
                    for ($d = $lastDay; $d >= 1; $d--) {
                        $dateStr = sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, $d);
                        if (isset($byDay[$dateStr])) {
                            $data[] = $byDay[$dateStr];
                        } else {
                            $data[] = [
                                'day'             => $dateStr,
                                'year'            => $targetYear,
                                'month'           => $targetMonth,
                                'count'           => 0,
                                'total_amount'    => 0,
                                'currency_code'   => $currency['code'],
                                'currency_prefix' => $currency['prefix'],
                                'currency_suffix' => $currency['suffix'],
                            ];
                        }
                    }
                }

                echo json_encode(['data' => $data]);
                break;

            case 'getTransaction':
                $transactionId = trim($_REQUEST['invoiceId'] ?? $_REQUEST['transactionId'] ?? '');
                if ($transactionId === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'transactionId required']);
                    break;
                }
                $query = self::transactionBaseQuery($userId);
                $query->where(function ($q) use ($transactionId) {
                    $q->where('tblaccounts.id', $transactionId)->orWhere('tblaccounts.transid', $transactionId);
                });
                $transaction = $query->first();
                if (!$transaction) {
                    http_response_code(404);
                    echo json_encode(['error' => 'transaction not found']);
                    break;
                }
                echo json_encode(['data' => self::mapTransactionForMobile($transaction)]);
                break;

            case 'getClient':
                $clientId = trim($_REQUEST['clientId'] ?? '');
                if ($clientId === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'clientId required']);
                    break;
                }
                $clientQuery = Capsule::table('tblclients')->where('id', (int) $clientId);
                if ((int) $userId > 0) {
                    $clientQuery->where('id', (int) $userId);
                }
                $client = $clientQuery->first();
                if (!$client) {
                    http_response_code(404);
                    echo json_encode(['error' => 'client not found']);
                    break;
                }
                $clientCompany = trim((string) ($client->companyname ?? ''));
                $clientFullName = trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? ''));
                $clientDisplay = $clientCompany !== '' ? $clientCompany : ($clientFullName !== '' ? $clientFullName : 'Client #' . $client->id);
                echo json_encode(['data' => [
                    'clientId'   => (string) $client->id,
                    'fullName'   => $clientFullName,
                    'company'    => $clientCompany,
                    'displayName' => $clientDisplay,
                    'email'      => (string) ($client->email ?? ''),
                    'phone'      => (string) ($client->phonenumber ?? ''),
                    'address1'   => (string) ($client->address1 ?? ''),
                    'address2'   => (string) ($client->address2 ?? ''),
                    'city'       => (string) ($client->city ?? ''),
                    'state'      => (string) ($client->state ?? ''),
                    'postcode'   => (string) ($client->postcode ?? ''),
                    'country'    => (string) ($client->country ?? ''),
                    'status'     => (string) ($client->status ?? ''),
                    'createdAt'  => (string) ($client->datecreated ?? ''),
                ]]);
                break;

            case 'getInvoice':
                $invoiceId = trim($_REQUEST['invoiceId'] ?? '');
                if ($invoiceId === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'invoiceId required']);
                    break;
                }
                $query = Capsule::table('tblinvoices')
                    ->leftJoin('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
                    ->select(
                        'tblinvoices.id',
                        'tblinvoices.invoicenum',
                        'tblinvoices.userid',
                        'tblinvoices.date',
                        'tblinvoices.duedate',
                        'tblinvoices.subtotal',
                        'tblinvoices.tax',
                        'tblinvoices.tax2',
                        'tblinvoices.credit',
                        'tblinvoices.total',
                        'tblinvoices.status',
                        'tblinvoices.paymentmethod',
                        'tblclients.firstname',
                        'tblclients.lastname',
                        'tblclients.companyname'
                    )
                    ->where('tblinvoices.id', $invoiceId);
                if ((int) $userId > 0) {
                    $query->where('tblinvoices.userid', (int) $userId);
                }
                $invoice = $query->first();
                if (!$invoice) {
                    http_response_code(404);
                    echo json_encode(['error' => 'invoice not found']);
                    break;
                }
                $items = Capsule::table('tblinvoiceitems')
                    ->select('id', 'type', 'relid', 'description', 'amount', 'taxed', 'duedate', 'paymentmethod', 'notes')
                    ->where('invoiceid', $invoice->id)
                    ->orderBy('id', 'asc')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id'            => (string) $item->id,
                            'type'          => (string) ($item->type ?? ''),
                            'relid'         => (string) ($item->relid ?? ''),
                            'description'   => (string) ($item->description ?? ''),
                            'amount'        => (float) ($item->amount ?? 0),
                            'taxed'         => (bool) ($item->taxed ?? false),
                            'duedate'       => (string) ($item->duedate ?? ''),
                            'paymentmethod' => (string) ($item->paymentmethod ?? ''),
                            'notes'         => (string) ($item->notes ?? ''),
                        ];
                    })
                    ->values()
                    ->all();

                echo json_encode(['data' => self::mapInvoiceForMobile($invoice, $items)]);
                break;

            case 'getFirebaseConfig':
                self::ensureFirebaseConfigTable();
                $fbRow = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
                $androidOptions = null;
                $iosOptions     = null;
                if ($fbRow && !empty($fbRow->android_google_services_json)) {
                    $androidOptions = self::extractAndroidFirebaseOptions($fbRow->android_google_services_json);
                }
                if ($fbRow && !empty($fbRow->ios_google_service_plist)) {
                    $iosOptions = self::extractIosFirebaseOptions($fbRow->ios_google_service_plist);
                }
                echo json_encode([
                    'android' => $androidOptions,
                    'ios'     => $iosOptions,
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action']);
        }

        exit;
    }

    private static function transactionBaseQuery($userId)
    {
        $query = Capsule::table('tblaccounts')
            ->leftJoin('tblclients', 'tblclients.id', '=', 'tblaccounts.userid')
            ->select(
                'tblaccounts.id',
                'tblaccounts.userid',
                'tblaccounts.invoiceid',
                'tblaccounts.date',
                'tblaccounts.gateway',
                'tblaccounts.description',
                'tblaccounts.amountin',
                'tblaccounts.amountout',
                'tblaccounts.fees',
                'tblaccounts.rate',
                'tblaccounts.transid',
                'tblclients.firstname',
                'tblclients.lastname',
                'tblclients.companyname'
            );

        if ((int) $userId > 0) {
            $query->where('tblaccounts.userid', (int) $userId);
        }

        return $query;
    }

    private static function applyTransactionFilters($query, $period)
    {
        switch ($period) {
            case 'today':
                $query->whereDate('tblaccounts.date', date('Y-m-d'));
                break;
            case 'yesterday':
                $query->whereDate('tblaccounts.date', date('Y-m-d', strtotime('-1 day')));
                break;
            case 'month':
                $query->whereYear('tblaccounts.date', date('Y'))->whereMonth('tblaccounts.date', date('m'));
                break;
            case 'lastMonth':
                $lastMonth = new \DateTime('first day of last month');
                $query->whereYear('tblaccounts.date', $lastMonth->format('Y'))
                    ->whereMonth('tblaccounts.date', $lastMonth->format('n'));
                break;
            case 'year':
                $query->whereYear('tblaccounts.date', date('Y'));
                break;
            case 'lastYear':
                $query->whereYear('tblaccounts.date', (int) date('Y') - 1);
                break;
            case 'thisYear':
            case 'currentYear':
                $query->whereYear('tblaccounts.date', date('Y'));
                break;
            case 'all':
            default:
        }
    }

    private static function mapTransactionForMobile($transaction)
    {
        $company = trim((string) ($transaction->companyname ?? ''));
        $name = trim((string) ($transaction->firstname ?? '') . ' ' . (string) ($transaction->lastname ?? ''));
        $clientName = $company !== '' ? $company : ($name !== '' ? $name : 'Client #' . $transaction->userid);
        $amount = self::transactionAmountValue($transaction);
        $currency = self::defaultCurrency();

        return [
            'id' => (string) $transaction->id,
            'transactionId' => (string) ($transaction->transid ?: $transaction->id),
            'invoiceId' => (string) ($transaction->invoiceid ?: ''),
            'clientId' => (string) ($transaction->userid ?? ''),
            'clientName' => $clientName,
            'amount' => $amount,
            'fee' => (float) ($transaction->fees ?? 0),
            'currencyCode' => $currency['code'],
            'currencyPrefix' => $currency['prefix'],
            'currencySuffix' => $currency['suffix'],
            'status' => $amount < 0 ? 'Debit' : 'Credit',
            'date' => (string) $transaction->date,
            'method' => (string) ($transaction->gateway ?: 'N/A'),
            'description' => (string) ($transaction->description ?: ''),
        ];
    }

    private static function transactionAmountValue($transaction)
    {
        $rate = (float) ($transaction->rate ?? 1);
        if ($rate <= 0) {
            $rate = 1;
        }

        return ((float) $transaction->amountin - (float) ($transaction->fees ?? 0) - (float) $transaction->amountout) / $rate;
    }

    private static function getIncomeTotalForPeriod($userId, $period)
    {
        $today = WHMCS\Carbon::today();
        $query = Capsule::table('tblaccounts');

        if ((int) $userId > 0) {
            $query->where('userid', (int) $userId);
        }

        switch ($period) {
            case 'today':
                $query->whereBetween('date', [$today->copy()->startOfDay()->toDateTimeString(), $today->copy()->endOfDay()->toDateTimeString()]);
                break;
            case 'yesterday':
                $yesterday = WHMCS\Carbon::today()->subDays(1);
                $query->whereBetween('date', [$yesterday->copy()->startOfDay()->toDateTimeString(), $yesterday->copy()->endOfDay()->toDateTimeString()]);
                break;
            case 'month':
                $query->whereBetween('date', [$today->copy()->startOfMonth()->toDateTimeString(), $today->copy()->endOfMonth()->toDateTimeString()]);
                break;
            case 'lastMonth':
                $lastMonth = new \DateTime('first day of last month');
                $query->whereYear('date', $lastMonth->format('Y'))->whereMonth('date', $lastMonth->format('n'));
                break;
            case 'lastYear':
                $query->whereYear('date', (int) date('Y') - 1);
                break;
            case 'thisYear':
            case 'currentYear':
            case 'year':
            case 'all':
            default:
                if ($period !== 'all') {
                    $query->whereBetween('date', [$today->copy()->startOfYear()->toDateTimeString(), $today->copy()->endOfYear()->toDateTimeString()]);
                }
                break;
        }

        $incomeExpr = '(amountin - fees - amountout) / IF(rate = 0, 1, rate)';
        $total = (clone $query)
            ->selectRaw("SUM({$incomeExpr}) as total")
            ->value('total');

        return (float) ($total ?: 0);
    }

    private static function defaultCurrency()
    {
        try {
            if (Capsule::schema()->hasTable('tblcurrencies')) {
                $currency = Capsule::table('tblcurrencies')->where('default', 1)->first();
                if (!$currency) {
                    $currency = Capsule::table('tblcurrencies')->orderBy('id', 'asc')->first();
                }
                if ($currency) {
                    return [
                        'code' => (string) ($currency->code ?? ''),
                        'prefix' => (string) ($currency->prefix ?? ''),
                        'suffix' => (string) ($currency->suffix ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Fall through to empty code; the mobile app will hide the suffix.
        }

        return ['code' => '', 'prefix' => '', 'suffix' => ''];
    }

    private static function mapInvoiceForMobile($invoice, $items = [])
    {
        $invoiceNumber = !empty($invoice->invoicenum) ? $invoice->invoicenum : $invoice->id;
        $company = trim((string) ($invoice->companyname ?? ''));
        $name = trim((string) ($invoice->firstname ?? '') . ' ' . (string) ($invoice->lastname ?? ''));
        $clientName = $company !== '' ? $company : ($name !== '' ? $name : 'Client #' . $invoice->userid);

        return [
            'invoiceId' => (string) $invoiceNumber,
            'id' => (string) $invoice->id,
            'clientId' => (string) ($invoice->userid ?? ''),
            'clientName' => $clientName,
            'items' => $items,
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) $invoice->tax + (float) $invoice->tax2,
            'credit' => (float) $invoice->credit,
            'total' => (float) $invoice->total,
            'status' => (string) $invoice->status,
            'date' => (string) $invoice->date,
            'dueDate' => (string) $invoice->duedate,
            'paymentMethod' => (string) ($invoice->paymentmethod ?: 'N/A'),
        ];
    }

    public static function adminOutput($vars)
    {
        $modulelink = $vars['modulelink'] ?? '';

        // Tabs definition
        $tabs = [
            'connect'          => 'Connect',
            'connections'      => 'Registered Devices',
            'notification'     => 'Notifications',
            'notificationlogs' => 'Notification Logs',
            'info'             => 'About Us',
        ];

        $action = $_GET['action'] ?? 'connect';

        // Admin actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['create_pair'])) {
            $pairing = bin2hex(random_bytes(8));
            Capsule::table('mod_smartersxconnect_pairs')->insert(['userid' => 0, 'pairing_code' => $pairing, 'state' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
            $_SESSION['smarterx_last_pairing'] = $pairing;
            header('Location: ' . $modulelink);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['regenerate_qr'])) {
            // Expire all pending system-level pairings so the old QR no longer works
            Capsule::table('mod_smartersxconnect_pairs')
                ->where('userid', 0)
                ->where('state', 'pending')
                ->update(['state' => 'expired']);
            // Revoke all active tokens for system-level (userid=0) paired devices
            Capsule::table('mod_smartersxconnect_tokens')
                ->where('userid', 0)
                ->where('revoked', 0)
                ->update(['revoked' => 1]);
            // Delete all system-level paired devices so the connections list is clean
            Capsule::table('mod_smartersxconnect_devices')
                ->where('userid', 0)
                ->delete();
            // Create a fresh pairing
            $pairing = bin2hex(random_bytes(8));
            Capsule::table('mod_smartersxconnect_pairs')->insert(['userid' => 0, 'pairing_code' => $pairing, 'state' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
            header('Location: ' . $modulelink);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['manual_connect'])) {
            $deviceId = trim($_POST['device_id'] ?? '');
            $label = trim($_POST['label'] ?? null);
            if ($deviceId !== '') {
                Capsule::table('mod_smartersxconnect_devices')->insert(['userid' => 0, 'device_id' => $deviceId, 'label' => $label, 'meta' => null, 'created_at' => date('Y-m-d H:i:s')]);
            }
            header('Location: ' . $modulelink . '&action=connections');
            exit;
        }

        if ($action === 'deleteDevice' && !empty($_GET['id'])) {
            $id = (int) $_GET['id'];
            $device = Capsule::table('mod_smartersxconnect_devices')->where('id', $id)->first();
            Capsule::table('mod_smartersxconnect_devices')->where('id', $id)->delete();
            Capsule::table('mod_smartersxconnect_tokens')->where('label', 'paired-device:' . $id)->update(['revoked' => 1]);
            if (Capsule::schema()->hasTable('mod_smartersxconnect_notification_devices')) {
                $notificationDevices = Capsule::table('mod_smartersxconnect_notification_devices')
                    ->where('device_table_id', $id);
                if ($device && !empty($device->device_id)) {
                    $notificationDevices->orWhere('mobile_device_id', $device->device_id);
                }
                $notificationDevices->delete();
            }
            if ($device && !empty($device->device_id)) {
                Capsule::table('mod_smartersxconnect_tokens')->where('label', 'paired-device')->update(['revoked' => 1]);
            }
            header('Location: ' . $modulelink . '&action=connections&deleted=1');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_delete_devices'])) {
            $ids = array_filter(array_map('intval', (array) ($_POST['device_ids'] ?? [])));
            $count = 0;
            foreach ($ids as $id) {
                $device = Capsule::table('mod_smartersxconnect_devices')->where('id', $id)->first();
                if (!$device) continue;
                Capsule::table('mod_smartersxconnect_devices')->where('id', $id)->delete();
                Capsule::table('mod_smartersxconnect_tokens')->where('label', 'paired-device:' . $id)->update(['revoked' => 1]);
                if (Capsule::schema()->hasTable('mod_smartersxconnect_notification_devices')) {
                    $q = Capsule::table('mod_smartersxconnect_notification_devices')->where('device_table_id', $id);
                    if (!empty($device->device_id)) $q->orWhere('mobile_device_id', $device->device_id);
                    $q->delete();
                }
                $count++;
            }
            header('Location: ' . $modulelink . '&action=connections&deleted=' . $count);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_payment_notifications'])) {
            self::handleSavePaymentNotifications($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_fcm_credentials'])) {
            self::handleSaveFcmCredentials($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_fcm_credentials'])) {
            self::handleDeleteFcmCredentials($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send_test_notification'])) {
            self::handleSendTestNotification($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_notifications'])) {
            self::handleSaveGlobalNotifications($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_firebase_android'])) {
            self::handleSaveFirebaseAndroid($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_firebase_ios'])) {
            self::handleSaveFirebaseIos($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_firebase_android'])) {
            self::handleDeleteFirebaseConfig($modulelink, 'android');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_firebase_ios'])) {
            self::handleDeleteFirebaseConfig($modulelink, 'ios');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['sync_firebase_from_manager'])) {
            self::loadFirebaseLibs();
            self::maybeAutoSyncFirebaseConfigs(true);
            header('Location: ' . $modulelink . '&action=notification&fb_synced=1');
            exit;
        }

        // Firebase Manager: file download — must run before any HTML output
        if ($action === 'firebase_manager' && !empty($_GET['firebase_download'])) {
            self::loadFirebaseLibs();
            self::handleFirebaseDownload($modulelink);
        }

        // Firebase Manager: OAuth callback from Google
        if ($action === 'firebase_manager' && !empty($_GET['code'])) {
            $systemUrl = \App::getSystemURL();
            $adminFolder = \App::get_admin_folder_name();
            
            $modulelink = rtrim($systemUrl, '/') . '/' . $adminFolder . '/addonmodules.php?module=smartersxconnect';
            $modulelink = htmlspecialchars($modulelink);
            self::loadFirebaseLibs();
            self::handleFirebaseOAuthCallback($modulelink);
        }

        // Firebase Manager: POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_firebase_oauth_creds'])) {
            self::loadFirebaseLibs();
            self::handleSaveFirebaseOAuthCreds($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['firebase_oauth_disconnect'])) {
            self::loadFirebaseLibs();
            self::handleFirebaseOAuthDisconnect($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['firebase_select_project'])) {
            self::loadFirebaseLibs();
            self::handleFirebaseSelectProject($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['firebase_create_android'])) {
            self::loadFirebaseLibs();
            self::handleFirebaseCreateAndroid($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['firebase_create_ios'])) {
            self::loadFirebaseLibs();
            self::handleFirebaseCreateIos($modulelink);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['firebase_reset_android'])) {
            self::loadFirebaseLibs();
            \FirebaseAuth::saveAndroidAppId('');
            header('Location: ' . $modulelink . '&action=firebase_manager');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['firebase_reset_ios'])) {
            self::loadFirebaseLibs();
            \FirebaseAuth::saveIosAppId('');
            header('Location: ' . $modulelink . '&action=firebase_manager');
            exit;
        }


        // Global banner — shown on every tab until the Google account is connected.
        self::loadFirebaseLibs();
        if (!\FirebaseAuth::isConnected()) {
            $notifUrl = htmlspecialchars($modulelink . '&action=notification');
            echo '<div class="alert alert-warning" style="margin-bottom:12px">'
                . '<strong>To get started with Payment instant Notifications</strong> &mdash; '
                . 'Upload your Google service account file to enable payment notifications on the SmartersX app. '
                . '<a href="' . $notifUrl . '">Configure now &rarr;</a>'
                . '</div>';
        }

        echo '<ul class="nav nav-tabs">';
        foreach ($tabs as $key => $label) {
            $active = ($action === $key) ? 'active' : '';
            $href = htmlspecialchars($modulelink . ($key === 'connect' ? '' : '&action=' . $key));
            echo "<li class=\"$active\"><a href=\"$href\">$label</a></li>";
        }
        echo '</ul>';

        // Render tab content
        echo '<div class="tab-content" style="padding-top:12px">';
        switch ($action) {
            case 'connections':
                self::renderConnections($vars);
                break;
            case 'notification':
            case 'firebase_manager':
                self::renderNotificationSettings($vars);
                break;
            case 'info':
                self::renderInfo($vars);
                break;
            case 'notificationlogs':
                self::renderNotificationLogs($vars);
                break;
            case 'connect':
            default:
                self::renderConnect($vars);
                break;
        }
        echo '</div>';
    }

    private static function generateToken($len = 32)
    {
        try {
            $b = random_bytes($len);
            return bin2hex($b);
        } catch (\Exception $e) {
            return bin2hex(openssl_random_pseudo_bytes($len));
        }
    }

    private static function createApiToken($userId, $label = null, $deviceTableId = null)
    {
        $raw = self::generateToken(24);
        $hash = hash('sha256', $raw);
        $tokenLabel = $deviceTableId ? ($label . ':' . $deviceTableId) : $label;
        $id = Capsule::table('mod_smartersxconnect_tokens')->insertGetId([
            'userid' => $userId,
            'token_hash' => $hash,
            'label' => $tokenLabel,
            'revoked' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['id' => $id, 'token' => $raw];
    }

    private static function deviceTableIdFromTokenRecord($tokenRecord)
    {
        if (!$tokenRecord || strpos((string) $tokenRecord->label, 'paired-device:') !== 0) {
            return 0;
        }

        return (int) substr((string) $tokenRecord->label, strlen('paired-device:'));
    }

    private static function validateApiToken($rawToken)
    {
        $hash = hash('sha256', trim($rawToken));
        $rec = Capsule::table('mod_smartersxconnect_tokens')
            ->where('token_hash', $hash)
            ->where('revoked', 0)
            ->first();
        if ($rec && strpos((string) $rec->label, 'paired-device:') === 0) {
            $deviceId = (int) substr((string) $rec->label, strlen('paired-device:'));
            $exists = Capsule::table('mod_smartersxconnect_devices')->where('id', $deviceId)->first();
            if (!$exists) {
                Capsule::table('mod_smartersxconnect_tokens')->where('id', $rec->id)->update(['revoked' => 1]);
                return null;
            }
        }
        return $rec;
    }

    /**
     * Generate a PNG data URI for the given text (QR code).
     * Uses chillerlan/php-qrcode if installed via Composer.
     * Returns data URI string on success or null on failure.
     */
    private static function generateQrDataUri(string $text)
    {
        // Prefer phpqrcode library bundled under module/lib/phpqrcode/qrlib.php
        $apidirectory = dirname(__DIR__) . '/';
        $qrlib = $apidirectory . 'phpqrcode/qrlib.php';
        if (file_exists($qrlib)) {
            require_once $qrlib;
            try {
                ob_start();
                \QRcode::png($text, null, QR_ECLEVEL_L, 4);
                $imageData = ob_get_clean();
                return 'data:image/png;base64,' . base64_encode($imageData);
            } catch (\Exception $e) {
                error_log('SmartersxConnect QR generation error: ' . $e->getMessage());
                return null;
            }
        }

        // Fallback: try chillerlan/php-qrcode via Composer
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
            if (class_exists('\\chillerlan\\QRCode\\QRCode') && class_exists('\\chillerlan\\QRCode\\QROptions')) {
                try {
                    $options = new \chillerlan\QRCode\QROptions(['version' => null, 'outputType' => \chillerlan\QRCode\QROptions::OUTPUT_IMAGE_PNG, 'eccLevel' => \chillerlan\QRCode\QROptions::ECC_L, 'scale' => 5]);
                    $qrcode = new \chillerlan\QRCode\QRCode($options);
                    $imageData = $qrcode->render($text);
                    return 'data:image/png;base64,' . base64_encode($imageData);
                } catch (\Exception $e) {
                    return null;
                }
            }
        }

        return null;
    }

    private static function renderConnect($vars)
    {
        $modulelink = $vars['modulelink'] ?? '';
        $iosDownloadUrl = 'https://apps.apple.com/in/app/smartersx/id1643695817';
        $androidDownloadUrl = 'https://play.google.com/store/apps/details?id=com.techsmarters.smarterx&hl=en_IN';

        // Ensure a recent system-level pairing exists (userid = 0). Reuse if recent, otherwise create.
        $recentThresholdSeconds = 3600; // 1 hour
        $pair = Capsule::table('mod_smartersxconnect_pairs')
            ->where('userid', 0)
            ->where('state', 'pending')
            ->orderBy('created_at', 'desc')
            ->first();
        $createNew = true;
        if ($pair) {
            $createdAt = strtotime($pair->created_at);
            if ($createdAt !== false && (time() - $createdAt) <= $recentThresholdSeconds) {
                $createNew = false;
                $pairing = $pair->pairing_code;
            }
        }
        if ($createNew) {
            $pairing = bin2hex(random_bytes(8));
            Capsule::table('mod_smartersxconnect_pairs')->insert([
                'userid' => 0,
                'pairing_code' => $pairing,
                'state' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        //get formo whmcs configuration table 
        $whmcsBaseUrl = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
        if (!$whmcsBaseUrl) {
            $whmcsBaseUrl = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]", '/');
        } else {
            $whmcsBaseUrl = rtrim($whmcsBaseUrl, '/');
        }
        $pairingClientUrl = $whmcsBaseUrl . '/index.php?m=smartersxconnect&action=pairRequest&pairing_code=' . $pairing;

        echo '<div class="tab-pane active" id="tab-connect">';
        echo '<h4>Connect Your WHMCS Store to the SmartersX App</h4>';
    echo '<p>Open the <a href="#" id="sx-download-app-link"> <strong>SmartersX App</strong></a>.<br>
Scan the QR Code below to complete the connection.<br><br>';
// <a href="#" id="sx-download-app-link">Download the app</a></p>';

        $qr = self::generateQrDataUri($pairingClientUrl);
        if ($qr) {
            echo '<div class="mt-3"><img src="' . $qr . '" alt="Pairing QR" style="max-width:300px"/></div>';
        } else {
            echo '<p><a href="' . htmlspecialchars($pairingClientUrl) . '" target="_blank">Open pairing URL</a></p>';
        }
        echo '
<style>
#sx-regen-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
#sx-regen-overlay.active{display:flex}
#sx-regen-box{background:#fff;border-radius:8px;padding:28px 28px 22px;max-width:400px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.18)}
#sx-regen-box h4{margin:0 0 10px;font-size:16px;font-weight:700}
#sx-regen-box p{margin:0 0 20px;color:#555;font-size:14px;line-height:1.5}
#sx-regen-box .sx-btns{display:flex;gap:10px;justify-content:flex-end}
#sx-download-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center}
#sx-download-overlay.active{display:flex}
#sx-download-box{background:#fff;border-radius:12px;padding:32px 28px 24px;max-width:420px;width:90%;box-shadow:0 12px 40px rgba(0,0,0,.22);text-align:center}
#sx-download-box h4{margin:0 0 8px;font-size:18px;font-weight:700;color:#1a1a2e}
#sx-download-box p{margin:0 0 24px;color:#666;font-size:14px;line-height:1.5}
#sx-download-box .sx-store-links{display:flex;flex-direction:column;gap:12px}
#sx-download-box .sx-store-btn{display:flex;align-items:center;justify-content:center;gap:10px;padding:12px 20px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600;transition:opacity .2s}
#sx-download-box .sx-store-btn:hover{opacity:.88;text-decoration:none}
#sx-download-box .sx-ios-btn{background:#000;color:#fff}
#sx-download-box .sx-android-btn{background:#01875f;color:#fff}
#sx-download-box .sx-store-btn svg{flex-shrink:0}
#sx-download-box .sx-store-btn .sx-btn-text{display:flex;flex-direction:column;align-items:flex-start;line-height:1.2}
#sx-download-box .sx-store-btn .sx-btn-sub{font-size:11px;font-weight:400;opacity:.85}
#sx-download-box .sx-btn-close{margin-top:16px;background:none;border:1px solid #ddd;border-radius:8px;padding:8px 20px;font-size:13px;color:#555;cursor:pointer;width:100%}
#sx-download-box .sx-btn-close:hover{background:#f5f5f5}
</style>
<div id="sx-download-overlay">
    <div id="sx-download-box">
        <h4>Download SmartersX</h4>
        <p>Choose your platform to get started.</p>
        <div class="sx-store-links">
            <a class="sx-store-btn sx-ios-btn" href="' . htmlspecialchars($iosDownloadUrl) . '" target="_blank" rel="noopener noreferrer">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="white"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>
                <div class="sx-btn-text">
                    <span class="sx-btn-sub">Download on the</span>
                    <span>App Store</span>
                </div>
            </a>
            <a class="sx-store-btn sx-android-btn" href="' . htmlspecialchars($androidDownloadUrl) . '" target="_blank" rel="noopener noreferrer">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="white"><path d="M17.523 15.341a.898.898 0 0 1-.898.898H7.375a.898.898 0 0 1-.898-.898V8.716h11.046v6.625zM7.376 5.009l-1.17-2.026a.198.198 0 0 0-.27-.073.198.198 0 0 0-.073.27l1.186 2.054A7.222 7.222 0 0 0 4.8 8.317h14.4a7.222 7.222 0 0 0-2.25-3.083l1.186-2.054a.198.198 0 0 0-.073-.27.198.198 0 0 0-.27.073l-1.17 2.026A6.98 6.98 0 0 0 12 4.2a6.98 6.98 0 0 0-4.624 1.809zM9.6 6.9a.6.6 0 1 1-1.2 0 .6.6 0 0 1 1.2 0zm6 0a.6.6 0 1 1-1.2 0 .6.6 0 0 1 1.2 0zM6.477 16.8a1.8 1.8 0 0 0 1.8 1.8v1.8a.9.9 0 0 0 1.8 0V18.6h3.846v1.8a.9.9 0 0 0 1.8 0V18.6a1.8 1.8 0 0 0 1.8-1.8v-.459H6.477V16.8z"/></svg>
                <div class="sx-btn-text">
                    <span class="sx-btn-sub">Get it on</span>
                    <span>Google Play</span>
                </div>
            </a>
        </div>
        <button type="button" class="sx-btn-close" onclick="document.getElementById(\'sx-download-overlay\').classList.remove(\'active\')">Close</button>
    </div>
</div>
<div id="sx-regen-overlay">
  <div id="sx-regen-box">
    <h4>Regenerate QR Code?</h4>
    <p>The current QR code and pairing code will stop working immediately.</p>
    <div class="sx-btns">
      <button type="button" class="btn btn-default" onclick="document.getElementById(\'sx-regen-overlay\').classList.remove(\'active\')">Cancel</button>
      <button type="button" class="btn btn-danger" onclick="document.getElementById(\'sx-regen-form\').submit()">Yes, Regenerate</button>
    </div>
  </div>
</div>
<form id="sx-regen-form" method="post" action="' . htmlspecialchars($modulelink) . '" style="margin-top:12px">
  <input type="hidden" name="regenerate_qr" value="1">
  <button type="button" class="btn btn-default" onclick="document.getElementById(\'sx-regen-overlay\').classList.add(\'active\')">
    <span class="glyphicon glyphicon-refresh"></span> Regenerate QR Code
  </button>
  <p class="help-block" style="margin-top:6px">Once you generate a new code, the current QR code and pairing code will stop working immediately.</p>
</form>';
        echo '<div class="form-group" style="max-width:640px;margin-top:16px">';
        echo '<label>WHMCS URL</label>';
        echo '<input type="text" class="form-control" readonly onclick="this.select()" value="' . htmlspecialchars($whmcsBaseUrl) . '">';
        echo '<p class="help-block">Enter this full WHMCS URL in the mobile app when the server URL is not preconfigured.</p>';
        echo '</div>';
        echo '<div class="form-group" style="max-width:640px">';
        echo '<label>Pairing Code</label>';
        echo '<input type="text" class="form-control" readonly onclick="this.select()" value="' . htmlspecialchars($pairing) . '">';
        echo '</div>';
                echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    var trigger = document.getElementById("sx-download-app-link");
    var overlay = document.getElementById("sx-download-overlay");
    if (trigger && overlay) {
        trigger.addEventListener("click", function (event) {
            event.preventDefault();
            overlay.classList.add("active");
        });
        overlay.addEventListener("click", function (event) {
            if (event.target === overlay) {
                overlay.classList.remove("active");
            }
        });
    }
});
</script>';
        echo '</div>';
    }

    private static function renderConnections($vars)
    {
        $modulelink = $vars['modulelink'] ?? '';
        $devices = Capsule::table('mod_smartersxconnect_devices')->orderBy('created_at', 'desc')->get();
        $deviceCount = $devices ? count($devices) : 0;

        echo '<div class="tab-pane active" id="tab-connections">';
        echo '<h4>Registered Devices</h4>';

        if (!empty($_GET['deleted'])) {
            $n = (int) $_GET['deleted'];
            echo '<div class="alert alert-success"><strong>Done!</strong> '
                . $n . ' device' . ($n === 1 ? '' : 's') . ' removed.</div>';
        }

        self::renderTestNotificationStatus();
        self::renderTestNotificationForm($modulelink, 'connections');

        if ($deviceCount === 0) {
            echo '<div class="alert alert-info">No registered devices.</div>';
        } else {
            $action = htmlspecialchars($modulelink . '&action=connections');
            echo '<form method="post" action="' . $action . '" id="sx-bulk-form">';
            echo '<input type="hidden" name="bulk_delete_devices" value="1">';

            // Toolbar
            echo '<div class="clearfix" style="margin-bottom:10px">';
            echo '<div class="btn-group pull-left">';
            echo '<button type="button" class="btn btn-default btn-sm" onclick="sxSelectAll(true)">'
                . '<span class="glyphicon glyphicon-check"></span> Select All</button>';
            echo '<button type="button" class="btn btn-default btn-sm" onclick="sxSelectAll(false)">'
                . '<span class="glyphicon glyphicon-unchecked"></span> Deselect All</button>';
            echo '</div>';
            echo '<button type="submit" class="btn btn-danger btn-sm pull-right" id="sx-bulk-btn" disabled '
                . 'onclick="return confirm(\'Delete selected devices? This cannot be undone.\')">'
                . '<span class="glyphicon glyphicon-trash"></span> Delete Selected (<span id="sx-sel-count">0</span>)</button>';
            echo '</div>';

            // Table
            echo '<table class="table table-bordered table-hover" style="margin-bottom:6px">';
            echo '<thead><tr>';
            echo '<th style="width:36px;text-align:center">'
                . '<input type="checkbox" id="sx-check-all" onchange="sxSelectAll(this.checked)" title="Select all"></th>';
            echo '<th>#</th><th>Label</th><th>Device ID</th><th>Connected</th><th>Action</th>';
            echo '</tr></thead><tbody>';

            foreach ($devices as $d) {
                $id  = intval($d->id);
                $del = htmlspecialchars($modulelink . '&action=deleteDevice&id=' . $id);
                $label = htmlspecialchars($d->label ?: '—');
                $deviceId = htmlspecialchars($d->device_id);
                $created = htmlspecialchars($d->created_at);
                echo '<tr>';
                echo '<td style="text-align:center;vertical-align:middle">'
                    . '<input type="checkbox" name="device_ids[]" value="' . $id . '" class="sx-device-cb" onchange="sxUpdateBulk()"></td>';
                echo '<td>' . $id . '</td>';
                echo '<td><strong>' . $label . '</strong></td>';
                echo '<td><code style="font-size:11px">' . $deviceId . '</code></td>';
                echo '<td><small class="text-muted">' . $created . '</small></td>';
                echo '<td><a href="' . $del . '" class="btn btn-xs btn-danger" '
                    . 'onclick="return confirm(\'Remove this device?\')">'
                    . '<span class="glyphicon glyphicon-trash"></span> Remove</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<p class="text-muted" style="font-size:12px">' . $deviceCount . ' device' . ($deviceCount === 1 ? '' : 's') . ' registered.</p>';
            echo '</form>';

            echo '<script>
function sxSelectAll(checked) {
    document.querySelectorAll(".sx-device-cb").forEach(function(cb){ cb.checked = checked; });
    document.getElementById("sx-check-all").checked = checked;
    sxUpdateBulk();
}
function sxUpdateBulk() {
    var checked = document.querySelectorAll(".sx-device-cb:checked").length;
    document.getElementById("sx-sel-count").textContent = checked;
    document.getElementById("sx-bulk-btn").disabled = checked === 0;
    document.getElementById("sx-check-all").checked =
        checked === document.querySelectorAll(".sx-device-cb").length;
}
</script>';
        }

        echo '</div>';
    }

    private static function renderNotificationSettings($vars)
    {
        $modulelink = $vars['modulelink'] ?? '';
        $credentialSummary = self::getServiceAccountSummary();
        self::ensurePaymentNotificationTable();
        $notifications = Capsule::table('mod_smartersxconnect_payment_notifications')
            ->orderBy('id', 'asc')
            ->get();

        // Auto-sync Firebase configs if Firebase Manager is connected
        self::ensureFirebaseConfigTable();
        self::maybeAutoSyncFirebaseConfigs();

        // Firebase Manager state — used for sidebar step tracking and button gating
        self::loadFirebaseLibs();
        $oauthRow        = \FirebaseAuth::getRow();
        $fbConnected     = \FirebaseAuth::isConnected();
        $fbProjectSet    = $oauthRow && !empty($oauthRow->selected_project_id);
        $fbAppsSet       = $oauthRow && !empty($oauthRow->android_app_id) && !empty($oauthRow->ios_app_id);

        // Send Test button enabled if service account is stored OR Firebase Manager has a project selected.
        $isConfigured = !empty($credentialSummary['configured']) || $fbProjectSet;

        echo '<div class="tab-pane active" id="tab-settings">';

        // ── Alerts ───────────────────────────────────────────────────────
        if (!empty($_GET['saved'])) {
            echo '<div class="alert alert-success"><strong>Saved!</strong> Payment notification settings updated successfully.</div>';
        }
        if (!empty($_GET['fcm_saved'])) {
            echo '<div class="alert alert-success"><strong>Uploaded!</strong> Payment notification service configured successfully.</div>';
        }
        if (!empty($_GET['fcm_error'])) {
            echo '<div class="alert alert-danger"><strong>Error:</strong> ' . htmlspecialchars($_GET['fcm_error']) . '</div>';
        }
        if (!empty($_GET['fcm_deleted'])) {
            echo '<div class="alert alert-info"><strong>Deleted.</strong> Payment notification credentials have been removed.</div>';
        }
        if (!empty($_GET['fb_synced'])) {
            echo '<div class="alert alert-success"><strong>Synced!</strong> Firebase config files fetched from Firebase Manager.</div>';
        }
        if (!empty($_GET['fb_saved'])) {
            $fbPlatform = $_GET['fb_saved'] === 'android' ? 'Android (google-services.json)' : 'iOS (GoogleService-Info.plist)';
            echo '<div class="alert alert-success"><strong>Uploaded!</strong> ' . htmlspecialchars($fbPlatform) . ' config saved.</div>';
        }
        if (!empty($_GET['fb_deleted'])) {
            $fbPlatform = $_GET['fb_deleted'] === 'android' ? 'Android' : 'iOS';
            echo '<div class="alert alert-info"><strong>Deleted.</strong> ' . htmlspecialchars($fbPlatform) . ' Firebase config removed.</div>';
        }
        if (!empty($_GET['fb_error'])) {
            echo '<div class="alert alert-danger"><strong>Error:</strong> ' . htmlspecialchars($_GET['fb_error']) . '</div>';
        }
        self::renderTestNotificationStatus();

        $globalEnabled = Capsule::table('tblconfiguration')
            ->where('setting', 'smartersx_notifications_enabled')
            ->value('value');
        $globalEnabled = ($globalEnabled === null) ? '1' : $globalEnabled;

        // Ensure all rules are enabled — the global toggle controls on/off, not individual rows.
        Capsule::table('mod_smartersxconnect_payment_notifications')
            ->where('enabled', '!=', 1)
            ->update(['enabled' => 1]);

        // Step 4 done: global toggle is on AND at least one notification rule exists.
        $step4Done = ((string) $globalEnabled === '1')
            && Capsule::table('mod_smartersxconnect_payment_notifications')->count() > 0;

        // Step 5 done: a test notification was successfully sent at least once.
        $step5Done = Capsule::table('tblconfiguration')
            ->where('setting', 'smartersx_test_notification_sent')
            ->where('value', '1')
            ->exists();

        echo '<div class="row" style="margin-top:8px">';

        // ── col-md-8: Settings ────────────────────────────────────────────
        echo '<div class="col-md-8">';

        // ── Firebase & Notification Setup (inline Firebase Manager) ─────────
        echo '<style>
.sx-ns-step-hdr{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;user-select:none;transition:background .15s}
.sx-ns-step-hdr:hover{background:#f0f4f8!important}
.sx-ns-badge{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;font-size:13px;font-weight:700;flex-shrink:0;transition:background .2s}
.sx-ns-chevron{transition:transform .2s;font-size:11px}
.sx-ns-chevron.open{transform:rotate(180deg)}
.sx-ns-body{padding:18px 20px;border-top:1px solid #e8ecf0;background:#fff}
.sx-upload-card{border:2px dashed #c8d0da;border-radius:6px;padding:20px 16px;text-align:center;transition:border-color .2s}
.sx-upload-card:hover{border-color:#337ab7}
.sx-upload-card input[type=file]{display:none}
.sx-upload-card label{cursor:pointer;margin:0}
.sx-info-row{display:flex;gap:6px;padding:5px 0;border-bottom:1px solid #f4f4f4;font-size:13px}
.sx-info-row:last-child{border-bottom:none}
.sx-info-key{color:#888;min-width:120px;flex-shrink:0}
.sx-info-val{font-weight:600;word-break:break-all}
</style>';

        // ── Firebase Manager (inline — primary setup) ────────────────────────
        self::loadFirebaseLibs();
        self::renderFirebaseManager($vars);

        echo '<script>
function sxNsShow(id){document.getElementById(id).style.display="block";}
function sxNsHide(id){document.getElementById(id).style.display="none";}
</script>';

        // ── Notification Templates ────────────────────────────────────────────
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading clearfix">';
        echo '<strong class="pull-left" style="line-height:26px">Notification Templates</strong>';
        echo '<div class="pull-right" style="display:flex;align-items:center;gap:8px">';
        if ($globalEnabled === '1') {
            echo '<span class="label label-success" style="font-size:12px;padding:4px 8px">&#10003; Notifications ON</span>';
        } else {
            echo '<span class="label label-danger" style="font-size:12px;padding:4px 8px">&#10007; Notifications OFF</span>';
        }
        echo '<form method="post" action="' . htmlspecialchars($modulelink . '&action=notification') . '" style="margin:0">';
        echo '<input type="hidden" name="save_global_notifications" value="1">';
        if ($globalEnabled === '1') {
            // Submits without notifications_enabled → handler sets '0' (disable)
            echo '<button type="submit" class="btn btn-xs btn-default">Turn Off</button>';
        } else {
            // Submits with notifications_enabled=1 → handler sets '1' (enable)
            echo '<button type="submit" name="notifications_enabled" value="1" class="btn btn-xs btn-success">Turn On</button>';
        }
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '<div class="panel-body">';
        echo '<p class="text-muted">Customise the message sent to the app for each payment event.</p>';

        echo '<form method="post" action="' . htmlspecialchars($modulelink . '&action=notification') . '">';
        echo '<input type="hidden" name="save_payment_notifications" value="1">';
        foreach ($notifications as $notification) {
            $id = (int) $notification->id;
            echo '<div class="well well-sm" style="margin-bottom:12px">';
            echo '<input type="hidden" name="notification_id[]" value="' . $id . '">';
            echo '<div class="row">';
            echo '<div class="col-xs-12" style="margin-bottom:6px">';
            echo '<strong>' . htmlspecialchars($notification->label) . '</strong> ';
            // echo '<code style="font-size:11px">' . htmlspecialchars($notification->event_key) . '</code>';
            echo '</div>';
            echo '</div>';
            echo '<div class="row">';
            echo '<div class="col-sm-4">';
            echo '<label class="control-label small">Title</label>';
            echo '<input type="text" class="form-control input-sm" name="title_template[' . $id . ']" value="' . htmlspecialchars($notification->title_template) . '" placeholder="Notification title">';
            echo '</div>';
            echo '<div class="col-sm-5">';
            echo '<label class="control-label small">Message</label>';
            echo '<textarea class="form-control input-sm" rows="2" name="body_template[' . $id . ']" placeholder="Notification message">' . htmlspecialchars($notification->body_template) . '</textarea>';
            echo '</div>';
            echo '<div class="col-sm-3">';
            echo '<label class="control-label small">&nbsp;</label><br>';
            echo '<button type="button" class="btn btn-default btn-sm" '
                . (!$isConfigured ? 'disabled title="Complete Firebase Manager setup first"' : '')
                . ' onclick="sxTestEvent(\'' . htmlspecialchars($notification->event_key, ENT_QUOTES) . '\', \'' . htmlspecialchars($modulelink, ENT_QUOTES) . '\')">'
                . '<span class="glyphicon glyphicon-envelope"></span> Send Test</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '<p class="help-block"><strong>Variables:</strong> ';
        foreach (['{amount}', '{transaction_id}', '{invoice_id}', '{client_name}', '{payment_method}', '{date}'] as $v) {
            echo '<code>' . $v . '</code> ';
        }
        echo '</p>';
        echo '<button type="submit" class="btn btn-primary">Save Notification Rules</button>';
        echo '</form>';
        echo '</div></div>'; // panel-body + panel

        echo '<script>
function sxTestEvent(eventKey, modulelink) {
    if (!confirm("Send a test notification for: " + eventKey + "?")) return;
    var f = document.createElement("form");
    f.method = "post"; f.action = modulelink + "&action=notification";
    var fields = {send_test_notification:"1",return_action:"notification",test_event_key:eventKey};
    Object.keys(fields).forEach(function(k){
        var i=document.createElement("input");i.type="hidden";i.name=k;i.value=fields[k];f.appendChild(i);
    });
    document.body.appendChild(f); f.submit();
}
</script>';

        echo '</div>'; // col-md-8

        // ── col-md-4: Setup Guide ─────────────────────────────────────────
        echo '<div class="col-md-4">';
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><strong>Setup Guide</strong></div>';
        echo '<div class="panel-body">';

        // Determine current step (first incomplete)
        $guideStepsDone = [$fbConnected, $fbProjectSet, $fbAppsSet, $step4Done, $step5Done];
        $currentStep = count($guideStepsDone); // default: all done
        foreach ($guideStepsDone as $si => $sd) {
            if (!$sd) { $currentStep = $si; break; }
        }

        $guideSteps = [
            [
                $fbConnected,
                'Connect Firebase Manager',
                'Link your Google account',
                [
                    'Enter your Google OAuth Client ID &amp; Secret in Step 1 of the Firebase Manager panel.',
                    'Click <strong>Connect Google Account</strong> and sign in.',
                    'Grant the requested Firebase &amp; IAM permissions.',
                ],
            ],
            [
                $fbProjectSet,
                'Select Firebase Project',
                'Choose the project to use',
                [
                    'After connecting, a list of your Firebase projects appears.',
                    'Select the project that matches your app and click <strong>Save Project</strong>.',
                    'FCM service account credentials are fetched automatically.',
                ],
            ],
            [
                $fbAppsSet,
                'Register Android &amp; iOS Apps',
                'Create Firebase app entries',
                [
                    'In Step 3, confirm the bundle ID (<code>com.techsmarters.smarterx</code>) and display name.',
                    'Click <strong>Create Android App</strong> then <strong>Create iOS App</strong>.',
                    'Config files are synced to the module automatically — no manual download needed.',
                ],
            ],
            [
                $step4Done,
                'Configure Notification Templates',
                'Customise message content',
                [
                    'Scroll down to <strong>Notification Templates</strong>.',
                    'Edit the <strong>Title</strong> and <strong>Message</strong> for each payment event.',
                    'Use <code>{amount}</code>, <code>{client_name}</code>, etc. as placeholders.',
                    'Click <strong>Save Notification Rules</strong>.',
                ],
            ],
            [
                $step5Done,
                'Send a Test Notification',
                'Verify end-to-end delivery',
                [
                    'Make sure at least one device is paired and has the app installed.',
                    'Click <strong>Send Test</strong> next to any notification rule.',
                    'Check Notification Logs if the notification does not arrive.',
                ],
            ],
        ];

        echo '<ol class="list-unstyled">';
        foreach ($guideSteps as $i => $step) {
            list($done, $title, $subtitle, $subSteps) = $step;
            $isCurrent = ($i === $currentStep);
            if ($done) {
                $badgeStyle = 'background:#5cb85c;color:#fff';
                $bgHdr      = '#f6fff7';
                $badgeLabel = '&#10003;';
            } elseif ($isCurrent) {
                $badgeStyle = 'background:#337ab7;color:#fff';
                $bgHdr      = '#f0f7ff';
                $badgeLabel = (string)($i + 1);
            } else {
                $badgeStyle = 'background:#c8d0da;color:#555';
                $bgHdr      = '#fafafa';
                $badgeLabel = (string)($i + 1);
            }
            $collapseId  = 'sx-guide-' . ($i + 1);
            $defaultOpen = $isCurrent ? 'block' : 'none';

            echo '<li style="margin-bottom:10px;border:1px solid #e3e6eb;border-radius:6px;overflow:hidden">';
            echo '<div style="display:flex;gap:10px;align-items:center;padding:10px 12px;cursor:pointer;background:' . $bgHdr . '" '
                . 'onclick="var b=document.getElementById(\'' . $collapseId . '\');b.style.display=b.style.display===\'none\'?\'block\':\'none\'">';
            echo '<div style="flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;font-size:12px;font-weight:700;' . $badgeStyle . '">' . $badgeLabel . '</div>';
            echo '<div style="flex:1">';
            echo '<strong style="font-size:13px">' . $title . '</strong>';
            echo '<br><small class="text-muted">' . $subtitle . '</small>';
            echo '</div>';
            if ($isCurrent) echo '<span class="label label-info" style="font-size:10px">Current</span>';
            echo '</div>';
            echo '<div id="' . $collapseId . '" style="display:' . $defaultOpen . ';padding:10px 12px;border-top:1px solid #e3e6eb;background:#fff">';
            echo '<ol style="margin:0;padding-left:18px">';
            foreach ($subSteps as $sub) {
                echo '<li style="font-size:12px;color:#555;margin-bottom:5px;line-height:1.5">' . $sub . '</li>';
            }
            echo '</ol>';
            echo '</div>';
            echo '</li>';
        }
        echo '</ol>';
        echo '</div></div>'; // panel-body + panel
        echo '</div>'; // col-md-4

        echo '</div>'; // row
        echo '</div>'; // tab-pane
    }

    private static function renderTestNotificationForm($modulelink, $returnAction = 'notification')
    {
        // Rendered inline inside renderNotificationSettings — kept for other callers.
        echo '<div class="panel panel-default" style="max-width:680px">';
        echo '<div class="panel-body">';
        echo '<h4>Test Notifications</h4>';
        echo '<p class="help-block">Send a test payment notification to all active registered devices.</p>';
        echo '<form method="post" action="' . htmlspecialchars($modulelink . '&action=' . $returnAction) . '" onsubmit="return confirm(\'Send a test notification to all registered devices?\')">';
        echo '<input type="hidden" name="send_test_notification" value="1">';
        echo '<input type="hidden" name="return_action" value="' . htmlspecialchars($returnAction) . '">';
        echo '<button type="submit" class="btn btn-warning">Send Test Notification</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    private static function renderTestNotificationStatus()
    {
        if (!empty($_GET['testsent'])) {
            $count = (int) ($_GET['testcount'] ?? 0);
            echo '<div class="alert alert-success"><strong>Sent!</strong> Test notification delivered to ' . $count . ' connected device' . ($count === 1 ? '' : 's') . '.</div>';
        }
        if (!empty($_GET['testerror'])) {
            echo '<div class="alert alert-danger"><strong>Error:</strong> ' . htmlspecialchars($_GET['testerror']) . '</div>';
        }
    }

    private static function handleSaveGlobalNotifications($modulelink)
    {
        $enabled = isset($_POST['notifications_enabled']) ? '1' : '0';
        $exists = Capsule::table('tblconfiguration')
            ->where('setting', 'smartersx_notifications_enabled')
            ->first();
        if ($exists) {
            Capsule::table('tblconfiguration')
                ->where('setting', 'smartersx_notifications_enabled')
                ->update(['value' => $enabled]);
        } else {
            Capsule::table('tblconfiguration')->insert([
                'setting' => 'smartersx_notifications_enabled',
                'value'   => $enabled,
            ]);
        }
        header('Location: ' . $modulelink . '&action=notification&saved=1');
        exit;
    }

    private static function handleSavePaymentNotifications($modulelink)
    {
        self::ensurePaymentNotificationTable();
        $ids = $_POST['notification_id'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }

        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $title = trim($_POST['title_template'][$id] ?? '');
            $body = trim($_POST['body_template'][$id] ?? '');
            // enabled is controlled by the global toggle — always keep rules active.
            Capsule::table('mod_smartersxconnect_payment_notifications')
                ->where('id', $id)
                ->update([
                    'enabled' => 1,
                    'title_template' => $title,
                    'body_template' => $body,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }

        header('Location: ' . $modulelink . '&action=notification&saved=1');
        exit;
    }

    private static function handleDeleteFcmCredentials($modulelink)
    {
        try {
            self::loadFirebaseLibs();
            \FirebaseAuth::clearServiceAccountJson();
            if (Capsule::schema()->hasTable('mod_smartersxconnect_notification_credentials')) {
                Capsule::table('mod_smartersxconnect_notification_credentials')->truncate();
            }
            // Also clear the test-sent flag so the guide resets to step 5.
            Capsule::table('tblconfiguration')
                ->where('setting', 'smartersx_test_notification_sent')
                ->delete();
            header('Location: ' . $modulelink . '&action=notification&fcm_deleted=1');
            exit;
        } catch (\Throwable $e) {
            header('Location: ' . $modulelink . '&action=notification&fcm_error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    private static function handleSaveFcmCredentials($modulelink)
    {
        try {
            self::loadNotificationHelpers();
            if (!function_exists('smartersxconnect_store_service_account_credentials')) {
                header('Location: ' . $modulelink . '&action=notification&fcm_error=' . urlencode('Credential storage helper is unavailable.'));
                exit;
            }

            $fileInfo = $_FILES['fcm_service_account_file'] ?? null;
            if (!$fileInfo || empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
                header('Location: ' . $modulelink . '&action=notification&fcm_error=' . urlencode('Please choose a valid JSON file to upload.'));
                exit;
            }

            $serviceAccountJson = file_get_contents($fileInfo['tmp_name']);
            $result = smartersxconnect_store_service_account_credentials($serviceAccountJson);
            if (empty($result['ok'])) {
                header('Location: ' . $modulelink . '&action=notification&fcm_error=' . urlencode($result['error'] ?? 'Unable to save FCM credentials.'));
                exit;
            }

            header('Location: ' . $modulelink . '&action=notification&fcm_saved=1');
            exit;
        } catch (\Throwable $e) {
            header('Location: ' . $modulelink . '&action=notification&fcm_error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    private static function handleSendTestNotification($modulelink)
    {
        try {
            self::loadNotificationHelpers();
            $returnAction = self::adminReturnAction($_POST['return_action'] ?? 'notification');
            $redirectBase = $modulelink . '&action=' . $returnAction;

            if (function_exists('smartersxconnect_ensure_notification_infrastructure_tables')) {
                smartersxconnect_ensure_notification_infrastructure_tables();
            }

            $devices = self::getConnectedNotificationDevices();

            $deviceCount = $devices ? count($devices) : 0;
            if ($deviceCount === 0) {
                header('Location: ' . $redirectBase . '&testerror=' . urlencode('No connected devices with notification tokens found.'));
                exit;
            }

            if (!function_exists('smartersxconnect_sendFCMNotification')) {
                header('Location: ' . $redirectBase . '&testerror=' . urlencode('Notification sender is not available.'));
                exit;
            }

            $result = smartersxconnect_sendFCMNotification(
                'Test Notification',
                'This is a test notification from WHMCS.',
                [
                    'id' => 'test_notification',
                    'event' => 'test_notification',
                ],
                $devices
            );

            $resultDecoded = is_string($result) ? json_decode($result, true) : null;
            if (is_array($resultDecoded) && isset($resultDecoded['error'])) {
                $errCode = $resultDecoded['error']['details'][0]['errorCode']
                    ?? $resultDecoded['error']['status']
                    ?? $resultDecoded['error']['message']
                    ?? 'FCM_ERROR';
                header('Location: ' . $redirectBase . '&testerror=' . urlencode('FCM error: ' . $errCode));
                exit;
            }
            if (is_string($result) && !isset($resultDecoded['name']) && stripos($result, 'error') !== false) {
                header('Location: ' . $redirectBase . '&testerror=' . urlencode($result));
                exit;
            }

            // Persist that a test was successfully sent so the setup guide shows green.
            Capsule::table('tblconfiguration')->updateOrInsert(
                ['setting' => 'smartersx_test_notification_sent'],
                ['value' => '1']
            );
            header('Location: ' . $redirectBase . '&testsent=1&testcount=' . $deviceCount);
            exit;
        } catch (\Throwable $e) {
            $returnAction = self::adminReturnAction($_POST['return_action'] ?? 'notification');
            header('Location: ' . $modulelink . '&action=' . $returnAction . '&testerror=' . urlencode($e->getMessage()));
            exit;
        }
    }

    private static function adminReturnAction($action)
    {
        return in_array($action, ['connections', 'notification'], true) ? $action : 'notification';
    }

    private static function getServiceAccountSummary()
    {
        $summary = [
            'configured' => false,
            'project_id' => '',
            'client_email' => '',
        ];

        try {
            self::loadFirebaseLibs();
            $json = \FirebaseAuth::getServiceAccountJson();
            if ($json) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $summary['configured'] = true;
                    $summary['project_id'] = (string) ($decoded['project_id'] ?? '');
                    $summary['client_email'] = (string) ($decoded['client_email'] ?? '');
                }
            }
        } catch (\Throwable $e) {}

        return $summary;
    }

    private static function getConnectedNotificationDevices()
    {
        if (!Capsule::schema()->hasTable('mod_smartersxconnect_notification_devices')) {
            return [];
        }

        return Capsule::table('mod_smartersxconnect_notification_devices')
            ->where('status', 1)
            ->where('devicetoken', '!=', '')
            ->get();
    }

    private static function loadNotificationHelpers()
    {
        if (!function_exists('smartersxconnect_sendFCMNotification')) {
            $hooksFile = dirname(__DIR__) . '/hooks.php';
            if (file_exists($hooksFile)) {
                require_once $hooksFile;
            }
        }
    }

    private static function ensurePaymentNotificationTable()
    {
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

        foreach (self::paymentNotificationDefaults() as $row) {
            $exists = Capsule::table('mod_smartersxconnect_payment_notifications')
                ->where('event_key', $row['event_key'])
                ->first();
            if (!$exists) {
                Capsule::table('mod_smartersxconnect_payment_notifications')->insert([
                    'event_key' => $row['event_key'],
                    'label' => $row['label'],
                    'enabled' => 1,
                    'title_template' => $row['title_template'],
                    'body_template' => $row['body_template'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    private static function paymentNotificationDefaults()
    {
        return [
            [
                'event_key' => 'payment_received',
                'label' => 'Payment Received',
                'title_template' => 'Payment received: {amount}',
                'body_template' => '{client_name} paid {amount} via {payment_method}. Transaction #{transaction_id}.',
            ]
        ];
    }

    private static function renderNotificationLogs($vars)
    {
        echo '<div class="tab-pane active" id="tab-logs">';
        echo '<h4>Notification Logs</h4>';

        if (!Capsule::schema()->hasTable('mod_smartersxconnect_notification_logs')) {
            echo '<div class="alert alert-info">No logs yet. Logs will appear here after the first notification is sent.</div>';
            echo '</div>';
            return;
        }

        $page     = max(1, (int) ($_GET['logpage'] ?? 1));
        $perPage  = 20;
        $offset   = ($page - 1) * $perPage;
        $total    = Capsule::table('mod_smartersxconnect_notification_logs')->count();
        $logs     = Capsule::table('mod_smartersxconnect_notification_logs')
            ->orderBy('id', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        if (!$logs || count($logs) === 0) {
            echo '<div class="alert alert-info">No notification logs found.</div>';
            echo '</div>';
            return;
        }

        // Clear log button
        $modulelink = $vars['modulelink'] ?? '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['clear_notification_logs'])) {
            Capsule::table('mod_smartersxconnect_notification_logs')->truncate();
            echo '<div class="alert alert-success">Logs cleared.</div>';
            echo '<meta http-equiv="refresh" content="0">';
            echo '</div>';
            return;
        }

        echo '<form method="post" style="margin-bottom:12px;" onsubmit="return confirm(\'Clear all notification logs?\');">';
        echo '<input type="hidden" name="clear_notification_logs" value="1">';
        echo '<button type="submit" class="btn btn-sm btn-danger">Clear All Logs</button>';
        echo '</form>';

        echo '<div class="table-responsive">';
        echo '<table class="table table-bordered table-striped table-condensed" style="font-size:13px;">';
        echo '<thead><tr><th>#</th><th>Date</th><th>Type / Title</th><th>Request (sent)</th><th>Response (FCM)</th></tr></thead>';
        echo '<tbody>';
        foreach ($logs as $log) {
            $reqDecoded = json_decode($log->request ?? '', true);
            $resDecoded = json_decode($log->response ?? '', true);

            // Summarise the request: show token + notification title
            if (is_array($reqDecoded) && isset($reqDecoded['message'])) {
                $token   = $reqDecoded['message']['token'] ?? '';
                $reqHtml = '<span title="' . htmlspecialchars($token) . '">'
                    . htmlspecialchars(substr($token, 0, 16)) . '…</span>';
            } else {
                $reqHtml = '<span class="text-muted">' . htmlspecialchars(substr($log->request ?? '', 0, 80)) . '</span>';
            }

            // Summarise the response: highlight errors
            if (is_array($resDecoded)) {
                $status = $resDecoded['name'] ?? ($resDecoded['error']['status'] ?? ($resDecoded['error']['message'] ?? null));
                if ($status) {
                    $isError = isset($resDecoded['error']);
                    $resHtml = '<span class="' . ($isError ? 'text-danger' : 'text-success') . '">'
                        . htmlspecialchars($status) . '</span>';
                } else {
                    $resHtml = '<code>' . htmlspecialchars(substr(json_encode($resDecoded), 0, 120)) . '</code>';
                }
            } else {
                $isErr   = stripos($log->response ?? '', 'error') !== false || stripos($log->response ?? '', 'Missing') !== false;
                $resHtml = '<span class="' . ($isErr ? 'text-danger' : 'text-muted') . '">'
                    . htmlspecialchars(substr($log->response ?? 'No response', 0, 120)) . '</span>';
            }

            echo '<tr>';
            echo '<td>' . (int) $log->id . '</td>';
            echo '<td style="white-space:nowrap;">' . htmlspecialchars($log->datetime ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($log->type ?? '') . '</td>';
            echo '<td>' . $reqHtml . '</td>';
            echo '<td>' . $resHtml . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // Pagination
        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages > 1) {
            $baseUrl = htmlspecialchars($modulelink . '&action=notificationlogs');
            echo '<nav><ul class="pagination pagination-sm">';
            for ($p = 1; $p <= $totalPages; $p++) {
                $active = $p === $page ? ' class="active"' : '';
                echo "<li{$active}><a href=\"{$baseUrl}&logpage={$p}\">{$p}</a></li>";
            }
            echo '</ul></nav>';
        }

        echo '<p class="text-muted" style="font-size:12px;">Showing ' . count($logs) . ' of ' . $total . ' entries (newest first).</p>';
        echo '</div>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Firebase Config — table, admin UI, upload handlers, parsers, API helper
    // ─────────────────────────────────────────────────────────────────────────

    private static function ensureFirebaseConfigTable()
    {
        if (!Capsule::schema()->hasTable('mod_smartersxconnect_firebase_config')) {
            Capsule::schema()->create('mod_smartersxconnect_firebase_config', function ($table) {
                $table->increments('id');
                $table->longText('android_google_services_json')->nullable();
                $table->timestamp('android_uploaded_at')->nullable();
                $table->longText('ios_google_service_plist')->nullable();
                $table->timestamp('ios_uploaded_at')->nullable();
            });
        }
    }



    private static function androidUploadForm($modulelink)
    {
        return '<form method="post" action="' . htmlspecialchars($modulelink . '&action=notification') . '" enctype="multipart/form-data">'
            . '<input type="hidden" name="save_firebase_android" value="1">'
            . '<div class="input-group" style="max-width:420px">'
            . '<input type="file" name="firebase_android_file" class="form-control" accept=".json,application/json" required>'
            . '<span class="input-group-btn"><button type="submit" class="btn btn-primary">Upload JSON</button></span>'
            . '</div>'
            . '<p class="help-block">Firebase Console &rarr; Project Settings &rarr; Android app &rarr; <code>google-services.json</code></p>'
            . '</form>';
    }

    private static function iosUploadForm($modulelink)
    {
        return '<form method="post" action="' . htmlspecialchars($modulelink . '&action=notification') . '" enctype="multipart/form-data">'
            . '<input type="hidden" name="save_firebase_ios" value="1">'
            . '<div class="input-group" style="max-width:420px">'
            . '<input type="file" name="firebase_ios_file" class="form-control" accept=".plist,text/xml,application/xml" required>'
            . '<span class="input-group-btn"><button type="submit" class="btn btn-primary">Upload Plist</button></span>'
            . '</div>'
            . '<p class="help-block">Firebase Console &rarr; Project Settings &rarr; iOS app &rarr; <code>GoogleService-Info.plist</code></p>'
            . '</form>';
    }

    private static function handleSaveFirebaseAndroid($modulelink)
    {
        try {
            self::ensureFirebaseConfigTable();
            $fileInfo = $_FILES['firebase_android_file'] ?? null;
            if (!$fileInfo || empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
                header('Location: ' . $modulelink . '&action=firebase&fb_error=' . urlencode('Please choose a valid JSON file.'));
                exit;
            }
            $content = file_get_contents($fileInfo['tmp_name']);
            $decoded = json_decode($content, true);
            if (!is_array($decoded) || empty($decoded['project_info']) || empty($decoded['client'])) {
                header('Location: ' . $modulelink . '&action=firebase&fb_error=' . urlencode('Invalid google-services.json — missing project_info or client fields.'));
                exit;
            }
            $row = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
            if ($row) {
                Capsule::table('mod_smartersxconnect_firebase_config')
                    ->where('id', $row->id)
                    ->update(['android_google_services_json' => $content, 'android_uploaded_at' => date('Y-m-d H:i:s')]);
            } else {
                Capsule::table('mod_smartersxconnect_firebase_config')
                    ->insert(['android_google_services_json' => $content, 'android_uploaded_at' => date('Y-m-d H:i:s')]);
            }
            header('Location: ' . $modulelink . '&action=notification&fb_saved=android');
            exit;
        } catch (\Throwable $e) {
            header('Location: ' . $modulelink . '&action=notification&fb_error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    private static function handleSaveFirebaseIos($modulelink)
    {
        try {
            self::ensureFirebaseConfigTable();
            $fileInfo = $_FILES['firebase_ios_file'] ?? null;
            if (!$fileInfo || empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
                header('Location: ' . $modulelink . '&action=notification&fb_error=' . urlencode('Please choose a valid plist file.'));
                exit;
            }
            $content = file_get_contents($fileInfo['tmp_name']);
            $parsed  = self::parsePlistToArray($content);
            if (!is_array($parsed) || empty($parsed['GOOGLE_APP_ID'])) {
                header('Location: ' . $modulelink . '&action=notification&fb_error=' . urlencode('Invalid GoogleService-Info.plist — GOOGLE_APP_ID not found.'));
                exit;
            }
            $row = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
            if ($row) {
                Capsule::table('mod_smartersxconnect_firebase_config')
                    ->where('id', $row->id)
                    ->update(['ios_google_service_plist' => $content, 'ios_uploaded_at' => date('Y-m-d H:i:s')]);
            } else {
                Capsule::table('mod_smartersxconnect_firebase_config')
                    ->insert(['ios_google_service_plist' => $content, 'ios_uploaded_at' => date('Y-m-d H:i:s')]);
            }
            header('Location: ' . $modulelink . '&action=notification&fb_saved=ios');
            exit;
        } catch (\Throwable $e) {
            header('Location: ' . $modulelink . '&action=notification&fb_error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    private static function handleDeleteFirebaseConfig($modulelink, $platform)
    {
        try {
            self::ensureFirebaseConfigTable();
            $row = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
            if ($row) {
                if ($platform === 'android') {
                    Capsule::table('mod_smartersxconnect_firebase_config')
                        ->where('id', $row->id)
                        ->update(['android_google_services_json' => null, 'android_uploaded_at' => null]);
                } else {
                    Capsule::table('mod_smartersxconnect_firebase_config')
                        ->where('id', $row->id)
                        ->update(['ios_google_service_plist' => null, 'ios_uploaded_at' => null]);
                }
            }
            header('Location: ' . $modulelink . '&action=notification&fb_deleted=' . $platform);
            exit;
        } catch (\Throwable $e) {
            header('Location: ' . $modulelink . '&action=notification&fb_error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    private static function parsePlistToArray($xmlString)
    {
        try {
            $xml = @simplexml_load_string((string) $xmlString);
            if (!$xml) {
                return null;
            }
            // Apple plist: <dict> contains alternating <key> and value nodes
            $dict = $xml->dict ?? null;
            if (!$dict) {
                return null;
            }
            $result = [];
            $nodes  = $dict->children();
            $keys   = [];
            $vals   = [];
            foreach ($nodes as $name => $node) {
                if ($name === 'key') {
                    $keys[] = (string) $node;
                } else {
                    $vals[] = ['type' => $name, 'node' => $node];
                }
            }
            foreach ($keys as $i => $key) {
                if (!isset($vals[$i])) {
                    continue;
                }
                $type = $vals[$i]['type'];
                $node = $vals[$i]['node'];
                if ($type === 'string') {
                    $result[$key] = (string) $node;
                } elseif ($type === 'true') {
                    $result[$key] = true;
                } elseif ($type === 'false') {
                    $result[$key] = false;
                } elseif ($type === 'integer') {
                    $result[$key] = (int) (string) $node;
                } else {
                    $result[$key] = (string) $node;
                }
            }
            return $result;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function extractAndroidFirebaseOptions($jsonString)
    {
        $data = json_decode((string) $jsonString, true);
        if (!is_array($data)) {
            return null;
        }
        $projectInfo = $data['project_info'] ?? [];
        $client      = $data['client'][0] ?? [];
        $apiKey      = $client['api_key'][0]['current_key'] ?? '';
        $appId       = $client['client_info']['mobilesdk_app_id'] ?? '';

        return [
            'apiKey'            => (string) $apiKey,
            'appId'             => (string) $appId,
            'messagingSenderId' => (string) ($projectInfo['project_number'] ?? ''),
            'projectId'         => (string) ($projectInfo['project_id'] ?? ''),
            'storageBucket'     => (string) ($projectInfo['storage_bucket'] ?? ''),
        ];
    }

    private static function extractIosFirebaseOptions($plistString)
    {
        $data = self::parsePlistToArray((string) $plistString);
        if (!is_array($data)) {
            return null;
        }

        return [
            'apiKey'            => (string) ($data['API_KEY'] ?? ''),
            'appId'             => (string) ($data['GOOGLE_APP_ID'] ?? ''),
            'messagingSenderId' => (string) ($data['GCM_SENDER_ID'] ?? ''),
            'projectId'         => (string) ($data['PROJECT_ID'] ?? ''),
            'storageBucket'     => (string) ($data['STORAGE_BUCKET'] ?? ''),
            'iosBundleId'       => (string) ($data['BUNDLE_ID'] ?? ''),
        ];
    }

    private static function renderInfo($vars)
    {
        echo '<div class="tab-pane active" id="tab-info">';
        echo '<h4>About Us</h4>';
        echo '<p>Smart Insights. Anytime. Anywhere.</p>';
        echo '<p>
 
SmartersX Connect is an advanced WHMCS add-on module developed by <a href="https://www.whmcssmarters.com/">WHMCS SMARTERS Pvt. Ltd.</a> that securely connects your WHMCS installation with the <a href="https://www.smartersx.com/">SmartersX</a> Mobile Application for <a href="https://play.google.com/store/apps/details?id=com.techsmarters.smarterx&hl=en_IN">Android</a> and <a href="https://apps.apple.com/in/app/smartersx/id1643695817">iOS</a>.</p>';
        echo '<p>
 
Designed for hosting companies, VPN providers, OTT businesses, software vendors, and service providers, <a href="https://www.smartersx.com/">SmartersX</a> Connect transforms your WHMCS data into real-time business insights. Instantly monitor today’s sales, monthly revenue, yearly performance, new orders, active clients, paid invoices, growth trends, and other key business metrics directly from your mobile device.</p>';
        echo '<p>
 
Whether you’re in the office, traveling, or attending meetings, <a href="https://www.smartersx.com/">SmartersX</a> keeps you connected to your business with the information that matters most—anytime, anywhere.
 </p>';
        echo '</div><style>
#tab-info p {
    margin-bottom: 12px;    line-height: 1.6;
}
#tab-info a {
    color: #337ab7;    text-decoration: underline;
    }
        </style>';
    }

    // ── Firebase Manager helpers ──────────────────────────────────────────────

    private static function maybeAutoSyncFirebaseConfigs(bool $force = false): void
    {
        self::loadFirebaseLibs();
        if (!\FirebaseAuth::isConnected()) return;

        $oauthRow  = \FirebaseAuth::getRow();
        if (!$oauthRow) return;

        $projectId = $oauthRow->selected_project_id ?? '';
        $androidId = $oauthRow->android_app_id ?? '';
        $iosId     = $oauthRow->ios_app_id ?? '';
        if (!$projectId) return;

        self::ensureFirebaseConfigTable();
        $fbRow = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();

        $needAndroid = $androidId && ($force || !$fbRow || empty($fbRow->android_google_services_json));
        $needIos     = $iosId     && ($force || !$fbRow || empty($fbRow->ios_google_service_plist));
        if (!$needAndroid && !$needIos) return;

        $token = \FirebaseAuth::getValidAccessToken();
        if (!$token) return;

        if ($needAndroid) {
            $r = \FirebaseAPI::getAndroidConfig($token, $projectId, $androidId);
            if (empty($r['error'])) {
                $payload = ['android_google_services_json' => $r['content'], 'android_uploaded_at' => date('Y-m-d H:i:s')];
                if ($fbRow) {
                    Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $fbRow->id)->update($payload);
                } else {
                    $newId = Capsule::table('mod_smartersxconnect_firebase_config')->insertGetId($payload);
                    $fbRow = Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $newId)->first();
                }
            }
        }

        if ($needIos) {
            $r = \FirebaseAPI::getIosConfig($token, $projectId, $iosId);
            if (empty($r['error'])) {
                $payload = ['ios_google_service_plist' => $r['content'], 'ios_uploaded_at' => date('Y-m-d H:i:s')];
                if ($fbRow) {
                    Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $fbRow->id)->update($payload);
                } else {
                    Capsule::table('mod_smartersxconnect_firebase_config')->insert($payload);
                }
            }
        }
    }

    private static function loadFirebaseLibs(): void
    {
        static $loaded = false;
        if ($loaded) return;
        require_once __DIR__ . '/FirebaseAuth.php';
        require_once __DIR__ . '/FirebaseAPI.php';
        $loaded = true;
    }

    private static function fbMgrAbsoluteModulelink(): string
    {
        $systemUrl   = rtrim(\App::getSystemURL(), '/');
        $adminFolder = \App::get_admin_folder_name();
        return $systemUrl . '/' . $adminFolder . '/addonmodules.php?module=smartersxconnect';
    }

    private static function fbMgrRedirectUri(): string
    {
        return self::fbMgrAbsoluteModulelink() . '&action=firebase_manager';
    }

    private static function handleFirebaseDownload(string $modulelink): void
    {
        $type = $_GET['firebase_download'] ?? '';
        if (!in_array($type, ['android', 'ios', 'server'], true)) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Invalid download type.'));
            exit;
        }

        $token = \FirebaseAuth::getValidAccessToken();
        if (!$token) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Session expired. Please re-authenticate.'));
            exit;
        }

        $row       = \FirebaseAuth::getRow();
        $projectId = $row ? ($row->selected_project_id ?? '') : '';
        if (!$projectId) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('No project selected.'));
            exit;
        }

        if ($type === 'android') {
            $appId = $row ? ($row->android_app_id ?? '') : '';
            if (!$appId) {
                header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('No Android app registered.'));
                exit;
            }
            $result = \FirebaseAPI::getAndroidConfig($token, $projectId, $appId);
        } elseif ($type === 'ios') {
            $appId = $row ? ($row->ios_app_id ?? '') : '';
            if (!$appId) {
                header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('No iOS app registered.'));
                exit;
            }
            $result = \FirebaseAPI::getIosConfig($token, $projectId, $appId);
        } else {
            $result = \FirebaseAPI::getServiceAccountKey($token, $projectId);
        }

        if (!empty($result['error'])) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode($result['error']));
            exit;
        }

        // Auto-save android/ios configs to firebase_config table for the mobile API.
        // Auto-save server keys as FCM credentials for payment notification sends.
        if ($type === 'android') {
            self::ensureFirebaseConfigTable();
            $fbRow = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
            if ($fbRow) {
                Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $fbRow->id)->update([
                    'android_google_services_json' => $result['content'],
                    'android_uploaded_at'           => date('Y-m-d H:i:s'),
                ]);
            } else {
                Capsule::table('mod_smartersxconnect_firebase_config')->insert([
                    'android_google_services_json' => $result['content'],
                    'android_uploaded_at'           => date('Y-m-d H:i:s'),
                ]);
            }
        } elseif ($type === 'ios') {
            self::ensureFirebaseConfigTable();
            $fbRow = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
            if ($fbRow) {
                Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $fbRow->id)->update([
                    'ios_google_service_plist' => $result['content'],
                    'ios_uploaded_at'           => date('Y-m-d H:i:s'),
                ]);
            } else {
                Capsule::table('mod_smartersxconnect_firebase_config')->insert([
                    'ios_google_service_plist' => $result['content'],
                    'ios_uploaded_at'           => date('Y-m-d H:i:s'),
                ]);
            }
        } elseif ($type === 'server') {
            self::loadNotificationHelpers();
            if (function_exists('smartersxconnect_store_service_account_credentials')) {
                $saveResult = smartersxconnect_store_service_account_credentials($result['content']);
                if (empty($saveResult['ok'])) {
                    header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode($saveResult['error'] ?? 'Unable to save server key.'));
                    exit;
                }
            }
        }

        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($result['filename']) . '"');
        header('Content-Length: ' . strlen($result['content']));
        header('Cache-Control: no-cache, must-revalidate');
        echo $result['content'];
        exit;
    }

    private static function handleFirebaseOAuthCallback(string $modulelink): void
    {
        $code  = trim($_GET['code'] ?? '');
        $state = trim($_GET['state'] ?? '');

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['fb_oauth_state']) || $state !== $_SESSION['fb_oauth_state']) {
            unset($_SESSION['fb_oauth_state']);
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('OAuth state mismatch. Please try again.'));
            exit;
        }
        unset($_SESSION['fb_oauth_state']);

        if (empty($code)) {
            $errMsg = trim($_GET['error_description'] ?? ($_GET['error'] ?? 'OAuth authorization was denied.'));
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode($errMsg));
            exit;
        }

        $result = \FirebaseAuth::exchangeCode($code, self::fbMgrRedirectUri());
        if (!empty($result['error'])) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode($result['error']));
            exit;
        }
        header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_connected=1');
        exit;
    }

    private static function handleSaveFirebaseOAuthCreds(string $modulelink): void
    {
        $clientId     = trim($_POST['fb_client_id'] ?? '');
        $clientSecret = trim($_POST['fb_client_secret'] ?? '');
        if (empty($clientId)) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Client ID is required.'));
            exit;
        }
        \FirebaseAuth::saveCredentials($clientId, $clientSecret);
        header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_saved=1');
        exit;
    }

    private static function handleFirebaseOAuthDisconnect(string $modulelink): void
    {
        \FirebaseAuth::disconnect();
        header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_disconnected=1');
        exit;
    }

    private static function handleFirebaseSelectProject(string $modulelink): void
    {
        set_time_limit(300);
        $projectId = trim($_POST['fb_project_id'] ?? '');
        if (empty($projectId)) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Please select a project.'));
            exit;
        }
        \FirebaseAuth::saveProjectId($projectId);

        $token = \FirebaseAuth::getValidAccessToken();
        if (!$token) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Access token expired — please re-authenticate in Step 1.'));
            exit;
        }

        // Fetch service account key for FCM sending
        $saError = null;
        $keyResult = \FirebaseAPI::getServiceAccountKey($token, $projectId);
        if (empty($keyResult['error'])) {
            self::loadNotificationHelpers();
            if (function_exists('smartersxconnect_store_service_account_credentials')) {
                smartersxconnect_store_service_account_credentials($keyResult['content']);
            }
        } else {
            $saError = $keyResult['error'];
        }

        // Auto-register Android app
        $packageName     = 'com.techsmarters.smarterx';
        $whmcsName       = Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->value('value') ?? '';
        $androidResult   = \FirebaseAPI::createAndroidApp($token, $projectId, $packageName, $whmcsName);
        $androidError    = null;
        if (!empty($androidResult['error'])) {
            $androidError = $androidResult['error'];
        } else {
            $androidAppId = $androidResult['appId'] ?? '';
            \FirebaseAuth::saveAndroidAppId($androidAppId);
            if ($androidAppId) {
                $cfg = \FirebaseAPI::getAndroidConfig($token, $projectId, $androidAppId);
                if (empty($cfg['error'])) {
                    self::ensureFirebaseConfigTable();
                    $fbRow   = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
                    $payload = ['android_google_services_json' => $cfg['content'], 'android_uploaded_at' => date('Y-m-d H:i:s')];
                    if ($fbRow) Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $fbRow->id)->update($payload);
                    else        Capsule::table('mod_smartersxconnect_firebase_config')->insert($payload);
                }
            }
        }

        // Auto-register iOS app
        $bundleId    = 'com.techsmarters.smarterx';
        $appStoreId  = '1643695817';
        $iosResult   = \FirebaseAPI::createIosApp($token, $projectId, $bundleId, $whmcsName, $appStoreId);
        $iosError    = null;
        if (!empty($iosResult['error'])) {
            $iosError = $iosResult['error'];
        } else {
            $iosAppId = $iosResult['appId'] ?? '';
            \FirebaseAuth::saveIosAppId($iosAppId);
            if ($iosAppId) {
                $cfg = \FirebaseAPI::getIosConfig($token, $projectId, $iosAppId);
                if (empty($cfg['error'])) {
                    self::ensureFirebaseConfigTable();
                    $fbRow   = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
                    $payload = ['ios_google_service_plist' => $cfg['content'], 'ios_uploaded_at' => date('Y-m-d H:i:s')];
                    if ($fbRow) Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $fbRow->id)->update($payload);
                    else        Capsule::table('mod_smartersxconnect_firebase_config')->insert($payload);
                }
            }
        }

        // Build redirect with consolidated status
        $params = '&fb_mgr_project_saved=1';
        if ($saError)     $params .= '&fb_mgr_sa_warn='      . urlencode($saError);
        if ($androidError) $params .= '&fb_mgr_android_warn=' . urlencode($androidError);
        if ($iosError)     $params .= '&fb_mgr_ios_warn='     . urlencode($iosError);
        if (!$androidError && !$iosError) $params .= '&fb_mgr_apps_created=1';

        header('Location: ' . $modulelink . '&action=firebase_manager' . $params);
        exit;
    }

    private static function handleRefetchServiceAccount(string $modulelink): void
    {
        self::loadFirebaseLibs();
        $row = \FirebaseAuth::getRow();
        $projectId = $row ? ($row->selected_project_id ?? '') : '';
        if (!$projectId) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('No project selected. Complete Step 2 first.'));
            exit;
        }
        $token = \FirebaseAuth::getValidAccessToken();
        if (!$token) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Access token expired. Please re-authenticate in Step 1.'));
            exit;
        }
        $keyResult = \FirebaseAPI::getServiceAccountKey($token, $projectId);
        if (!empty($keyResult['error'])) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Service account fetch failed: ' . $keyResult['error']));
            exit;
        }
        self::loadNotificationHelpers();
        if (function_exists('smartersxconnect_store_service_account_credentials')) {
            smartersxconnect_store_service_account_credentials($keyResult['content']);
        }
        header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_sa_ok=1');
        exit;
    }

    private static function handleFirebaseCreateAndroid(string $modulelink): void
    {
        set_time_limit(120);
        $packageName = trim($_POST['fb_android_package'] ?? '');
        $displayName = trim($_POST['fb_android_name'] ?? '');
        if (empty($packageName)) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Package name is required.'));
            exit;
        }
        $token = \FirebaseAuth::getValidAccessToken();
        if (!$token) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Session expired. Please re-authenticate.'));
            exit;
        }
        $row       = \FirebaseAuth::getRow();
        $projectId = $row ? ($row->selected_project_id ?? '') : '';
        if (!$projectId) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('No project selected.'));
            exit;
        }
        $result = \FirebaseAPI::createAndroidApp($token, $projectId, $packageName, $displayName);
        if (!empty($result['error'])) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode($result['error']));
            exit;
        }
        $appId = $result['appId'] ?? '';
        \FirebaseAuth::saveAndroidAppId($appId);
        // Auto-save config to DB immediately so Notifications tab shows it right away
        if ($appId) {
            $cfg = \FirebaseAPI::getAndroidConfig($token, $projectId, $appId);
            if (empty($cfg['error'])) {
                self::ensureFirebaseConfigTable();
                $fbRow = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
                $payload = ['android_google_services_json' => $cfg['content'], 'android_uploaded_at' => date('Y-m-d H:i:s')];
                if ($fbRow) Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $fbRow->id)->update($payload);
                else Capsule::table('mod_smartersxconnect_firebase_config')->insert($payload);
            }
        }
        header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_app_created=android');
        exit;
    }

    private static function handleFirebaseCreateIos(string $modulelink): void
    {
        set_time_limit(120);
        $bundleId    = trim($_POST['fb_ios_bundle'] ?? '');
        $displayName = trim($_POST['fb_ios_name'] ?? '');
        $appStoreId  = trim($_POST['fb_ios_appstore'] ?? '');
        if (empty($bundleId)) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Bundle ID is required.'));
            exit;
        }
        $token = \FirebaseAuth::getValidAccessToken();
        if (!$token) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('Session expired. Please re-authenticate.'));
            exit;
        }
        $row       = \FirebaseAuth::getRow();
        $projectId = $row ? ($row->selected_project_id ?? '') : '';
        if (!$projectId) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode('No project selected.'));
            exit;
        }
        $result = \FirebaseAPI::createIosApp($token, $projectId, $bundleId, $displayName, $appStoreId);
        if (!empty($result['error'])) {
            header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_error=' . urlencode($result['error']));
            exit;
        }
        $appId = $result['appId'] ?? '';
        \FirebaseAuth::saveIosAppId($appId);
        // Auto-save config to DB immediately so Notifications tab shows it right away
        if ($appId) {
            $cfg = \FirebaseAPI::getIosConfig($token, $projectId, $appId);
            if (empty($cfg['error'])) {
                self::ensureFirebaseConfigTable();
                $fbRow = Capsule::table('mod_smartersxconnect_firebase_config')->orderBy('id', 'asc')->first();
                $payload = ['ios_google_service_plist' => $cfg['content'], 'ios_uploaded_at' => date('Y-m-d H:i:s')];
                if ($fbRow) Capsule::table('mod_smartersxconnect_firebase_config')->where('id', $fbRow->id)->update($payload);
                else Capsule::table('mod_smartersxconnect_firebase_config')->insert($payload);
            }
        }
        header('Location: ' . $modulelink . '&action=firebase_manager&fb_mgr_app_created=ios');
        exit;
    }

    private static function renderFirebaseManager(array $vars): void
    {
        $modulelink  = $vars['modulelink'] ?? '';
        $row         = \FirebaseAuth::getRow();
        $isConnected = \FirebaseAuth::isConnected();
        $hasCreds    = \FirebaseAuth::hasCredentials();
        $projectId   = $row ? ($row->selected_project_id ?? '') : '';
        $androidId   = $row ? ($row->android_app_id ?? '') : '';
        $iosId       = $row ? ($row->ios_app_id ?? '') : '';

        // Build the absolute redirect URI once — used for both the auth button and token exchange
        $absModulelink  = self::fbMgrAbsoluteModulelink();
        $oauthRedirect  = $absModulelink . '&action=firebase_manager';
        $ml             = htmlspecialchars($modulelink);

        // Defaults for Step 3 forms
        $defaultBundleId   = 'com.techsmarters.smarterx';
        $whmcsCompanyName  = Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->value('value') ?? '';

        // Alerts
        if (!empty($_GET['fb_mgr_error'])) {
            echo '<div class="alert alert-danger"><strong>Error:</strong> ' . htmlspecialchars($_GET['fb_mgr_error']) . '</div>';
        }
        if (!empty($_GET['fb_mgr_connected'])) {
            echo '<div class="alert alert-success"><strong>Connected!</strong> Google account linked successfully.</div>';
        }
        if (!empty($_GET['fb_mgr_saved'])) {
            echo '<div class="alert alert-success">OAuth credentials saved.</div>';
        }
        if (!empty($_GET['fb_mgr_disconnected'])) {
            echo '<div class="alert alert-info">Disconnected from Google. Credentials are still saved.</div>';
        }
        if (!empty($_GET['fb_mgr_project_saved'])) {
            echo '<div class="alert alert-success">Firebase project selected.</div>';
        }
        if (!empty($_GET['fb_mgr_sa_ok'])) {
            echo '<div class="alert alert-success">Firebase service account saved for payment notifications.</div>';
        }
        if (!empty($_GET['fb_mgr_sa_warn'])) {
            echo '<div class="alert alert-warning"><strong>Firebase project selected, but service account was not saved:</strong> ' . htmlspecialchars($_GET['fb_mgr_sa_warn']) . '</div>';
        }
        if (!empty($_GET['fb_mgr_app_created'])) {
            $which = $_GET['fb_mgr_app_created'] === 'android' ? 'Android' : 'iOS';
            echo '<div class="alert alert-success"><strong>' . $which . ' app created!</strong> Proceed to Step 4 to configure APNs, then Step 5 to download config files.</div>';
        }
        if (!empty($_GET['fb_mgr_apps_created'])) {
            echo '<div class="alert alert-success"><strong>Project selected &amp; both apps registered!</strong> Android and iOS apps are ready. Proceed to Step 4 to configure APNs.</div>';
        }
        if (!empty($_GET['fb_mgr_android_warn'])) {
            echo '<div class="alert alert-warning"><strong>Android app registration failed:</strong> ' . htmlspecialchars($_GET['fb_mgr_android_warn']) . '</div>';
        }
        if (!empty($_GET['fb_mgr_ios_warn'])) {
            echo '<div class="alert alert-warning"><strong>iOS app registration failed:</strong> ' . htmlspecialchars($_GET['fb_mgr_ios_warn']) . '</div>';
        }

        echo '<style>
.fb-step{margin-bottom:18px}
.fb-step .panel-heading{display:flex;align-items:center;gap:10px}
.fb-step-num{display:inline-flex;align-items:center;justify-content:center;
  width:26px;height:26px;border-radius:50%;font-weight:700;font-size:13px;
  background:#337ab7;color:#fff;flex-shrink:0}
.fb-step-num.done{background:#5cb85c}
.fb-step-num.locked{background:#bbb}
.fb-step.locked{opacity:.55;pointer-events:none}
.fb-app-badge{display:inline-block;padding:3px 9px;background:#f5f5f5;
  border:1px solid #ddd;border-radius:3px;font-family:monospace;font-size:12px}
</style>';

        // ── Step 1: OAuth credentials ─────────────────────────────────────────
        $usingDummy = \FirebaseAuth::isUsingDefaultCredentials();
        $s1badge    = ($isConnected && !$usingDummy) ? 'done' : ($isConnected ? 'done' : '');
        echo '<div class="panel panel-default fb-step">';
        echo '<div class="panel-heading"><span class="fb-step-num ' . $s1badge . '">1</span>'
            . '<strong>Connect Google Account</strong>';
        if ($isConnected && !$usingDummy) echo ' <span class="label label-success" style="margin-left:6px">&#10003; Connected</span>';
        if ($usingDummy)                  echo ' <span class="label label-warning" style="margin-left:6px">Default Config</span>';
        echo '</div><div class="panel-body">';

        if ($usingDummy) {
            echo '<div class="alert alert-warning" style="margin-bottom:14px">'
                . '<strong>Default (Demo) Configuration Active.</strong> '
                . 'The system is using built-in demo Firebase credentials. '
                . 'Enter your own Google OAuth credentials below and click <strong>Save Credentials</strong> to connect your own Firebase project.</div>';
        }

        if (!$isConnected || $usingDummy) {
            if ($row && !empty($row->client_id) && !$usingDummy) {
                $storedClientId     = htmlspecialchars(substr($row->client_id, 0, 24) . '...');
                $storedClientSecret = '';
            } elseif ($usingDummy) {
                $storedClientId     = htmlspecialchars($row->client_id ?? \FirebaseAuth::DEFAULT_CLIENT_ID);
                $storedClientSecret = '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';
            } else {
                $storedClientId     = '';
                $storedClientSecret = '';
            }

            echo '<p class="text-muted" style="margin-bottom:12px">Create OAuth 2.0 credentials in '
                . '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> '
                . '(type: <strong>Web application</strong>). Add this as an Authorised redirect URI:<br>'
                . '<code>' . htmlspecialchars($oauthRedirect) . '</code></p>';

            echo '<form method="post" action="' . $ml . '&action=firebase_manager">';
            echo '<div class="form-group"><label>Client ID</label>'
                . '<input type="text" name="fb_client_id" class="form-control" required '
                . 'placeholder="xxxx.apps.googleusercontent.com"'
                . ($storedClientId ? ' value="' . $storedClientId . '"' : '') . '></div>';
            echo '<div class="form-group"><label>Client Secret</label>'
                . '<input type="password" name="fb_client_secret" class="form-control" '
                . ($hasCreds && !$usingDummy ? 'placeholder="(leave blank to keep current)"' : 'required placeholder="GOCSPx-..."')
                . ($storedClientSecret ? ' value="' . htmlspecialchars($storedClientSecret) . '"' : '')
                . '></div>';
            echo '<button type="submit" name="save_firebase_oauth_creds" value="1" class="btn btn-default">Save Credentials</button>';

            if ($hasCreds && !$usingDummy) {
                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                $state = bin2hex(random_bytes(12));
                $_SESSION['fb_oauth_state'] = $state;
                $authUrl = \FirebaseAuth::getAuthUrl($oauthRedirect, $state);
                echo ' <a href="' . htmlspecialchars($authUrl) . '" class="btn btn-primary" style="margin-left:8px">'
                    . '<span class="glyphicon glyphicon-log-in"></span> Connect with Google</a>';
            }
            echo '</form>';
        } else {
            echo '<p>Google OAuth is active and will refresh automatically.</p>';
            echo '<form method="post" action="' . $ml . '&action=firebase_manager" style="display:inline">'
                . '<button type="submit" name="firebase_oauth_disconnect" value="1" class="btn btn-danger btn-sm" '
                . 'onclick="return confirm(\'Disconnect? The selected project and app IDs will be cleared.\')">Disconnect</button></form>';

            if ($hasCreds) {
                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                $state = bin2hex(random_bytes(12));
                $_SESSION['fb_oauth_state'] = $state;
                $authUrl = \FirebaseAuth::getAuthUrl($oauthRedirect, $state);
                echo ' <a href="' . htmlspecialchars($authUrl) . '" class="btn btn-default btn-sm">Re-authenticate</a>';
            }
        }
        echo '</div></div>';

        // ── Step 2: Select project ────────────────────────────────────────────
        $s2locked = !$isConnected;
        $s2done   = $isConnected && $projectId !== '';
        $s2badge  = $s2done ? 'done' : ($s2locked ? 'locked' : '');

        echo '<div class="panel panel-default fb-step' . ($s2locked ? ' locked' : '') . '">';
        echo '<div class="panel-heading"><span class="fb-step-num ' . $s2badge . '">2</span>'
            . '<strong>Select Firebase Project</strong>';
        if ($s2done) echo ' <span class="label label-success" style="margin-left:6px">&#10003; ' . htmlspecialchars($projectId) . '</span>';
        echo '</div><div class="panel-body">';

        if ($isConnected) {
            $token      = \FirebaseAuth::getValidAccessToken();
            $projects   = [];
            $listErr    = null;
            if ($token) {
                $listRes = \FirebaseAPI::listProjects($token);
                if (isset($listRes['error'])) $listErr = $listRes['error'];
                else $projects = $listRes['projects'];
            } else {
                $listErr = 'Access token expired. Please re-authenticate in Step 1.';
            }

            if ($listErr) {
                echo '<div class="alert alert-warning">' . htmlspecialchars($listErr) . '</div>';
            } elseif (empty($projects)) {
                echo '<p class="text-muted">No Firebase projects found. '
                    . '<a href="https://console.firebase.google.com/" target="_blank" rel="noopener">Create a project</a> first, then return here.</p>';
            } else {
                echo '<form method="post" action="' . $ml . '&action=firebase_manager">';
                echo '<div class="form-group"><label>Firebase Project</label>'
                    . '<select name="fb_project_id" class="form-control" style="max-width:440px">';
                foreach ($projects as $p) {
                    $pid  = htmlspecialchars($p['projectId'] ?? '');
                    $pnm  = htmlspecialchars($p['displayName'] ?? ($p['projectId'] ?? ''));
                    $sel  = ($pid === htmlspecialchars($projectId)) ? ' selected' : '';
                    echo '<option value="' . $pid . '"' . $sel . '>' . $pnm . ' (' . $pid . ')</option>';
                }
                echo '</select></div>';
                echo '<button type="submit" name="firebase_select_project" value="1" class="btn btn-primary">Select Project</button>';
                echo '</form>';
            }
        } else {
            echo '<p class="text-muted">Complete Step 1 first.</p>';
        }
        echo '</div></div>';

        // ── Step 3: Create apps (auto-handled on project select — hidden from admin) ──
        $s3locked = !$s2done;
        $s3done   = $s2done && ($androidId !== '' || $iosId !== '');
        $s3badge  = $s3done ? 'done' : ($s3locked ? 'locked' : '');

        echo '<div class="panel panel-default fb-step' . ($s3locked ? ' locked' : '') . '" style="display:none">';
        echo '<div class="panel-heading"><span class="fb-step-num ' . $s3badge . '">3</span>'
            . '<strong>Register Firebase Apps</strong></div><div class="panel-body">';

        if ($s2done) {
            // Android
            echo '<h4 style="margin-top:0">Android App</h4>';
            if ($androidId !== '') {
                echo '<p>App ID: <span class="fb-app-badge">' . htmlspecialchars($androidId) . '</span></p>';
                echo '<form method="post" action="' . $ml . '&action=firebase_manager" style="display:inline">'
                    . '<button type="submit" name="firebase_reset_android" value="1" class="btn btn-default btn-xs" '
                    . 'onclick="return confirm(\'Clear saved Android App ID? The app stays in Firebase Console.\')">Clear &amp; Re-enter</button></form>';
            } else {
                echo '<form method="post" action="' . $ml . '&action=firebase_manager">';
                echo '<div class="row">'
                    . '<div class="col-sm-5"><div class="form-group"><label>Package Name <span class="text-danger">*</span></label>'
                    . '<input type="text" name="fb_android_package" class="form-control" required value="' . htmlspecialchars($defaultBundleId) . '"></div></div>'
                    . '<div class="col-sm-4"><div class="form-group"><label>Display Name</label>'
                    . '<input type="text" name="fb_android_name" class="form-control" value="' . htmlspecialchars($whmcsCompanyName) . '" placeholder="My App"></div></div>'
                    . '</div>';
                echo '<button type="submit" name="firebase_create_android" value="1" class="btn btn-success">Create Android App</button>'
                    . ' <span class="help-block" style="display:inline;margin-left:10px;font-size:12px">Calls Firebase API &mdash; may take 30&ndash;60 s.</span>';
                echo '</form>';
            }

            echo '<hr style="margin:16px 0">';

            // iOS
            echo '<h4>iOS App</h4>';
            if ($iosId !== '') {
                echo '<p>App ID: <span class="fb-app-badge">' . htmlspecialchars($iosId) . '</span></p>';
                echo '<form method="post" action="' . $ml . '&action=firebase_manager" style="display:inline">'
                    . '<button type="submit" name="firebase_reset_ios" value="1" class="btn btn-default btn-xs" '
                    . 'onclick="return confirm(\'Clear saved iOS App ID? The app stays in Firebase Console.\')">Clear &amp; Re-enter</button></form>';
            } else {
                echo '<form method="post" action="' . $ml . '&action=firebase_manager">';
                echo '<div class="row">'
                    . '<div class="col-sm-5"><div class="form-group"><label>Bundle ID <span class="text-danger">*</span></label>'
                    . '<input type="text" name="fb_ios_bundle" class="form-control" required value="' . htmlspecialchars($defaultBundleId) . '"></div></div>'
                    . '<div class="col-sm-4"><div class="form-group"><label>Display Name</label>'
                    . '<input type="text" name="fb_ios_name" class="form-control" value="' . htmlspecialchars($whmcsCompanyName) . '" placeholder="My App"></div></div>'
                    . '<div class="col-sm-3"><div class="form-group"><label>App Store ID</label>'
                    . '<input type="text" name="fb_ios_appstore" class="form-control" value="1643695817" placeholder="123456789"></div></div>'
                    . '</div>';
                echo '<button type="submit" name="firebase_create_ios" value="1" class="btn btn-success">Create iOS App</button>'
                    . ' <span class="help-block" style="display:inline;margin-left:10px;font-size:12px">Calls Firebase API &mdash; may take 30&ndash;60 s.</span>';
                echo '</form>';
            }
        } else {
            echo '<p class="text-muted">Complete Step 2 first.</p>';
        }
        echo '</div></div>';

        if (!$usingDummy):
        // ── Step 4: APNs key for iOS push ─────────────────────────────────────
        $s4locked = !$s3done;
        $certDir  = __DIR__ . '/../certificate/';
        $p8Files  = is_dir($certDir)
            ? array_values(array_filter(scandir($certDir), fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'p8'))
            : [];
        $s4badge  = $s4locked ? 'locked' : '';

        echo '<div class="panel panel-default fb-step' . ($s4locked ? ' locked' : '') . '">';
        echo '<div class="panel-heading"><span class="fb-step-num ' . $s4badge . '">4</span>'
            . '<strong>Upload APNs Key to Firebase Console (iOS Push)</strong></div><div class="panel-body">';

        if ($s3done) {
            // Show .p8 files already in the certificate folder
            if (!empty($p8Files)) {
                echo '<div class="alert alert-info" style="margin-bottom:16px">'
                    . '<strong>APNs key found in <code>certificate/</code> folder:</strong><br>'
                    . implode('<br>', array_map(fn($f) => '<code>' . htmlspecialchars($f) . '</code>', $p8Files))
                    . '<br><small class="text-muted">Use this file when uploading to Firebase Console in Step 3 below.</small>'
                    . '</div>';
            } else {
                echo '<div class="alert alert-warning" style="margin-bottom:16px">'
                    . 'No <code>.p8</code> file found in <code>certificate/</code> folder. '
                    . 'Generate one in Apple Developer Portal (Step 1 below) and place it there.'
                    . '</div>';
            }

            echo '<h4 style="margin-top:0">Upload APNs Key to Firebase Console</h4>';
            echo '<ol style="line-height:2;margin-bottom:0">'
                . '<li>Open <a href="https://console.firebase.google.com/project/' . htmlspecialchars($projectId ?: '_') . '/settings/cloudmessaging" target="_blank" rel="noopener">'
                . '<strong>Firebase Console &rarr; Project Settings &rarr; Cloud Messaging</strong></a>'
                . ($projectId ? ' <span class="label label-default">' . htmlspecialchars($projectId) . '</span>' : '') . '</li>'
                . '<li>Scroll down to <strong>Apple app configuration</strong></li>'
                . '<li>Find your iOS app row — bundle ID: <code>' . htmlspecialchars($defaultBundleId) . '</code></li>'
                . '<li>Under <strong>APNs Authentication Key</strong> click <strong>Upload</strong></li>'
                . '<li>Select your <code>.p8</code> file, enter the <strong>Key ID</strong> and <strong>Team ID</strong>, then click <strong>Upload</strong></li>'
                . '</ol>';

            echo '<div class="alert alert-warning" style="margin-top:14px;margin-bottom:0">'
                . '<strong>Note:</strong> One APNs Auth Key covers both production and sandbox (development) environments. '
                . 'Firebase has no API for this step — it must be done in Firebase Console.</div>';
        } else {
            echo '<p class="text-muted">Complete Step 3 first.</p>';
        }
        echo '</div></div>';

        // ── Step 5: Download configs ──────────────────────────────────────────
        $s5locked = !$s3done;
        $s5badge  = $s5locked ? 'locked' : '';

        echo '<div class="panel panel-default fb-step' . ($s5locked ? ' locked' : '') . '">';
        echo '<div class="panel-heading"><span class="fb-step-num ' . $s5badge . '">5</span>'
            . '<strong>Download Config Files</strong></div><div class="panel-body">';

        if ($s3done) {
            echo '<p class="text-muted" style="margin-bottom:14px">Android and iOS config files are <strong>also saved automatically</strong> '
                . 'to the Notifications tab so the mobile app can fetch them via the API endpoint.</p>';

            echo '<div class="row">';
            if ($androidId !== '') {
                echo '<div class="col-sm-4"><div class="panel panel-default" style="text-align:center;padding:20px 12px">'
                    . '<div style="font-size:30px;margin-bottom:6px">&#x1F4F1;</div>'
                    . '<strong>Android</strong><br><small class="text-muted">google-services.json</small><br><br>'
                    . '<a href="' . $ml . '&action=firebase_manager&firebase_download=android" class="btn btn-primary btn-sm">Download</a>'
                    . '</div></div>';
            }
            if ($iosId !== '') {
                echo '<div class="col-sm-4"><div class="panel panel-default" style="text-align:center;padding:20px 12px">'
                    . '<div style="font-size:30px;margin-bottom:6px">&#xF8FF;</div>'
                    . '<strong>iOS</strong><br><small class="text-muted">GoogleService-Info.plist</small><br><br>'
                    . '<a href="' . $ml . '&action=firebase_manager&firebase_download=ios" class="btn btn-primary btn-sm">Download</a>'
                    . '</div></div>';
            }
            echo '<div class="col-sm-4"><div class="panel panel-default" style="text-align:center;padding:20px 12px">'
                . '<div style="font-size:30px;margin-bottom:6px">&#x1F511;</div>'
                . '<strong>Server Key</strong><br><small class="text-muted">firebase-adminsdk.json</small><br><br>'
                . '<a href="' . $ml . '&action=firebase_manager&firebase_download=server" class="btn btn-warning btn-sm" '
                . 'onclick="return confirm(\'Each download creates a new IAM key in your project. Continue?\')">Download</a>'
                . '</div></div>';
            echo '</div>';

            echo '<div class="alert alert-info" style="margin-bottom:0">'
                . '<strong>Note:</strong> Each Server Key download creates a new IAM key in Google Cloud. '
                . 'Manage or revoke old keys in '
                . '<a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Cloud Console &rarr; Service Accounts</a>.'
                . '</div>';
        } else {
            echo '<p class="text-muted">Complete Step 3 first.</p>';
        }
        echo '</div></div>';
        endif; // !$usingDummy
    }
}
