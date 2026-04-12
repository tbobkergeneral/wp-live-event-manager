<?php
/**
 * Stream Vendors – per-provider credentials and settings.
 *
 * Top-level tabs  : one per registered provider + "Add Provider" info tab.
 * Provider sub-tabs: Credentials | [extra tabs declared by the provider via get_extra_tabs()]
 *
 * URL params:
 *   provider  – active provider slug  (default: first registered provider)
 *   subtab    – active sub-tab slug   (default: 'credentials')
 */
if (!defined('ABSPATH')) exit;

$factory  = LEM_Streaming_Provider_Factory::get_instance();
$settings = get_option('lem_settings', []);

$registered_providers = $factory->get_available_providers(); // ['mux', ...]

// Active provider tab
$active_provider_id = sanitize_text_field($_GET['provider'] ?? ($settings['streaming_provider'] ?? ($registered_providers[0] ?? 'mux')));
if (!in_array($active_provider_id, $registered_providers, true) && $active_provider_id !== '_add') {
    $active_provider_id = $registered_providers[0] ?? 'mux';
}

// Active sub-tab
$active_subtab = sanitize_text_field($_GET['subtab'] ?? 'credentials');

// Load the active provider (null when on the _add tab)
$provider = ($active_provider_id !== '_add') ? $factory->get_provider($active_provider_id) : null;

// ── Credential save handler ───────────────────────────────────────────────────
if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
if (
    $provider &&
    $active_subtab === 'credentials' &&
    isset($_POST['lem_vendor_nonce']) &&
    wp_verify_nonce($_POST['lem_vendor_nonce'], 'lem_save_vendor_' . $active_provider_id)
) {
    $fields = $provider->get_settings_fields();

    foreach ($fields as $key => $field) {
        $type         = $field['type'] ?? 'text';
        $raw          = $_POST[$key] ?? '';
        $settings[$key] = ($type === 'textarea')
            ? sanitize_textarea_field($raw)
            : sanitize_text_field($raw);
    }

    update_option('lem_settings', $settings);

    $validation = $provider->validate_settings($settings);
    if ($validation === true) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>Credentials saved.</strong></p></div>';
    } else {
        $issues = is_array($validation)
            ? implode('</li><li>', array_map('esc_html', $validation))
            : esc_html($validation);
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Saved with warnings:</strong></p><ul><li>' . $issues . '</li></ul></div>';
    }

    // Reload after save
    $settings = get_option('lem_settings', []);
    $provider = $factory->get_provider($active_provider_id);
}

// Helper: build a vendor-page URL
function lem_vendor_url($provider_id, $subtab = 'credentials') {
    return admin_url(add_query_arg(
        ['post_type' => 'lem_event', 'page' => 'live-event-manager-stream-vendors', 'provider' => $provider_id, 'subtab' => $subtab],
        'edit.php'
    ));
}
?>

<div class="wrap lem-vendors-wrap">
    <h1>Vendors</h1>

    <?php /* ── Provider top-level tabs ──────────────────────────────────── */ ?>
    <nav class="nav-tab-wrapper">
        <?php foreach ($registered_providers as $pid):
            $pname      = $factory->get_provider_name($pid);
            $p          = $factory->get_provider($pid);
            $configured = $p ? $p->is_configured() : false;
            $is_active  = ($active_provider_id === $pid);
            $url        = lem_vendor_url($pid, 'credentials');
        ?>
        <a href="<?php echo esc_url($url); ?>"
           class="nav-tab <?php echo $is_active ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html($pname); ?>
            <?php if ($configured): ?>
                <span class="lem-status-dot lem-ok" title="Configured">&#10003;</span>
            <?php else: ?>
                <span class="lem-status-dot lem-err" title="Not configured">&#10007;</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>

        <a href="<?php echo esc_url(lem_vendor_url('_add')); ?>"
           class="nav-tab <?php echo $active_provider_id === '_add' ? 'nav-tab-active' : ''; ?>">
            + Add Provider
        </a>
    </nav>

    <?php /* ── Provider panel ───────────────────────────────────────────── */ ?>
    <?php if ($active_provider_id === '_add'): ?>

    <div class="lem-vendor-panel lem-add-provider-panel">

        <div class="lem-add-provider-header">
            <div>
                <h2>Add a Streaming Provider</h2>
                <p class="description">Any class implementing <code>LEM_Streaming_Provider_Interface</code> is automatically picked up — no changes to this page required.</p>
            </div>
            <button type="button" class="button button-primary lem-download-template" id="lem-download-template-btn">
                &#8681; Download Provider Template
            </button>
        </div>

        <div class="lem-add-steps">
            <div class="lem-add-step">
                <span class="lem-step-num">1</span>
                <div>
                    <strong>Download &amp; place the class file</strong>
                    <p>Click the button above to get a pre-filled stub. Save it as:<br>
                    <code>services/streaming/providers/class-<em>yourprovider</em>-provider.php</code></p>
                </div>
            </div>
            <div class="lem-add-step">
                <span class="lem-step-num">2</span>
                <div>
                    <strong>Register the provider</strong>
                    <p>In <code>class-streaming-provider-factory.php</code>, add one line inside <code>__construct()</code>:</p>
                    <pre>$this->register_provider('yourprovider', 'LEM_YourProvider_Provider');</pre>
                </div>
            </div>
            <div class="lem-add-step">
                <span class="lem-step-num">3</span>
                <div>
                    <strong>Select the provider</strong>
                    <p>Go to <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-settings&tab=streaming')); ?>">Settings → Streaming</a> and choose it from the dropdown.</p>
                </div>
            </div>
        </div>

        <hr class="lem-divider">

        <h3>Interface methods</h3>
        <div class="lem-interface-grid">
            <div>
                <h4>Identity &amp; Configuration</h4>
                <ul>
                    <li><code>get_id()</code> — unique slug, e.g. <code>'myprovider'</code></li>
                    <li><code>get_name()</code> — display name, e.g. <code>'My Provider'</code></li>
                    <li><code>get_settings_fields()</code> — credential form fields (rendered automatically here)</li>
                    <li><code>is_configured()</code> — returns bool; drives the ✓/✗ on the tab</li>
                    <li><code>validate_settings()</code> — returns <code>true</code> or array of issues</li>
                    <li><code>get_extra_tabs()</code> — optional provider-specific sub-tabs</li>
                </ul>
            </div>
            <div>
                <h4>Streaming</h4>
                <ul>
                    <li><code>create_stream($params)</code></li>
                    <li><code>list_streams()</code></li>
                    <li><code>delete_stream($stream_id)</code></li>
                    <li><code>get_stream_status($stream_id)</code></li>
                    <li><code>generate_playback_token($playback_id, $opts)</code></li>
                </ul>
                <h4>Simulcast</h4>
                <ul>
                    <li><code>create_simulcast_target($stream_id, $params)</code></li>
                    <li><code>list_simulcast_targets($stream_id)</code></li>
                    <li><code>delete_simulcast_target($stream_id, $target_id)</code></li>
                </ul>
            </div>
        </div>

        <div class="notice notice-info inline lem-planned-providers">
            <p>&#128736; Planned providers: <strong>Cloudflare Stream</strong>, <strong>LiveKit</strong>, <strong>YouTube Live</strong>. PRs welcome on <a href="https://github.com/simulcast/wp-live-event-manager" target="_blank">GitHub</a>.</p>
        </div>

        <hr class="lem-divider">

        <!-- ── Upload zone ──────────────────────────────────────────────── -->
        <h3>Upload a Provider</h3>
        <p class="description" style="margin-bottom:16px;">
            Already have a completed provider class? Upload it here — the file will be placed in
            <code>services/streaming/providers/</code> and registered automatically.
        </p>

        <div id="lem-upload-zone" class="lem-upload-zone" tabindex="0" role="button" aria-label="Upload provider file">
            <div class="lem-upload-icon">&#8681;</div>
            <p class="lem-upload-label">Drag &amp; drop your <code>class-{slug}-provider.php</code> here</p>
            <p class="lem-upload-or">or</p>
            <label for="lem-provider-file-input" class="button">Browse file&hellip;</label>
            <input type="file" id="lem-provider-file-input" accept=".php" style="display:none;">
            <p id="lem-upload-filename" class="lem-upload-filename" style="display:none;"></p>
        </div>

        <div style="margin-top:12px; display:flex; gap:10px; align-items:center;">
            <button type="button" class="button button-primary" id="lem-upload-btn" disabled>
                Upload &amp; Register
            </button>
            <span id="lem-upload-spinner" class="spinner" style="float:none; visibility:hidden;"></span>
        </div>

        <div id="lem-upload-result" style="margin-top:16px; display:none;"></div>

    </div><!-- .lem-add-provider-panel -->

    <?php
    // Build the downloadable template PHP file content
    $template_php = <<<'PHPTEMPLATE'
<?php
/**
 * My Provider – LEM Streaming Provider
 *
 * QUICK START
 * -----------
 * 1. Rename every occurrence of "MyProvider" / "myprovider" to match your provider.
 * 2. Save as: services/streaming/providers/class-myprovider-provider.php
 * 3. Register in class-streaming-provider-factory.php:
 *      $this->register_provider('myprovider', 'LEM_MyProvider_Provider');
 * 4. Select the provider in Settings → Streaming.
 */

if (!defined('ABSPATH')) exit;

class LEM_MyProvider_Provider implements LEM_Streaming_Provider_Interface {

    /** @var array Plugin settings (lem_settings option) */
    private array $settings;

    /** @var object|null Reference to the main plugin instance */
    private $plugin;

    public function __construct($plugin = null) {
        $this->plugin   = $plugin;
        $this->settings = get_option('lem_settings', []);
    }

    // ── Identity ─────────────────────────────────────────────────────────────

    public function get_id(): string {
        return 'myprovider'; // TODO: replace with your slug
    }

    public function get_name(): string {
        return 'My Provider'; // TODO: replace with display name
    }

    // ── Settings fields ───────────────────────────────────────────────────────
    // These are rendered automatically on the Stream Vendors credentials tab.

    public function get_settings_fields(): array {
        return [
            'myprovider_api_key' => [
                'label'       => 'API Key',
                'type'        => 'text',
                'section'     => 'API Credentials',
                'required'    => true,
                'placeholder' => 'sk_live_…',
                'description' => 'Found in your provider dashboard under API → Keys.',
            ],
            'myprovider_api_secret' => [
                'label'       => 'API Secret',
                'type'        => 'password',
                'section'     => 'API Credentials',
                'required'    => true,
                'placeholder' => '',
                'description' => 'Keep this secret — never expose it client-side.',
            ],
            // Add more fields as needed; supported types: text, password, textarea, url
        ];
    }

    // ── Configuration status ──────────────────────────────────────────────────

    public function is_configured(): bool {
        return !empty($this->settings['myprovider_api_key'])
            && !empty($this->settings['myprovider_api_secret']);
    }

    public function validate_settings(array $settings): bool|array {
        $issues = [];

        if (empty($settings['myprovider_api_key'])) {
            $issues[] = 'API Key is required.';
        }
        if (empty($settings['myprovider_api_secret'])) {
            $issues[] = 'API Secret is required.';
        }

        // TODO: optionally make a test API call here to verify credentials.

        return empty($issues) ? true : $issues;
    }

    // ── Extra admin sub-tabs (optional) ───────────────────────────────────────
    // Return an empty array if you don't need additional tabs.

    public function get_extra_tabs(): array {
        return [];
        // Example:
        // return [
        //     'restrictions' => [
        //         'label'    => 'Playback Restrictions',
        //         'template' => LEM_PLUGIN_DIR . 'templates/myprovider-restrictions-page.php',
        //     ],
        // ];
    }

    // ── Playback token ────────────────────────────────────────────────────────

    /**
     * Generate a signed playback token for a viewer.
     *
     * @param string $playback_id  The stream/asset playback ID.
     * @param array  $options      Optional: audience, expiry, claims, etc.
     * @return string|WP_Error     Signed token string, or WP_Error on failure.
     */
    public function generate_playback_token(string $playback_id, array $options = []) {
        // TODO: implement token signing for your provider.
        // For JWT-based providers, use the firebase/php-jwt library or openssl_sign().
        return new WP_Error('not_implemented', 'generate_playback_token() not yet implemented.');
    }

    // ── Stream management ─────────────────────────────────────────────────────

    /**
     * Create a new live stream.
     *
     * @param array $params {
     *   string   $passthrough          Friendly name / metadata.
     *   string[] $playback_policies    e.g. ['public'] or ['signed'].
     *   string[] $asset_playback_policies
     *   bool     $reduced_latency
     *   bool     $test_mode
     * }
     * @return array|WP_Error  Stream object with at least: id, stream_key, playback_ids, status.
     */
    public function create_stream(array $params) {
        // TODO: call your provider's create-stream API endpoint.
        // Example shape to return:
        // return [
        //     'id'          => 'stream_abc123',
        //     'stream_key'  => 'sk_live_…',
        //     'playback_ids'=> [['id' => 'pbid_…', 'policy' => 'public']],
        //     'status'      => 'idle',
        //     'passthrough' => $params['passthrough'] ?? '',
        //     'created_at'  => date('c'),
        // ];
        return new WP_Error('not_implemented', 'create_stream() not yet implemented.');
    }

    /**
     * Return all live streams for this account.
     *
     * @return array|WP_Error  Array of stream objects (same shape as create_stream return).
     */
    public function list_streams() {
        // TODO: call your provider's list-streams API endpoint.
        return new WP_Error('not_implemented', 'list_streams() not yet implemented.');
    }

    /**
     * Delete a live stream.
     *
     * @param string $stream_id
     * @return true|WP_Error
     */
    public function delete_stream(string $stream_id) {
        // TODO: call your provider's delete-stream API endpoint.
        return new WP_Error('not_implemented', 'delete_stream() not yet implemented.');
    }

    /**
     * Get the current status of a stream.
     *
     * @param string $stream_id
     * @return array|WP_Error {
     *   string $status      e.g. 'active', 'idle', 'disabled'.
     *   bool   $is_active
     *   mixed  $recent_asset  Optional: most recent recorded asset.
     * }
     */
    public function get_stream_status(string $stream_id) {
        // TODO: call your provider's stream-status API endpoint.
        return new WP_Error('not_implemented', 'get_stream_status() not yet implemented.');
    }

    // ── Simulcast ─────────────────────────────────────────────────────────────

    /**
     * Add a simulcast target to a stream.
     *
     * @param string $stream_id
     * @param array  $params  { url: string, stream_key?: string }
     * @return array|WP_Error  Target object with at least: id, url, status.
     */
    public function create_simulcast_target(string $stream_id, array $params) {
        return new WP_Error('not_implemented', 'create_simulcast_target() not yet implemented.');
    }

    /**
     * List all simulcast targets for a stream.
     *
     * @param string $stream_id
     * @return array|WP_Error
     */
    public function list_simulcast_targets(string $stream_id) {
        return new WP_Error('not_implemented', 'list_simulcast_targets() not yet implemented.');
    }

    /**
     * Delete a simulcast target.
     *
     * @param string $stream_id
     * @param string $target_id
     * @return true|WP_Error
     */
    public function delete_simulcast_target(string $stream_id, string $target_id) {
        return new WP_Error('not_implemented', 'delete_simulcast_target() not yet implemented.');
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Make an authenticated request to the provider API.
     *
     * @param string $method  'GET', 'POST', 'DELETE', etc.
     * @param string $path    API path, e.g. '/live-streams'.
     * @param array  $body    Request body (will be JSON-encoded for non-GET).
     * @return array|WP_Error Decoded response body.
     */
    private function api_request(string $method, string $path, array $body = []) {
        $this->settings = get_option('lem_settings', []); // ensure fresh

        $api_key    = $this->settings['myprovider_api_key']    ?? '';
        $api_secret = $this->settings['myprovider_api_secret'] ?? '';

        // TODO: replace with your provider's base URL.
        $base_url = 'https://api.myprovider.example/v1';

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                // TODO: replace with your provider's auth scheme.
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 10,
        ];

        if (!empty($body) && $method !== 'GET') {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($base_url . $path, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $data      = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code >= 400) {
            $message = $data['error']['message'] ?? $data['message'] ?? 'API error ' . $http_code;
            return new WP_Error('api_error', $message, ['status' => $http_code]);
        }

        return $data;
    }
}
PHPTEMPLATE;
    ?>

    <script>
    (function() {
        // ── Template download ─────────────────────────────────────────────
        document.getElementById('lem-download-template-btn').addEventListener('click', function () {
            var content  = <?php echo wp_json_encode($template_php); ?>;
            var filename = 'class-myprovider-provider.php';
            var blob     = new Blob([content], { type: 'text/plain' });
            var url      = URL.createObjectURL(blob);
            var a        = document.createElement('a');
            a.href       = url;
            a.download   = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });

        // ── File upload ───────────────────────────────────────────────────
        var ajaxUrl    = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var uploadNonce = <?php echo wp_json_encode(wp_create_nonce('lem_upload_provider_nonce')); ?>;

        var $zone      = document.getElementById('lem-upload-zone');
        var $fileInput = document.getElementById('lem-provider-file-input');
        var $filename  = document.getElementById('lem-upload-filename');
        var $btn       = document.getElementById('lem-upload-btn');
        var $spinner   = document.getElementById('lem-upload-spinner');
        var $result    = document.getElementById('lem-upload-result');
        var selectedFile = null;

        // Click zone → open file picker
        $zone.addEventListener('click', function (e) {
            if (e.target.tagName !== 'LABEL' && e.target.tagName !== 'INPUT') {
                $fileInput.click();
            }
        });
        $zone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') $fileInput.click();
        });

        // File selected via picker
        $fileInput.addEventListener('change', function () {
            setFile(this.files[0] || null);
        });

        // Drag & drop
        $zone.addEventListener('dragover', function (e) {
            e.preventDefault();
            $zone.classList.add('lem-upload-drag');
        });
        $zone.addEventListener('dragleave', function () {
            $zone.classList.remove('lem-upload-drag');
        });
        $zone.addEventListener('drop', function (e) {
            e.preventDefault();
            $zone.classList.remove('lem-upload-drag');
            var f = e.dataTransfer.files[0] || null;
            setFile(f);
        });

        function setFile(f) {
            selectedFile = f;
            if (!f) {
                $filename.style.display  = 'none';
                $btn.disabled            = true;
                $zone.classList.remove('lem-upload-has-file');
                return;
            }
            $filename.textContent    = f.name;
            $filename.style.display  = 'block';
            $btn.disabled            = false;
            $zone.classList.add('lem-upload-has-file');
            clearResult();
        }

        // Upload
        $btn.addEventListener('click', function () {
            if (!selectedFile) return;

            var fd = new FormData();
            fd.append('action',        'lem_upload_provider');
            fd.append('nonce',         uploadNonce);
            fd.append('provider_file', selectedFile, selectedFile.name);

            $btn.disabled             = true;
            $spinner.style.visibility = 'visible';
            clearResult();

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    $spinner.style.visibility = 'hidden';
                    if (resp.success) {
                        showSuccess(resp.data);
                    } else {
                        showError(resp.data || 'Upload failed.');
                        $btn.disabled = false;
                    }
                })
                .catch(function (err) {
                    $spinner.style.visibility = 'hidden';
                    showError('Network error: ' + err);
                    $btn.disabled = false;
                });
        });

        function showSuccess(data) {
            var action    = data.is_replace ? 'updated' : 'uploaded';
            var regStatus = data.registered
                ? '<span class="lem-upload-ok">&#10003; Auto-registered in factory.</span>'
                : '<span class="lem-upload-warn">&#9888; Could not auto-register. Add this line manually to <code>class-streaming-provider-factory.php</code> inside <code>__construct()</code>:<br><code>' + escHtml(data.register_snippet) + '</code></span>';

            $result.innerHTML =
                '<div class="lem-upload-result-box lem-result-ok">' +
                    '<strong>&#10003; Provider ' + escHtml(action) + ' successfully!</strong><br>' +
                    'File: <code>' + escHtml(data.filename) + '</code><br>' +
                    'Class: <code>' + escHtml(data.class_name) + '</code><br><br>' +
                    regStatus + '<br><br>' +
                    (data.registered
                        ? '<a href="' + escHtml(window.location.href.replace(/[?&]provider=[^&]*/g,'')) + '&provider=' + escHtml(data.provider_id) + '" class="button button-primary">Go to ' + escHtml(data.provider_id) + ' settings →</a>'
                        : ''
                    ) +
                '</div>';
            $result.style.display = 'block';

            // Reset upload zone
            setFile(null);
            $fileInput.value = '';
        }

        function showError(msg) {
            $result.innerHTML =
                '<div class="lem-upload-result-box lem-result-err">' +
                    '<strong>&#10007; Upload failed.</strong> ' + msg +
                '</div>';
            $result.style.display = 'block';
        }

        function clearResult() {
            $result.innerHTML    = '';
            $result.style.display = 'none';
        }

        function escHtml(s) {
            return String(s)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    })();
    </script>

    <?php elseif ($provider): ?>

    <?php
    // Build sub-tabs for this provider
    $subtabs = ['credentials' => 'Credentials'];
    $extra   = $provider->get_extra_tabs();
    foreach ($extra as $slug => $tab) {
        $subtabs[$slug] = $tab['label'];
    }
    // Ensure active subtab is valid
    if (!isset($subtabs[$active_subtab])) {
        $active_subtab = 'credentials';
    }
    ?>

    <div class="lem-vendor-panel">

        <?php /* Sub-tab nav — only shown when provider has extra tabs */ ?>
        <?php if (count($subtabs) > 1): ?>
        <div class="lem-subtab-nav">
            <?php foreach ($subtabs as $slug => $label):
                $url = lem_vendor_url($active_provider_id, $slug);
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="lem-subtab <?php echo $active_subtab === $slug ? 'lem-subtab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php /* ── Credentials sub-tab ─────────────────────────────────── */ ?>
        <?php if ($active_subtab === 'credentials'): ?>

        <?php if ($provider->is_configured()): ?>
        <div class="lem-status-banner lem-status-ok">
            <?php echo esc_html($provider->get_name()); ?> is configured and ready.
        </div>
        <?php else: ?>
        <div class="lem-status-banner lem-status-warn">
            <strong><?php echo esc_html($provider->get_name()); ?> is not fully configured.</strong>
            Complete the required fields below.
        </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(lem_vendor_url($active_provider_id, 'credentials')); ?>">
            <?php wp_nonce_field('lem_save_vendor_' . $active_provider_id, 'lem_vendor_nonce'); ?>

            <?php
            $fields          = $provider->get_settings_fields();
            $current_section = null;
            $field_list      = array_values(array_map(null, array_keys($fields), $fields));
            $total           = count($field_list);

            foreach ($field_list as $i => $pair):
                [$key, $field] = $pair;
                $section     = $field['section']     ?? '';
                $label       = $field['label']       ?? $key;
                $type        = $field['type']        ?? 'text';
                $required    = $field['required']    ?? false;
                $description = $field['description'] ?? '';
                $placeholder = $field['placeholder'] ?? '';
                $current_val = $settings[$key]       ?? '';

                if ($section && $section !== $current_section):
                    if ($current_section !== null) echo '</table>';
                    $current_section = $section;
                    echo '<h2>' . esc_html($section) . '</h2><table class="form-table">';
                elseif ($current_section === null):
                    $current_section = '';
                    echo '<table class="form-table">';
                endif;
            ?>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($label); ?>
                            <?php if ($required): ?><span style="color:#d63638;" title="Required"> *</span><?php endif; ?>
                        </label>
                    </th>
                    <td>
                        <?php if ($type === 'textarea'): ?>
                            <textarea id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                                      rows="5" class="large-text code"
                                      placeholder="<?php echo esc_attr($placeholder); ?>"
                            ><?php echo esc_textarea($current_val); ?></textarea>
                        <?php else: ?>
                            <input type="<?php echo esc_attr($type); ?>"
                                   id="<?php echo esc_attr($key); ?>"
                                   name="<?php echo esc_attr($key); ?>"
                                   value="<?php echo esc_attr($current_val); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($placeholder); ?>"
                                   <?php echo $required ? 'required' : ''; ?>>
                        <?php endif; ?>
                        <?php if ($description): ?>
                            <p class="description"><?php echo wp_kses($description, ['strong' => [], 'code' => [], 'em' => [], 'a' => ['href' => [], 'target' => []]]); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php if ($i === $total - 1) echo '</table>'; endforeach; ?>

            <h2>Webhook</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Webhook URL</th>
                    <td>
                        <code><?php echo esc_url(admin_url('admin-ajax.php?action=lem_' . $active_provider_id . '_webhook')); ?></code>
                        <p class="description">Register this URL in your <?php echo esc_html($provider->get_name()); ?> dashboard to receive stream events.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-primary" value="Save Credentials">
            </p>
        </form>

        <?php /* ── Extra provider sub-tabs ──────────────────────────────── */ ?>
        <?php elseif (isset($extra[$active_subtab])): ?>

        <?php
        $template_path = $extra[$active_subtab]['template'] ?? '';
        if ($template_path && file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<p>Template not found for this tab.</p>';
        }
        ?>

        <?php endif; /* end sub-tab */ ?>
    </div><!-- .lem-vendor-panel -->

    <?php else: ?>
    <div class="notice notice-error"><p>Provider not found. Check that the class file exists in <code>services/streaming/providers/</code>.</p></div>
    <?php endif; ?>

</div><!-- .wrap -->

<style>
/* ── Provider top-level tabs ── */
.lem-vendors-wrap .nav-tab-wrapper {
    margin-bottom: 0;
    border-bottom: 1px solid #c3c4c7;
}
.lem-vendors-wrap .nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.lem-status-dot        { font-size: 13px; line-height: 1; }
.lem-status-dot.lem-ok  { color: #46b450; }
.lem-status-dot.lem-err { color: #d63638; }

/* ── Provider content panel ── */
.lem-vendor-panel {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-top: none;
    padding: 0 24px 24px;
}

/* ── Sub-tabs ── */
.lem-subtab-nav {
    display: flex;
    gap: 0;
    border-bottom: 1px solid #e0e0e0;
    margin: 0 -24px 24px;
    padding: 0 24px;
    background: #f6f7f7;
}
.lem-subtab {
    display: inline-block;
    padding: 10px 16px;
    font-size: 13px;
    color: #3c434a;
    text-decoration: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -1px;
    transition: color 0.15s, border-color 0.15s;
}
.lem-subtab:hover { color: #0073aa; border-bottom-color: #c3c4c7; }
.lem-subtab-active {
    color: #0073aa;
    border-bottom-color: #0073aa;
    font-weight: 600;
}

/* ── Status banners ── */
.lem-status-banner {
    padding: 10px 14px;
    border-radius: 4px;
    margin: 20px 0 4px;
    font-size: 13px;
    line-height: 1.5;
}
.lem-status-ok   { background: #edfaed; border: 1px solid #b3e6b3; color: #2a6e2a; }
.lem-status-warn { background: #fff8e5; border: 1px solid #f5c518; color: #6d4c00; }

/* ── Add provider panel ── */
.lem-vendor-panel pre {
    background: #f6f7f7;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 10px 14px;
    font-size: 12px;
    overflow-x: auto;
}
.lem-vendor-panel ol,
.lem-vendor-panel ul { padding-left: 20px; }
.lem-vendor-panel li { margin-bottom: 6px; }

/* Add Provider – header row */
.lem-add-provider-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    padding-top: 20px;
    flex-wrap: wrap;
}
.lem-add-provider-header h2 { margin: 0 0 4px; }
.lem-add-provider-header .description { margin: 0; }
.lem-download-template {
    flex-shrink: 0;
    font-size: 13px !important;
    padding: 6px 14px !important;
    height: auto !important;
}

/* Steps */
.lem-add-steps {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin: 24px 0 0;
}
.lem-add-step {
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.lem-step-num {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    background: #0073aa;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    margin-top: 1px;
}
.lem-add-step strong { display: block; margin-bottom: 4px; }
.lem-add-step p { margin: 0 0 6px; color: #50575e; }
.lem-add-step pre {
    margin: 6px 0 0;
    display: inline-block;
}

/* Divider */
.lem-divider {
    border: none;
    border-top: 1px solid #e0e0e0;
    margin: 24px 0;
}

/* Interface grid */
.lem-interface-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 20px;
}
@media (max-width: 900px) {
    .lem-interface-grid { grid-template-columns: 1fr; }
    .lem-add-provider-header { flex-direction: column; }
}
.lem-interface-grid h4 {
    font-size: 13px;
    margin: 0 0 8px;
    padding-bottom: 4px;
    border-bottom: 1px solid #e0e0e0;
}
.lem-interface-grid ul {
    list-style: none;
    padding: 0;
    margin: 0 0 16px;
}
.lem-interface-grid li {
    padding: 3px 0;
    font-size: 13px;
    border-bottom: 1px solid #f0f0f0;
}
.lem-interface-grid li:last-child { border-bottom: none; }

/* Planned providers notice */
.lem-planned-providers { margin: 0 !important; }

/* ── Upload zone ── */
.lem-upload-zone {
    border: 2px dashed #c3c4c7;
    border-radius: 6px;
    padding: 32px 24px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    background: #fafafa;
    outline: none;
}
.lem-upload-zone:hover,
.lem-upload-zone:focus {
    border-color: #0073aa;
    background: #f0f6fc;
}
.lem-upload-zone.lem-upload-drag {
    border-color: #0073aa;
    background: #e8f3fb;
}
.lem-upload-zone.lem-upload-has-file {
    border-color: #46b450;
    background: #f0faf0;
}
.lem-upload-icon {
    font-size: 36px;
    line-height: 1;
    color: #a0a5aa;
    margin-bottom: 8px;
}
.lem-upload-has-file .lem-upload-icon { color: #46b450; }
.lem-upload-label {
    margin: 0 0 6px;
    font-size: 14px;
    color: #3c434a;
}
.lem-upload-or {
    margin: 4px 0 10px;
    font-size: 12px;
    color: #8c8f94;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.lem-upload-filename {
    margin: 10px 0 0;
    font-size: 13px;
    font-weight: 600;
    color: #1d2327;
}

/* Result box */
.lem-upload-result-box {
    padding: 16px 18px;
    border-radius: 4px;
    font-size: 13px;
    line-height: 1.7;
}
.lem-result-ok  { background: #edfaed; border: 1px solid #b3e6b3; }
.lem-result-err { background: #fdf2f2; border: 1px solid #f5c6c6; }
.lem-upload-ok   { color: #2a6e2a; font-weight: 600; }
.lem-upload-warn { color: #6d4c00; }
</style>
