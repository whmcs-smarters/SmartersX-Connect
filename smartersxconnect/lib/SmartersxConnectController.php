<?php

use WHMCS\Database\Capsule;

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
                $deviceIdDb = Capsule::table('mod_smartersxconnect_devices')->insertGetId([
                    'userid' => 0,
                    'device_id' => $deviceId,
                    'label' => $label,
                    'meta' => json_encode(['paired_via' => 'qr']),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
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

        if ($userId === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $action = $_REQUEST['action'] ?? '';

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
                echo json_encode(['status' => 'queued', 'message' => 'Test notification queued (stub)']);
                break;

                                if ((int) $rec->userid !== (int) $userId) {
                                    http_response_code(403);
                                    echo json_encode(['error' => 'pairing does not belong to this account']);
                                    break;
                                }
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
            'connect' => 'Connect',
            'connections' => 'Registered Devices',
            'notification' => 'Notifications',
            'notificationlogs' => 'Notification Logs',
            'info' => 'About Us',
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

        // Global banner — shown on every tab when notification service is not configured.
        $credSummaryGlobal = self::getServiceAccountSummary();
        if (empty($credSummaryGlobal['configured'])) {
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

        $isConfigured = !empty($credentialSummary['configured']);

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
        self::renderTestNotificationStatus();

        $globalEnabled = Capsule::table('tblconfiguration')
            ->where('setting', 'smartersx_notifications_enabled')
            ->value('value');
        $globalEnabled = ($globalEnabled === null) ? '1' : $globalEnabled;

        $step1Done = $isConfigured;

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

        // Upload section
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><strong>Payment Notification Service</strong></div>';
        echo '<div class="panel-body">';

        if ($isConfigured) {
            // ── Configured state: large status + hidden reconfigure form ──
            echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:8px 0">';
            echo '<div>';
            echo '<h4 style="margin:0 0 6px;color:#3c763d"><span class="glyphicon glyphicon-ok-circle"></span> &nbsp;Payment Notifications Active</h4>';
            if (!empty($credentialSummary['project_id'])) {
                echo '<p class="text-muted" style="margin:0">Firebase Project: <strong>' . htmlspecialchars($credentialSummary['project_id']) . '</strong>';
                if (!empty($credentialSummary['client_email'])) {
                    echo '<br>Service Account: <strong>' . htmlspecialchars($credentialSummary['client_email']) . '</strong>';
                }
                echo '</p>';
            }
            echo '</div>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
            echo '<button type="button" class="btn btn-default btn-sm" onclick="document.getElementById(\'sx-reconfig-form\').style.display=\'block\';this.parentElement.style.display=\'none\'">'
                . '<span class="glyphicon glyphicon-refresh"></span> Reconfigure</button>';
            echo '<button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById(\'sx-delete-confirm\').style.display=\'block\';this.parentElement.style.display=\'none\'">'
                . '<span class="glyphicon glyphicon-trash"></span> Delete</button>';
            echo '</div>';
            echo '</div>';

            // Delete confirmation alert
            echo '<div id="sx-delete-confirm" style="display:none;margin-top:16px">';
            echo '<div class="alert alert-danger">';
            echo '<p><strong>Are you sure you want to delete the payment notification credentials?</strong><br>'
                . 'This will remove the service account configuration. Push notifications will stop working immediately.</p>';
            echo '<form method="post" action="' . htmlspecialchars($modulelink . '&action=notification') . '" style="display:inline">';
            echo '<input type="hidden" name="delete_fcm_credentials" value="1">';
            echo '<button type="submit" class="btn btn-danger btn-sm"><span class="glyphicon glyphicon-trash"></span> Yes, Delete Credentials</button>';
            echo '</form>';
            echo ' &nbsp;<button type="button" class="btn btn-default btn-sm" '
                . 'onclick="document.getElementById(\'sx-delete-confirm\').style.display=\'none\';document.querySelector(\'.sx-action-btns\') && (document.querySelector(\'.sx-action-btns\').style.display=\'flex\')">'
                . 'Cancel</button>';
            echo '</div>';
            echo '</div>';

            echo '<div id="sx-reconfig-form" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid #eee">';
            echo '<form method="post" action="' . htmlspecialchars($modulelink . '&action=notification') . '" enctype="multipart/form-data">';
            echo '<input type="hidden" name="save_fcm_credentials" value="1">';
            echo '<div class="input-group" style="max-width:480px">';
            echo '<input type="file" name="fcm_service_account_file" class="form-control" accept=".json,application/json" required>';
            echo '<span class="input-group-btn"><button type="submit" class="btn btn-primary">Upload JSON</button></span>';
            echo '</div>';
            echo '<p class="help-block">Select the <code>.json</code> service account file from Firebase Console &rarr; Service Accounts.</p>';
            echo '</form>';
            echo '</div>';
        } else {
            // ── Not configured state: warning + upload form always visible ──
            echo '<div class="alert alert-warning" style="margin-bottom:16px">';
            echo '<h4 style="margin:0 0 4px"><span class="glyphicon glyphicon-warning-sign"></span> &nbsp;Not Configured</h4>';
            echo '<p style="margin:0">Upload the service account JSON file to enable payment notifications on the SmartersX app.</p>';
            echo '</div>';
            echo '<form method="post" action="' . htmlspecialchars($modulelink . '&action=notification') . '" enctype="multipart/form-data">';
            echo '<input type="hidden" name="save_fcm_credentials" value="1">';
            echo '<div class="input-group" style="max-width:480px">';
            echo '<input type="file" name="fcm_service_account_file" class="form-control" accept=".json,application/json" required>';
            echo '<span class="input-group-btn"><button type="submit" class="btn btn-primary">Upload JSON</button></span>';
            echo '</div>';
            echo '<p class="help-block">Select the <code>.json</code> service account file from Firebase Console &rarr; Service Accounts.</p>';
            echo '</form>';
        }

        echo '</div></div>';

        // Notifications section
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading clearfix">';
        echo '<strong class="pull-left" style="line-height:26px">Payment Notifications Template</strong>';
        echo '<form method="post" action="' . htmlspecialchars($modulelink . '&action=notification') . '" class="pull-right" style="margin:0">';
        echo '<input type="hidden" name="save_global_notifications" value="1">';
        if ($globalEnabled === '1') {
            // No notifications_enabled field — handler receives nothing → sets to '0' (disabled).
            echo '<button type="submit" class="btn btn-xs btn-success">&#10003; Enabled &mdash; click to disable</button>';
        } else {
            echo '<button type="submit" name="notifications_enabled" value="1" class="btn btn-xs btn-warning"><strong>&#10007; Disabled</strong> &mdash; click to enable</button>';
        }
        echo '</form>';
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
                . (!$isConfigured ? 'disabled title="Upload JSON file first"' : '')
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
        $steps = [
            [
                $step1Done,
                'Connect your notification service',
                '<a href="https://console.firebase.google.com/" target="_blank" rel="noopener">console.firebase.google.com</a>',
                [
                    'Sign in with your Google account <a href="https://console.firebase.google.com/" target="_blank" rel="noopener">console.firebase.google.com</a>.',
                    'Click <strong>Add project</strong> and enter a project name (e.g. <em>SmartersX Notifications</em>).',
                    'Disable or enable Google Analytics as preferred &mdash; it is not required.',
                    'Click <strong>Create project</strong> and wait for it to be ready.',
                    'Click <strong>Continue</strong> to open the project dashboard.',
                ],
            ],
            [
                $step1Done,
                'Get your connection file',
                'Firebase Console &rarr; Project Settings &rarr; Service Accounts',
                [
                    'In your Firebase project, click the <strong>gear icon</strong> (&#9881;) next to <em>Project Overview</em> and choose <strong>Project settings</strong>.',
                    'Open the <strong>Service accounts</strong> tab.',
                    'Make sure <strong>Firebase Admin SDK</strong> is selected.',
                    'Click <strong>Generate new private key</strong>.',
                    'Confirm by clicking <strong>Generate key</strong> &mdash; a <code>.json</code> file will download to your computer.',
                ],
            ],
            [
                $step1Done,
                'Upload connection File',
                'Use the upload form on the left',
                [
                    'Go to the upload form on the left side of this page.',
                    'Click <strong>Choose File</strong> and select the <code>.json</code> file you just downloaded.',
                    'Click <strong>Upload JSON</strong> to save the credentials.',
                    'A green <em>Configured</em> status will confirm success.',
                ],
            ],
            [
                $step4Done,
                'Configure Notifications',
                'Payment Notifications panel below',
                [
                    'Scroll down to the <strong>Payment Notifications</strong> panel.',
                    'Make sure the global toggle is set to <strong>Enabled</strong>.',
                    'Customise the <strong>Title</strong> and <strong>Message</strong> for each event.',
                    'Use variables like <code>{amount}</code> and <code>{client_name}</code> in your templates.',
                    'Click <strong>Save Notification Rules</strong> when done.',
                ],
            ],
            [
                $step5Done,
                'Send a Test Notification',
                'Verify everything is working',
                [
                    'Make sure at least one device is connected and has the SmartersX app installed.',
                    'Click the <strong>Send Test</strong> button next to any notification rule.',
                    'You should receive a push notification on the registered device within a few seconds.',
                    'If no notification arrives, check the Firebase project credentials and ensure the device has notification permissions enabled.',
                ],
            ],
        ];

        echo '<ol class="list-unstyled">';
        foreach ($steps as $i => $step) {
            list($done, $title, $subtitle, $subSteps) = $step;
            $badge = $done
                ? '<span class="label label-success" style="font-size:12px;padding:4px 7px">' . ($i + 1) . '</span>'
                : '<span class="label label-primary" style="font-size:12px;padding:4px 7px">' . ($i + 1) . '</span>';
            $collapseId = 'sx-step-' . ($i + 1);
            echo '<li style="margin-bottom:14px;border:1px solid #e3e6eb;border-radius:6px;overflow:hidden">';
            // Step header — clickable to expand
            echo '<div style="display:flex;gap:10px;align-items:center;padding:10px 12px;cursor:pointer;background:' . ($done ? '#f6fff7' : '#f9f9f9') . '" '
                . 'onclick="var b=document.getElementById(\'' . $collapseId . '\');b.style.display=b.style.display===\'none\'?\'block\':\'none\'">';
            echo '<div style="flex-shrink:0">' . $badge . '</div>';
            echo '<div style="flex:1">';
            echo '<strong>' . $title . '</strong>';
            echo '<br><small class="text-muted">' . $subtitle . '</small>';
            echo '</div>';
            echo '<span class="glyphicon glyphicon-chevron-down text-muted" style="font-size:11px"></span>';
            echo '</div>';
            // Sub-steps — hidden by default, shown when header clicked
            echo '<div id="' . $collapseId . '" style="display:none;padding:10px 12px;border-top:1px solid #e3e6eb;background:#fff">';
            echo '<ol style="margin:0;padding-left:18px">';
            foreach ($subSteps as $sub) {
                echo '<li style="font-size:12px;color:#555;margin-bottom:6px;line-height:1.5">' . $sub . '</li>';
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

            if (is_string($result) && stripos($result, 'error') !== false) {
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
            if (Capsule::schema()->hasTable('mod_smartersxconnect_notification_credentials')) {
                $row = Capsule::table('mod_smartersxconnect_notification_credentials')->orderBy('id', 'asc')->first();
                if ($row && !empty($row->service_account_json)) {
                    $decoded = json_decode($row->service_account_json, true);
                    if (is_array($decoded)) {
                        $summary['configured'] = true;
                        $summary['project_id'] = (string) ($decoded['project_id'] ?? '');
                        $summary['client_email'] = (string) ($decoded['client_email'] ?? '');
                        return $summary;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall back to the legacy constant check below.
        }

        if (defined('FCMSmartersxconnect_CRED')) {
            $decoded = json_decode(FCMSmartersxconnect_CRED, true);
            if (is_array($decoded)) {
                $summary['configured'] = true;
                $summary['project_id'] = (string) ($decoded['project_id'] ?? '');
                $summary['client_email'] = (string) ($decoded['client_email'] ?? '');
            }
        }

        return $summary;
    }

    private static function getConnectedNotificationDevices()
    {
        if (!Capsule::schema()->hasTable('mod_smartersxconnect_notification_devices')) {
            return [];
        }

        return Capsule::table('mod_smartersxconnect_notification_devices as nd')
            ->join('mod_smartersxconnect_devices as d', function ($join) {
                $join->on('nd.device_table_id', '=', 'd.id')
                    ->orOn('nd.mobile_device_id', '=', 'd.device_id');
            })
            ->where('nd.status', 1)
            ->where('nd.devicetoken', '!=', '')
            ->select('nd.*')
            ->distinct()
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
        echo '<p>Notification log viewer will appear here.</p>';
        echo '</div>';
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
}
