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
        
        // Activation hook
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
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
            'wp-discord-announcements',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('wp_discord_announcements', 'wp_discord_announcements_options');
        
        add_settings_section(
            'wp_discord_announcements_section',
            'Discord Webhook Settings',
            null,
            'wp-discord-announcements'
        );

        add_settings_field(
            'webhook_url',
            'Discord Webhook URL',
            array($this, 'webhook_url_callback'),
            'wp-discord-announcements',
            'wp_discord_announcements_section'
        );
    }

    public function webhook_url_callback() {
        $webhook_url = isset($this->options['webhook_url']) ? $this->options['webhook_url'] : '';
        echo '<input type="text" name="wp_discord_announcements_options[webhook_url]" value="' . esc_attr($webhook_url) . '" size="60" />';
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h2>Discord Announcements Settings</h2>
            <form action="options.php" method="post">
                <?php
                settings_fields('wp_discord_announcements');
                do_settings_sections('wp-discord-announcements');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function handle_post_status_change($new_status, $old_status, $post) {
        // Only handle posts that are being published
        if ($new_status === 'publish' && $old_status !== 'publish') {
            $this->send_to_discord($post);
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

    private function send_to_discord($post) {
        if (empty($this->webhook_url)) {
            return;
        }

        // Prepare the message
        $message = array(
            'content' => '',
            'embeds' => array(
                array(
                    'title' => $post->post_title,
                    'description' => wp_trim_words($post->post_content, 100),
                    'url' => get_permalink($post->ID),
                    'color' => 7506394, // WordPress blue
                    'timestamp' => (new DateTime($post->post_date_gmt))->format('c')
                )
            )
        );

        // Send to Discord
        $response = wp_remote_post($this->webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($message),
        ));

        if (!is_wp_error($response)) {
            $discord_message_id = $this->extract_message_id($response);
            if ($discord_message_id) {
                global $wpdb;
                // Store the mapping
                $wpdb->replace(
                    $this->table_name,
                    array(
                        'post_id' => $post->ID,
                        'discord_message_id' => $discord_message_id
                    ),
                    array('%d', '%s')
                );
            }
        }
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
