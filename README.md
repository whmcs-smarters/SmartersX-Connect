# WHMCS Addon: SmartersX Connect

This is a scaffolding for a WHMCS addon module that manages connected devices for clients.

Features (scaffolded):
- List connected devices
- Connect a new device (QR / manual)
- Delete connected devices
- Notification test button (trigger a push/test notification)
- API for transaction totals: today, monthly, yearly, all-time
- Listing API with filters (today, month, year, all) and pagination

This module is a starting point — you must secure endpoints, add authentication checks, and integrate with your push/notification provider.

Installation notes:
- Copy this folder to `modules/addons/` inside your WHMCS installation.
- Run the module activation from WHMCS admin (it will create a devices table).

Files:
- `module.php` — addon module entry (config, activate/deactivate hooks)
- `hooks.php` — WHMCS hooks registration (placeholder)
- `lib/DeviceManager.php` — simple DB wrapper for devices
