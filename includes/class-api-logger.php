<?php
/**
 * AKS API Logger
 * Centralized logging for all API calls (outgoing and incoming webhooks)
 * 
 * Features:
 * - Toggle logging on/off from dashboard
 * - Log all outgoing API calls with request/response
 * - Log all incoming webhooks
 * - View logs in admin with filtering
 * - Auto-cleanup old logs
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_API_Logger {
    
    private static $instance = null;
    private $table_name;
    private $option_name = 'aks_api_logging_enabled';
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'aks_api_logs';
        
        // Create table on init if needed
        add_action('admin_init', array($this, 'maybe_create_table'));
        
        // Add admin menu (priority 20 to ensure parent menu exists first)
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        
        // AJAX handlers
        add_action('wp_ajax_aks_toggle_logging', array($this, 'ajax_toggle_logging'));
        add_action('wp_ajax_aks_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_aks_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_aks_get_log_detail', array($this, 'ajax_get_log_detail'));
        
        // Schedule cleanup
        if (!wp_next_scheduled('aks_cleanup_api_logs')) {
            wp_schedule_event(time(), 'daily', 'aks_cleanup_api_logs');
        }
        add_action('aks_cleanup_api_logs', array($this, 'cleanup_old_logs'));
    }
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Check if logging is enabled
     */
    public function is_enabled() {
        return get_option($this->option_name, false);
    }
    
    /**
     * Enable/disable logging
     */
    public function set_enabled($enabled) {
        update_option($this->option_name, (bool) $enabled);
    }
    
    /**
     * Create the logs table if it doesn't exist
     */
    public function maybe_create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            direction enum('outgoing','incoming') NOT NULL DEFAULT 'outgoing',
            service varchar(50) NOT NULL,
            endpoint varchar(500) NOT NULL,
            method varchar(10) NOT NULL DEFAULT 'GET',
            request_headers longtext,
            request_body longtext,
            response_code int(11),
            response_headers longtext,
            response_body longtext,
            user_id bigint(20) unsigned,
            user_email varchar(255),
            context varchar(255),
            duration_ms int(11),
            success tinyint(1) DEFAULT 1,
            error_message text,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY service (service),
            KEY direction (direction),
            KEY user_id (user_id),
            KEY success (success)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Log an API call
     * 
     * @param array $data Log data
     * @return int|false Insert ID or false on failure
     */
    public function log($data) {
        if (!$this->is_enabled()) {
            return false;
        }
        
        global $wpdb;
        
        $defaults = array(
            'created_at' => current_time('mysql'),
            'direction' => 'outgoing',
            'service' => 'unknown',
            'endpoint' => '',
            'method' => 'GET',
            'request_headers' => '',
            'request_body' => '',
            'response_code' => null,
            'response_headers' => '',
            'response_body' => '',
            'user_id' => null,
            'user_email' => '',
            'context' => '',
            'duration_ms' => null,
            'success' => 1,
            'error_message' => ''
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Serialize arrays/objects
        if (is_array($data['request_headers']) || is_object($data['request_headers'])) {
            $data['request_headers'] = json_encode($data['request_headers'], JSON_PRETTY_PRINT);
        }
        if (is_array($data['request_body']) || is_object($data['request_body'])) {
            $data['request_body'] = json_encode($data['request_body'], JSON_PRETTY_PRINT);
        }
        if (is_array($data['response_headers']) || is_object($data['response_headers'])) {
            $data['response_headers'] = json_encode($data['response_headers'], JSON_PRETTY_PRINT);
        }
        if (is_array($data['response_body']) || is_object($data['response_body'])) {
            $data['response_body'] = json_encode($data['response_body'], JSON_PRETTY_PRINT);
        }
        
        // Truncate very long responses to prevent DB issues
        $max_length = 65000;
        if (strlen($data['response_body']) > $max_length) {
            $data['response_body'] = substr($data['response_body'], 0, $max_length) . "\n\n[TRUNCATED - Response too long]";
        }
        if (strlen($data['request_body']) > $max_length) {
            $data['request_body'] = substr($data['request_body'], 0, $max_length) . "\n\n[TRUNCATED - Request too long]";
        }
        
        $result = $wpdb->insert($this->table_name, $data);
        
        if ($result === false) {
            error_log('AKS API Logger: Failed to insert log - ' . $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Helper method to log an outgoing API request
     */
    public function log_request($service, $endpoint, $method, $headers, $body, $context = '', $user_id = null, $user_email = '') {
        return array(
            'service' => $service,
            'endpoint' => $endpoint,
            'method' => $method,
            'request_headers' => $headers,
            'request_body' => $body,
            'context' => $context,
            'user_id' => $user_id,
            'user_email' => $user_email,
            'direction' => 'outgoing',
            '_start_time' => microtime(true)
        );
    }
    
    /**
     * Helper method to complete the log with response data
     */
    public function log_response($log_data, $response_code, $response_headers, $response_body, $success = true, $error_message = '') {
        $duration_ms = null;
        if (isset($log_data['_start_time'])) {
            $duration_ms = round((microtime(true) - $log_data['_start_time']) * 1000);
            unset($log_data['_start_time']);
        }
        
        $log_data['response_code'] = $response_code;
        $log_data['response_headers'] = $response_headers;
        $log_data['response_body'] = $response_body;
        $log_data['success'] = $success ? 1 : 0;
        $log_data['error_message'] = $error_message;
        $log_data['duration_ms'] = $duration_ms;
        
        return $this->log($log_data);
    }
    
    /**
     * Log an incoming webhook
     */
    public function log_webhook($service, $endpoint, $headers, $body, $response_code = 200, $response_body = '', $success = true, $error_message = '', $user_id = null, $user_email = '') {
        return $this->log(array(
            'direction' => 'incoming',
            'service' => $service,
            'endpoint' => $endpoint,
            'method' => 'POST',
            'request_headers' => $headers,
            'request_body' => $body,
            'response_code' => $response_code,
            'response_body' => $response_body,
            'success' => $success ? 1 : 0,
            'error_message' => $error_message,
            'user_id' => $user_id,
            'user_email' => $user_email,
            'context' => 'webhook'
        ));
    }
    
    /**
     * Get logs with filtering
     */
    public function get_logs($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'per_page' => 50,
            'page' => 1,
            'service' => '',
            'direction' => '',
            'success' => '',
            'search' => '',
            'date_from' => '',
            'date_to' => ''
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        $values = array();
        
        if (!empty($args['service'])) {
            $where[] = 'service = %s';
            $values[] = $args['service'];
        }
        
        if (!empty($args['direction'])) {
            $where[] = 'direction = %s';
            $values[] = $args['direction'];
        }
        
        if ($args['success'] !== '') {
            $where[] = 'success = %d';
            $values[] = (int) $args['success'];
        }
        
        if (!empty($args['search'])) {
            $where[] = '(endpoint LIKE %s OR request_body LIKE %s OR response_body LIKE %s OR user_email LIKE %s OR error_message LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }
        
        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }
        
        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}";
        if (!empty($values)) {
            $count_sql = $wpdb->prepare($count_sql, $values);
        }
        $total = $wpdb->get_var($count_sql);
        
        // Get paginated results
        $offset = ($args['page'] - 1) * $args['per_page'];
        $sql = "SELECT id, created_at, direction, service, endpoint, method, response_code, user_email, context, duration_ms, success, error_message 
                FROM {$this->table_name} 
                WHERE {$where_sql} 
                ORDER BY created_at DESC 
                LIMIT %d OFFSET %d";
        
        $values[] = $args['per_page'];
        $values[] = $offset;
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
        
        return array(
            'logs' => $results,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page'])
        );
    }
    
    /**
     * Get single log entry with full details
     */
    public function get_log($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ), ARRAY_A);
    }
    
    /**
     * Clear all logs
     */
    public function clear_logs() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    /**
     * Cleanup logs older than 30 days
     */
    public function cleanup_old_logs() {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
    }
    
    /**
     * Get available services for filtering
     */
    public function get_services() {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT service FROM {$this->table_name} ORDER BY service");
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'aks-integration',
            'API Logs',
            'API Logs',
            'manage_options',
            'aks-api-logs',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * AJAX: Toggle logging
     */
    public function ajax_toggle_logging() {
        check_ajax_referer('aks_api_logs_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        $this->set_enabled($enabled);
        
        wp_send_json_success(array('enabled' => $enabled));
    }
    
    /**
     * AJAX: Get logs
     */
    public function ajax_get_logs() {
        check_ajax_referer('aks_api_logs_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $args = array(
            'per_page' => isset($_POST['per_page']) ? intval($_POST['per_page']) : 50,
            'page' => isset($_POST['page']) ? intval($_POST['page']) : 1,
            'service' => isset($_POST['service']) ? sanitize_text_field($_POST['service']) : '',
            'direction' => isset($_POST['direction']) ? sanitize_text_field($_POST['direction']) : '',
            'success' => isset($_POST['success']) && $_POST['success'] !== '' ? sanitize_text_field($_POST['success']) : '',
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'date_from' => isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '',
            'date_to' => isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : ''
        );
        
        $result = $this->get_logs($args);
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get log detail
     */
    public function ajax_get_log_detail() {
        check_ajax_referer('aks_api_logs_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $log = $this->get_log($id);
        
        if (!$log) {
            wp_send_json_error('Log not found');
        }
        
        wp_send_json_success($log);
    }
    
    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('aks_api_logs_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $this->clear_logs();
        wp_send_json_success();
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        $is_enabled = $this->is_enabled();
        $services = $this->get_services();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('API Logs', 'aks-integration'); ?></h1>
            
            <!-- Logging Toggle -->
            <div class="aks-logging-toggle" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2 style="margin-top: 0;">Logging Settings</h2>
                <p>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="aks-logging-enabled" <?php checked($is_enabled); ?> style="width: 20px; height: 20px;" />
                        <span style="font-size: 16px; font-weight: 500;">Enable API Logging</span>
                    </label>
                </p>
                <p class="description">
                    When enabled, all outgoing API calls (SendPulse, Quo, DocuSeal) and incoming webhooks will be logged.<br>
                    Logs are automatically deleted after 30 days.
                </p>
                <p id="logging-status" style="margin-top: 10px; padding: 10px; border-radius: 4px; display: inline-block; <?php echo $is_enabled ? 'background: #d4edda; color: #155724;' : 'background: #f8d7da; color: #721c24;'; ?>">
                    <?php echo $is_enabled ? '✓ Logging is ENABLED' : '✗ Logging is DISABLED'; ?>
                </p>
            </div>
            
            <!-- Filters -->
            <div class="aks-log-filters" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2 style="margin-top: 0;">Filter Logs</h2>
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                    <div>
                        <label for="filter-service">Service</label><br>
                        <select id="filter-service" style="min-width: 150px;">
                            <option value="">All Services</option>
                            <?php foreach ($services as $service): ?>
                            <option value="<?php echo esc_attr($service); ?>"><?php echo esc_html($service); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="filter-direction">Direction</label><br>
                        <select id="filter-direction" style="min-width: 150px;">
                            <option value="">All</option>
                            <option value="outgoing">Outgoing (API Calls)</option>
                            <option value="incoming">Incoming (Webhooks)</option>
                        </select>
                    </div>
                    <div>
                        <label for="filter-success">Status</label><br>
                        <select id="filter-success" style="min-width: 150px;">
                            <option value="">All</option>
                            <option value="1">Success</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div>
                        <label for="filter-date-from">Date From</label><br>
                        <input type="date" id="filter-date-from" />
                    </div>
                    <div>
                        <label for="filter-date-to">Date To</label><br>
                        <input type="date" id="filter-date-to" />
                    </div>
                    <div>
                        <label for="filter-search">Search</label><br>
                        <input type="text" id="filter-search" placeholder="Email, endpoint, error..." style="min-width: 200px;" />
                    </div>
                    <div>
                        <button type="button" id="btn-filter" class="button button-primary">Filter</button>
                        <button type="button" id="btn-reset" class="button">Reset</button>
                    </div>
                    <div style="margin-left: auto;">
                        <button type="button" id="btn-refresh" class="button">🔄 Refresh</button>
                        <button type="button" id="btn-clear-logs" class="button" style="color: #dc3545;">🗑 Clear All Logs</button>
                    </div>
                </div>
            </div>
            
            <!-- Logs Table -->
            <div class="aks-logs-table" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <div id="logs-loading" style="text-align: center; padding: 40px; display: none;">
                    <span class="spinner is-active" style="float: none;"></span>
                    Loading logs...
                </div>
                
                <table class="wp-list-table widefat fixed striped" id="logs-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Time</th>
                            <th style="width: 80px;">Direction</th>
                            <th style="width: 100px;">Service</th>
                            <th>Endpoint</th>
                            <th style="width: 80px;">Method</th>
                            <th style="width: 80px;">Status</th>
                            <th style="width: 150px;">User</th>
                            <th style="width: 80px;">Duration</th>
                            <th style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="logs-tbody">
                        <tr><td colspan="9" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <div id="logs-pagination" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <div id="logs-info"></div>
                    <div id="logs-pages"></div>
                </div>
            </div>
            
            <!-- Log Detail Modal -->
            <div id="log-detail-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; overflow-y: auto;">
                <div style="background: #fff; margin: 50px auto; max-width: 900px; border-radius: 8px; max-height: calc(100vh - 100px); overflow-y: auto;">
                    <div style="padding: 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #fff;">
                        <h2 style="margin: 0;">Log Details</h2>
                        <button type="button" id="close-modal" class="button">✕ Close</button>
                    </div>
                    <div id="log-detail-content" style="padding: 20px;"></div>
                </div>
            </div>
        </div>
        
        <style>
            .aks-log-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .aks-log-badge.success { background: #d4edda; color: #155724; }
            .aks-log-badge.error { background: #f8d7da; color: #721c24; }
            .aks-log-badge.outgoing { background: #cce5ff; color: #004085; }
            .aks-log-badge.incoming { background: #fff3cd; color: #856404; }
            .aks-log-pre {
                background: #1e1e1e;
                color: #d4d4d4;
                padding: 15px;
                border-radius: 4px;
                overflow-x: auto;
                font-family: 'Consolas', 'Monaco', monospace;
                font-size: 12px;
                line-height: 1.5;
                white-space: pre-wrap;
                word-wrap: break-word;
                max-height: 300px;
                overflow-y: auto;
            }
            #logs-table td {
                vertical-align: middle;
            }
            .log-endpoint {
                max-width: 300px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        </style>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var currentPage = 1;
            var nonce = '<?php echo wp_create_nonce('aks_api_logs_nonce'); ?>';
            
            // Toggle logging
            $('#aks-logging-enabled').change(function() {
                var enabled = $(this).is(':checked');
                $.post(ajaxurl, {
                    action: 'aks_toggle_logging',
                    nonce: nonce,
                    enabled: enabled
                }, function(response) {
                    if (response.success) {
                        if (enabled) {
                            $('#logging-status').css({background: '#d4edda', color: '#155724'}).text('✓ Logging is ENABLED');
                        } else {
                            $('#logging-status').css({background: '#f8d7da', color: '#721c24'}).text('✗ Logging is DISABLED');
                        }
                    }
                });
            });
            
            // Load logs
            function loadLogs(page) {
                page = page || 1;
                currentPage = page;
                
                $('#logs-loading').show();
                $('#logs-tbody').html('<tr><td colspan="9" style="text-align: center;">Loading...</td></tr>');
                
                $.post(ajaxurl, {
                    action: 'aks_get_logs',
                    nonce: nonce,
                    page: page,
                    per_page: 50,
                    service: $('#filter-service').val(),
                    direction: $('#filter-direction').val(),
                    success: $('#filter-success').val(),
                    search: $('#filter-search').val(),
                    date_from: $('#filter-date-from').val(),
                    date_to: $('#filter-date-to').val()
                }, function(response) {
                    $('#logs-loading').hide();
                    
                    if (!response.success) {
                        $('#logs-tbody').html('<tr><td colspan="9" style="text-align: center; color: red;">Error loading logs</td></tr>');
                        return;
                    }
                    
                    var data = response.data;
                    var html = '';
                    
                    if (data.logs.length === 0) {
                        html = '<tr><td colspan="9" style="text-align: center;">No logs found</td></tr>';
                    } else {
                        $.each(data.logs, function(i, log) {
                            var statusClass = log.success == 1 ? 'success' : 'error';
                            var statusText = log.success == 1 ? log.response_code : 'Error';
                            var directionClass = log.direction;
                            var directionIcon = log.direction === 'outgoing' ? '↑' : '↓';
                            
                            html += '<tr>';
                            html += '<td>' + log.created_at + '</td>';
                            html += '<td><span class="aks-log-badge ' + directionClass + '">' + directionIcon + ' ' + log.direction + '</span></td>';
                            html += '<td>' + escapeHtml(log.service) + '</td>';
                            html += '<td class="log-endpoint" title="' + escapeHtml(log.endpoint) + '">' + escapeHtml(log.endpoint) + '</td>';
                            html += '<td>' + log.method + '</td>';
                            html += '<td><span class="aks-log-badge ' + statusClass + '">' + statusText + '</span></td>';
                            html += '<td>' + (log.user_email || '-') + '</td>';
                            html += '<td>' + (log.duration_ms ? log.duration_ms + 'ms' : '-') + '</td>';
                            html += '<td><button type="button" class="button button-small btn-view-log" data-id="' + log.id + '">View</button></td>';
                            html += '</tr>';
                        });
                    }
                    
                    $('#logs-tbody').html(html);
                    
                    // Update pagination
                    $('#logs-info').text('Showing ' + data.logs.length + ' of ' + data.total + ' logs');
                    
                    var pagesHtml = '';
                    if (data.pages > 1) {
                        for (var p = 1; p <= data.pages; p++) {
                            if (p === page) {
                                pagesHtml += '<span class="button button-disabled">' + p + '</span> ';
                            } else {
                                pagesHtml += '<button type="button" class="button btn-page" data-page="' + p + '">' + p + '</button> ';
                            }
                        }
                    }
                    $('#logs-pages').html(pagesHtml);
                });
            }
            
            // Helper function
            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(text));
                return div.innerHTML;
            }
            
            // View log detail
            $(document).on('click', '.btn-view-log', function() {
                var id = $(this).data('id');
                
                $.post(ajaxurl, {
                    action: 'aks_get_log_detail',
                    nonce: nonce,
                    id: id
                }, function(response) {
                    if (!response.success) {
                        alert('Error loading log details');
                        return;
                    }
                    
                    var log = response.data;
                    var statusClass = log.success == 1 ? 'success' : 'error';
                    
                    var html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
                    html += '<div><strong>Created:</strong> ' + log.created_at + '</div>';
                    html += '<div><strong>Direction:</strong> <span class="aks-log-badge ' + log.direction + '">' + log.direction + '</span></div>';
                    html += '<div><strong>Service:</strong> ' + escapeHtml(log.service) + '</div>';
                    html += '<div><strong>Method:</strong> ' + log.method + '</div>';
                    html += '<div><strong>Status:</strong> <span class="aks-log-badge ' + statusClass + '">' + (log.response_code || 'N/A') + '</span></div>';
                    html += '<div><strong>Duration:</strong> ' + (log.duration_ms ? log.duration_ms + 'ms' : 'N/A') + '</div>';
                    if (log.user_email) html += '<div><strong>User Email:</strong> ' + escapeHtml(log.user_email) + '</div>';
                    if (log.user_id) html += '<div><strong>User ID:</strong> ' + log.user_id + '</div>';
                    if (log.context) html += '<div><strong>Context:</strong> ' + escapeHtml(log.context) + '</div>';
                    html += '</div>';
                    
                    html += '<div style="margin-top: 20px;"><strong>Endpoint:</strong><br><code style="word-break: break-all;">' + escapeHtml(log.endpoint) + '</code></div>';
                    
                    if (log.error_message) {
                        html += '<div style="margin-top: 20px; padding: 15px; background: #f8d7da; border-radius: 4px;"><strong>Error:</strong><br>' + escapeHtml(log.error_message) + '</div>';
                    }
                    
                    if (log.request_headers) {
                        html += '<div style="margin-top: 20px;"><strong>Request Headers:</strong><pre class="aks-log-pre">' + escapeHtml(log.request_headers) + '</pre></div>';
                    }
                    
                    if (log.request_body) {
                        html += '<div style="margin-top: 20px;"><strong>Request Body:</strong><pre class="aks-log-pre">' + escapeHtml(log.request_body) + '</pre></div>';
                    }
                    
                    if (log.response_headers) {
                        html += '<div style="margin-top: 20px;"><strong>Response Headers:</strong><pre class="aks-log-pre">' + escapeHtml(log.response_headers) + '</pre></div>';
                    }
                    
                    if (log.response_body) {
                        html += '<div style="margin-top: 20px;"><strong>Response Body:</strong><pre class="aks-log-pre">' + escapeHtml(log.response_body) + '</pre></div>';
                    }
                    
                    $('#log-detail-content').html(html);
                    $('#log-detail-modal').fadeIn(200);
                });
            });
            
            // Close modal
            $('#close-modal, #log-detail-modal').click(function(e) {
                if (e.target === this) {
                    $('#log-detail-modal').fadeOut(200);
                }
            });
            
            // Pagination
            $(document).on('click', '.btn-page', function() {
                loadLogs($(this).data('page'));
            });
            
            // Filter
            $('#btn-filter').click(function() {
                loadLogs(1);
            });
            
            // Reset
            $('#btn-reset').click(function() {
                $('#filter-service, #filter-direction, #filter-success, #filter-search, #filter-date-from, #filter-date-to').val('');
                loadLogs(1);
            });
            
            // Refresh
            $('#btn-refresh').click(function() {
                loadLogs(currentPage);
            });
            
            // Clear logs
            $('#btn-clear-logs').click(function() {
                if (!confirm('Are you sure you want to delete ALL logs? This cannot be undone.')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'aks_clear_logs',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        loadLogs(1);
                    }
                });
            });
            
            // Enter key in search
            $('#filter-search').keypress(function(e) {
                if (e.which === 13) {
                    loadLogs(1);
                }
            });
            
            // Initial load
            loadLogs(1);
        });
        </script>
        <?php
    }
}

// Initialize
AKS_API_Logger::get_instance();