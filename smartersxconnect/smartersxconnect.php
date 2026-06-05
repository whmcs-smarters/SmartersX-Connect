<?php
/**
 * WHMCS Addon: Device Manager (scaffold)
 *
 * Minimal addon entrypoint with activation/deactivation which creates a devices table.
 * This file should be extended to add admin configuration and secure behaviors.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function smartersxconnect_config()
{
    return [
        'name' => 'SmartersX Connect',
        'description' => ' <a href="https://www.smartersx.com/">SmartersX</a>  Connect seamlessly links your WHMCS platform with the  <a href="https://www.smartersx.com/">SmartersX</a>  application, enabling business owners and administrators to monitor key metrics, track growth, and stay informed with real-time business insights directly from their mobile devices.',
        'version' => '1.0.0',
        'author' => '<a href="https://www.whmcssmarters.com/"><img src="../modules/addons/smartersxconnect/logo1.png" style="width: 200px;height: 40px;" alt="WHMCS SMARTERS"></a>',
        'fields' => [
            'delete_data_on_uninstall' => [
                'FriendlyName' => 'Delete Data on Uninstall',
                'Type' => 'yesno',
                'Description' => 'If enabled, all addon data will be deleted when the addon is uninstalled.',
            ],
        ],
    ];
}

function smartersxconnect_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_smartersxconnect_devices')) {
            Capsule::schema()->create('mod_smartersxconnect_devices', function ($table) {
                $table->increments('id');
                $table->integer('userid')->unsigned();
                $table->string('device_id', 128);
                $table->string('label')->nullable();
                $table->text('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('last_seen')->nullable();
            });
        }
        // tokens table for API token based auth
        if (!Capsule::schema()->hasTable('mod_smartersxconnect_tokens')) {
            Capsule::schema()->create('mod_smartersxconnect_tokens', function ($table) {
                $table->increments('id');
                $table->integer('userid')->unsigned();
                $table->string('token_hash', 128);
                $table->string('label')->nullable();
                $table->boolean('revoked')->default(false);
                $table->timestamp('created_at')->useCurrent();
            });
        }
        // pairing table for temporary pairing codes
        if (!Capsule::schema()->hasTable('mod_smartersxconnect_pairs')) {
            Capsule::schema()->create('mod_smartersxconnect_pairs', function ($table) {
                $table->increments('id');
                $table->integer('userid')->unsigned();
                $table->string('pairing_code', 64)->unique();
                $table->string('device_id', 128)->nullable();
                $table->string('state', 32)->default('pending'); // pending, requested, authorized
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('authorized_at')->nullable();
            });
        }
        smartersxconnect_ensure_payment_notifications_table();
        smartersxconnect_ensure_notification_tables();
    } catch (\Exception $e) {
        // Table may already exist; ignore or log
    }

    return [
        'status' => 'success',
        'description' => 'Device Manager activated',
    ];
}

function smartersxconnect_deactivate()
{
    try {
        //get from db 
        $deleteData = Capsule::table('tbladdonmodules')
            ->where('module', 'smartersxconnect')
            ->where('setting', 'delete_data_on_uninstall')
            ->value('value');
        if ($deleteData) {
            Capsule::schema()->dropIfExists('mod_smartersxconnect_devices');
            Capsule::schema()->dropIfExists('mod_smartersxconnect_tokens');
            Capsule::schema()->dropIfExists('mod_smartersxconnect_pairs');
            Capsule::schema()->dropIfExists('mod_smartersxconnect_payment_notifications');
            Capsule::schema()->dropIfExists('mod_smartersxconnect_notification_devices');
            Capsule::schema()->dropIfExists('mod_smartersxconnect_notification_credentials');
            Capsule::schema()->dropIfExists('mod_smartersxconnect_notification_logs');
        }
    } catch (\Exception $e) {
        // ignore
    }

    return [
        'status' => 'success',
        'description' => 'Device Manager deactivated',
    ];
}

function smartersxconnect_upgrade($vars)
{
    try {
        smartersxconnect_ensure_payment_notifications_table();
        smartersxconnect_ensure_notification_tables();
    } catch (\Exception $e) {
        // ignore
    }
}

function smartersxconnect_ensure_notification_tables()
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

function smartersxconnect_ensure_payment_notifications_table()
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

    smartersxconnect_seed_payment_notifications();
}

function smartersxconnect_seed_payment_notifications()
{
    $defaults = [
        [
            'event_key' => 'payment_received',
            'label' => 'Payment Received',
            'title_template' => 'New Payment Received: {amount} for Invoice #{invoice_id}',
            'body_template' => '{client_name} paid {amount} via {payment_method}. Transaction #{transaction_id}.',
        ]
    ];

    foreach ($defaults as $row) {
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

/**
 * Client area router: allow clients to call simple actions via the addon client area.
 * Example URL: /index.php?m=smartersxconnect&action=listDevices
 * NOTE: This echoes JSON directly and is intended as a lightweight API surface.
 */
function smartersxconnect_clientarea($vars)
{
    require_once __DIR__ . '/lib/SmartersxConnectController.php';
    SmartersxConnectController::clientarea($vars);
}

/**
 * Admin area output for the addon (shows QR pairing generator)
 */
function smartersxconnect_output($vars)
{
    require_once __DIR__ . '/lib/SmartersxConnectController.php';
    SmartersxConnectController::adminOutput($vars);
}
