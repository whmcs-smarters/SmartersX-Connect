<?php
namespace WHMCS\Addon\DeviceManager\Lib;

use WHMCS\Database\Capsule;

class DeviceManager
{
    protected $table = 'mod_smartersxconnect_devices';

    public function addDevice($userId, $deviceId, $label = null, $meta = null)
    {
        return Capsule::table($this->table)->insertGetId([
            'userid' => $userId,
            'device_id' => $deviceId,
            'label' => $label,
            'meta' => $meta ? json_encode($meta) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function listDevices($userId, $limit = 20, $offset = 0)
    {
        return Capsule::table($this->table)
            ->where('userid', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->toArray();
    }

    public function deleteDevice($id)
    {
        return Capsule::table($this->table)->where('id', $id)->delete();
    }

    public function findByDeviceId($deviceId)
    {
        return Capsule::table($this->table)->where('device_id', $deviceId)->first();
    }
}
