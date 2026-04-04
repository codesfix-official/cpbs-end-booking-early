<?php
/*
Plugin Name: CPBS End Booking Early
Description: Adds an admin-only "End Booking" action for active Car Park Booking System bookings.
Version: 1.0.0
Author: GitHub Copilot
*/

if (!defined('ABSPATH')) {
    exit;
}

final class CPBSEndBookingEarly
{
    const AJAX_ACTION = 'cpbs_end_booking_early';
    const NONCE_ACTION = 'cpbs_end_booking_early';
    const CAPABILITY = 'manage_options';
    const DEFAULT_CPT = 'cpbs_booking';
    const DEFAULT_META_PREFIX = 'cpbs_';
    const COLUMN_KEY = 'cpbs_end_booking_action';

    public function __construct()
    {
        add_filter('manage_edit-' . $this->get_booking_post_type() . '_columns', array($this, 'register_action_column'), 20);
        add_action('manage_' . $this->get_booking_post_type() . '_posts_custom_column', array($this, 'render_action_column'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_end_booking'));
    }

    public function register_action_column($columns)
    {
        $updated = array();

        foreach ($columns as $key => $label) {
            $updated[$key] = $label;

            if ($key === 'status') {
                $updated[self::COLUMN_KEY] = esc_html__('Actions', 'car-park-booking-system');
            }
        }

        if (!isset($updated[self::COLUMN_KEY])) {
            $updated[self::COLUMN_KEY] = esc_html__('Actions', 'car-park-booking-system');
        }

        return $updated;
    }

    public function render_action_column($column, $post_id)
    {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        if (!$this->current_user_can_end_bookings()) {
            return;
        }

        if (!$this->is_active_booking($post_id)) {
            return;
        }

        echo '<button type="button" class="button cpbs-end-booking-button" data-booking-id="' . esc_attr($post_id) . '">' . esc_html__('End Booking', 'car-park-booking-system') . '</button>';
    }

    public function enqueue_assets($hook_suffix)
    {
        if ($hook_suffix !== 'edit.php') {
            return;
        }

        if (!$this->current_user_can_end_bookings()) {
            return;
        }

        $screen = get_current_screen();
        if (!is_object($screen) || $screen->id !== 'edit-' . $this->get_booking_post_type()) {
            return;
        }

        $handle = 'cpbs-end-booking-early-admin';

        wp_enqueue_script(
            $handle,
            plugin_dir_url(__FILE__) . 'cpbs-end-booking-early-admin.js',
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script(
            $handle,
            'cpbsEndBookingEarly',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
                'i18n' => array(
                    'confirm' => esc_html__('End this booking now? The exit time will be changed to the current site time.', 'car-park-booking-system'),
                    'processing' => esc_html__('Ending...', 'car-park-booking-system'),
                    'button' => esc_html__('End Booking', 'car-park-booking-system'),
                    'genericError' => esc_html__('The booking could not be ended.', 'car-park-booking-system'),
                ),
            )
        );
    }

    public function ajax_end_booking()
    {
        if (!$this->current_user_can_end_bookings()) {
            wp_send_json_error(array('message' => esc_html__('You are not allowed to end bookings.', 'car-park-booking-system')), 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $booking_id = isset($_POST['booking_id']) ? absint(wp_unslash($_POST['booking_id'])) : 0;
        if ($booking_id <= 0) {
            wp_send_json_error(array('message' => esc_html__('Invalid booking ID.', 'car-park-booking-system')), 400);
        }

        if (!$this->is_booking_post($booking_id)) {
            wp_send_json_error(array('message' => esc_html__('Booking not found.', 'car-park-booking-system')), 404);
        }

        if (!$this->is_active_booking($booking_id)) {
            wp_send_json_error(array('message' => esc_html__('Only active bookings can be ended early.', 'car-park-booking-system')), 409);
        }

        $booking_model = class_exists('CPBSBooking') ? new CPBSBooking() : null;
        $booking_old = ($booking_model instanceof CPBSBooking) ? $booking_model->getBooking($booking_id) : false;
        if ($booking_old === false) {
            wp_send_json_error(array('message' => esc_html__('Booking details could not be loaded.', 'car-park-booking-system')), 500);
        }

        $current_time = new DateTimeImmutable('now', wp_timezone());
        $exit_date = $current_time->format('d-m-Y');
        $exit_time = $current_time->format('H:i');
        $exit_datetime = $exit_date . ' ' . $exit_time;
        $exit_datetime_normalized = $current_time->format('Y-m-d H:i');

        $this->update_booking_meta($booking_id, 'exit_date', $exit_date);
        $this->update_booking_meta($booking_id, 'exit_time', $exit_time);
        $this->update_booking_meta($booking_id, 'exit_datetime', $exit_datetime);
        $this->update_booking_meta($booking_id, 'exit_datetime_2', $exit_datetime_normalized);

        $status_updated = false;
        $completed_status_id = (int) apply_filters('cpbs_end_booking_early_completed_status_id', 4, $booking_id, $booking_old);
        $sync_mode = $this->get_booking_status_sync_mode();
        $has_linked_order = !empty($booking_old['meta']['woocommerce_booking_id']);

        if ($completed_status_id > 0 && !($sync_mode === 2 && $has_linked_order) && (int) $booking_old['meta']['booking_status_id'] !== $completed_status_id) {
            $this->update_booking_meta($booking_id, 'booking_status_id', $completed_status_id);
            $status_updated = true;
        }

        clean_post_cache($booking_id);

        $booking_new = $booking_model->getBooking($booking_id);
        if ($booking_new === false) {
            wp_send_json_error(array('message' => esc_html__('The booking was updated, but the refreshed booking data could not be loaded.', 'car-park-booking-system')), 500);
        }

        if ($status_updated) {
            $this->sync_booking_status($booking_id);

            if (method_exists($booking_model, 'sendEmailBookingChangeStatus')) {
                $booking_model->sendEmailBookingChangeStatus($booking_old, $booking_new);
            }
        }

        wp_send_json_success(
            array(
                'bookingId' => $booking_id,
                'exitDate' => $exit_date,
                'exitTime' => $exit_time,
                'statusUpdated' => $status_updated,
                'message' => $status_updated
                    ? esc_html__('The booking was ended early and marked as completed.', 'car-park-booking-system')
                    : esc_html__('The booking was ended early.', 'car-park-booking-system'),
            )
        );
    }

    private function current_user_can_end_bookings()
    {
        return current_user_can(self::CAPABILITY);
    }

    private function is_booking_post($booking_id)
    {
        $post = get_post($booking_id);

        return $post instanceof WP_Post && $post->post_type === $this->get_booking_post_type();
    }

    private function is_active_booking($booking_id)
    {
        if (!$this->is_booking_post($booking_id)) {
            return false;
        }

        $meta = $this->get_booking_meta($booking_id);
        $status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;
        if (!in_array($status_id, array(1, 2, 5), true)) {
            return false;
        }

        $entry = $this->build_site_datetime(isset($meta['entry_datetime_2']) ? $meta['entry_datetime_2'] : '');
        $exit = $this->build_site_datetime(isset($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : '');
        if (!$entry || !$exit) {
            return false;
        }

        $now = new DateTimeImmutable('now', wp_timezone());

        return $now >= $entry && $now < $exit;
    }

    private function build_site_datetime($normalized_datetime)
    {
        if (!is_string($normalized_datetime) || $normalized_datetime === '' || $normalized_datetime === '0000-00-00 00:00') {
            return false;
        }

        $datetime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $normalized_datetime, wp_timezone());

        return $datetime ?: false;
    }

    private function get_booking_post_type()
    {
        if (defined('PLUGIN_CPBS_CONTEXT')) {
            return PLUGIN_CPBS_CONTEXT . '_booking';
        }

        return self::DEFAULT_CPT;
    }

    private function get_meta_prefix()
    {
        if (defined('PLUGIN_CPBS_CONTEXT')) {
            return PLUGIN_CPBS_CONTEXT . '_';
        }

        return self::DEFAULT_META_PREFIX;
    }

    private function get_booking_meta($booking_id)
    {
        if (class_exists('CPBSPostMeta')) {
            return CPBSPostMeta::getPostMeta($booking_id);
        }

        $prepared = array();
        $raw_meta = get_post_meta($booking_id);

        foreach ((array) $raw_meta as $key => $values) {
            if (strpos($key, $this->get_meta_prefix()) !== 0) {
                continue;
            }

            $prepared[substr($key, strlen($this->get_meta_prefix()))] = maybe_unserialize(isset($values[0]) ? $values[0] : '');
        }

        return $prepared;
    }

    private function update_booking_meta($booking_id, $key, $value)
    {
        if (class_exists('CPBSPostMeta')) {
            CPBSPostMeta::updatePostMeta($booking_id, $key, $value);
            return;
        }

        update_post_meta($booking_id, $this->get_meta_prefix() . $key, $value);
    }

    private function get_booking_status_sync_mode()
    {
        if (class_exists('CPBSOption')) {
            return (int) CPBSOption::getOption('booking_status_synchronization');
        }

        return 1;
    }

    private function sync_booking_status($booking_id)
    {
        if (!class_exists('CPBSWooCommerce')) {
            return;
        }

        $email_sent = false;
        $woo_commerce = new CPBSWooCommerce();
        $woo_commerce->changeStatus(-1, $booking_id, $email_sent);
    }
}

new CPBSEndBookingEarly();
