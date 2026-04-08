# CPBS End Booking Early

A lightweight WordPress admin plugin extension for Car Park Booking System (CPBS).

## Parent Plugin (Extended Functionality)

This plugin extends the functionality of the existing plugin:

- Name: **Car Park Booking System for WordPress**
- URL: https://codecanyon.net/item/car-park-booking-system-for-wordpress/27465708

## What This Plugin Does

This plugin adds an **End Booking** action in the CPBS bookings list inside wp-admin.

When an admin clicks **End Booking** for an active booking, the plugin:

- sets booking exit date/time to the current site time,
- updates normalized exit datetime metadata,
- optionally marks booking status as completed (default status ID: `4`),
- syncs booking status with WooCommerce (when available),
- reloads the bookings list after success.

## Where It Appears

- Admin screen: `wp-admin/edit.php?post_type=cpbs_booking`
- Column: **Actions**
- Button: **End Booking**

## Visibility Rules for End Booking Button

The button is shown only when:

- current user has `manage_options` capability,
- booking is in allowed active statuses (IDs: `1`, `2`, `5`),
- current site time is between booking entry and exit times.

## Security Controls

- Admin-only AJAX action
- Capability check before action
- Nonce verification in AJAX request

## Files

- `cpbs-end-booking-early.php` - main plugin logic and AJAX handler
- `cpbs-end-booking-early-admin.js` - admin click handler and AJAX request

## Notes

- Ending a booking does **not** delete it.
- A booking may disappear from filtered list views (for example "New & accepted") if status changes after ending.
