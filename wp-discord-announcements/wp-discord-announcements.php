<?php
/**
 * Plugin Name: WP Discord Announcements
 * Description: Automatically syncs WordPress announcements to Discord
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

class WP_Discord_Announcements {
    private $options;
    private $webhook_url;
    private $db_version = '1.0';
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'discord_message_map';
        
        // Initialize options
        $this->options = get_option('wp_discord_announcements_options');
        $this->webhook_url = isset($this->options['webhook_url']) ? $this->options['webhook_url'] : '';

        // Hook into WordPress actions
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('transition_post_status', array($this, 'handle_post_status_change'), 10, 3);
        add_action('before_delete_post', array($this, 'handle_post_deletion'));
    }

    public function activate_plugin() {
        // Create database table for storing Discord message IDs
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            discord_message_id varchar(255) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        add_option('wp_discord_announcements_db_version', $this->db_version);
    }

    public function add_admin_menu() {
        add_options_page(
            'Discord Announcements Settings',
            'Discord Announcements',
            'manage_options',
            'wp_discord_announcements',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting(
            'wp_discord_announcements_options',
            'wp_discord_announcements_options'
        );

        add_settings_section(
            'wp_discord_announcements_main',
            'Discord Webhook Settings',
            null,
            'wp_discord_announcements'
        );

        add_settings_field(
            'webhook_url',
            'Webhook URL',
            array($this, 'webhook_url_callback'),
            'wp_discord_announcements',
            'wp_discord_announcements_main'
        );

        add_settings_field(
            'categories',
            'Categories to Sync',
            array($this, 'categories_callback'),
            'wp_discord_announcements',
            'wp_discord_announcements_main'
        );
    }

    public function webhook_url_callback() {
        $webhook_url = isset($this->options['webhook_url']) ? $this->options['webhook_url'] : '';
        echo '<input type="text" name="wp_discord_announcements_options[webhook_url]" value="' . esc_attr($webhook_url) . '" size="60" />';
    }

    public function categories_callback() {
        $categories = get_categories(array('hide_empty' => false));
        $selected_categories = isset($this->options['categories']) ? $this->options['categories'] : array('news');

        echo '<select name="wp_discord_announcements_options[categories][]" multiple="multiple" style="min-width: 200px; min-height: 100px;">';
        foreach ($categories as $category) {
            $selected = in_array($category->slug, $selected_categories) ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr($category->slug) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Hold Ctrl/Cmd to select multiple categories</p>';
    }

    public function render_settings_page() {
        if (isset($_POST['test_discord_webhook'])) {
            $test_result = $this->test_discord_webhook();
            if ($test_result === true) {
                echo '<div class="notice notice-success"><p>Test message sent successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Error sending test message: ' . esc_html($test_result) . '</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h2>Discord Announcements Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('wp_discord_announcements_options');
                do_settings_sections('wp_discord_announcements');
                submit_button();
                ?>
            </form>
            
            <hr />
            <h3>Test Webhook Connection</h3>
            <form method="post" action="">
                <p>Click the button below to send a test message to Discord:</p>
                <input type="submit" name="test_discord_webhook" class="button button-secondary" value="Send Test Message" />
            </form>
        </div>
        <?php
    }

    public function handle_post_status_change($new_status, $old_status, $post) {
        // Only handle posts that are being published
        if ($new_status === 'publish' && $old_status !== 'publish') {
            $selected_categories = isset($this->options['categories']) ? $this->options['categories'] : array('news');
            $post_categories = wp_get_post_categories($post->ID, array('fields' => 'slugs'));
            
            // Check if post is in any of the selected categories
            $matching_categories = array_intersect($selected_categories, $post_categories);
            if (!empty($matching_categories)) {
                $this->send_to_discord($post);
            }
        }
    }

    public function handle_post_deletion($post_id) {
        global $wpdb;
        
        // Get Discord message ID
        $discord_message_id = $wpdb->get_var($wpdb->prepare(
            "SELECT discord_message_id FROM $this->table_name WHERE post_id = %d",
            $post_id
        ));

        if ($discord_message_id) {
            // Delete from Discord
            $this->delete_discord_message($discord_message_id);
            
            // Remove from our mapping table
            $wpdb->delete($this->table_name, array('post_id' => $post_id));
        }
    }

    public function handle_post_publish($post_id) {
        error_log("Discord Announcements: Post published directly - ID: {$post_id}");
        $post = get_post($post_id);
        if ($post) {
            $this->handle_post_status_change('publish', 'draft', $post);
        }
    }

    private function send_to_discord($post) {
        if (empty($this->webhook_url)) {
            return 'Webhook URL is not configured';
        }

        // Prepare the message
        $message = array(
            'content' => '@everyone A new announcement was posted, read it here! ' . get_permalink($post->ID)
        );

        // Send to Discord
        $response = wp_remote_post($this->webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($message),
            'timeout' => 15,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            return 'WordPress error: ' . $response->get_error_message();
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 204) {
            return 'Discord API error: ' . wp_remote_retrieve_body($response);
        }

        $discord_message_id = $this->extract_message_id($response);
        if ($discord_message_id) {
            global $wpdb;
            // Store the mapping
            $result = $wpdb->replace(
                $this->table_name,
                array(
                    'post_id' => $post->ID,
                    'discord_message_id' => $discord_message_id
                ),
                array('%d', '%s')
            );
            if ($result === false) {
                return 'Database error when storing message mapping';
            }
        }

        return true;
    }

    private function delete_discord_message($message_id) {
        if (empty($this->webhook_url)) {
            return;
        }

        // Extract webhook ID and token from URL
        preg_match('/\/webhooks\/(\d+)\/(.+)/', $this->webhook_url, $matches);
        if (count($matches) === 3) {
            $webhook_id = $matches[1];
            $webhook_token = $matches[2];
            
            // Construct deletion URL
            $delete_url = "https://discord.com/api/v10/webhooks/{$webhook_id}/{$webhook_token}/messages/{$message_id}";
            
            wp_remote_request($delete_url, array(
                'method' => 'DELETE',
                'headers' => array('Content-Type' => 'application/json')
            ));
        }
    }

    public function test_discord_webhook() {
        if (empty($this->webhook_url)) {
            return 'Webhook URL is not configured';
        }

        $message = array(
            'content' => 'Test message from WordPress - ' . date('Y-m-d H:i:s')
        );

        $response = wp_remote_post($this->webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($message),
            'timeout' => 15,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            return 'WordPress error: ' . $response->get_error_message();
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 204) {
            return 'Discord API error: ' . wp_remote_retrieve_body($response);
        }

        return true;
    }

    private function extract_message_id($response) {
        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['x-message-id'])) {
            return $headers['x-message-id'];
        }
        return null;
    }
}

// Initialize the plugin
new WP_Discord_Announcements();
