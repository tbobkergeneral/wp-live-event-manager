<?php
/**
 * Stream Management Page - Create, Edit, Delete Live Streams
 */

if (!defined('ABSPATH')) {
    exit;
}

global $live_event_manager;

$settings = get_option('lem_settings', array());

// Check if Mux is configured
$mux_configured = !empty($settings['mux_token_id']) && !empty($settings['mux_token_secret']);

// Get list of available streams from Mux
$available_streams = array();

if ($live_event_manager && $mux_configured) {
    $credentials = $live_event_manager->get_mux_api_credentials();
    if ($credentials) {
        $request = new WP_REST_Request('GET', '/lem/v1/live-streams');
        $streams_result = $live_event_manager->list_live_streams($request);
        if (!is_wp_error($streams_result) && isset($streams_result['data'])) {
            foreach ($streams_result['data'] as $stream) {
                $stream['_provider'] = 'mux';
                $available_streams[] = $stream;
            }
        }
    }
}
?>

<div class="wrap">
    <h1>Stream Management</h1>
    <p class="description">Create, edit, and delete Mux live streams directly from WordPress.</p>
    
    <?php if (!$mux_configured): ?>
        <div class="notice notice-error">
            <p><strong>Mux API credentials not configured.</strong> Please set up your Mux credentials in <a href="<?php echo admin_url('edit.php?post_type=lem_event&page=live-event-manager-stream-vendors'); ?>">Stream Vendors</a>.</p>
        </div>
    <?php else: ?>
        
        <!-- Create New Stream -->
        <div class="postbox" style="padding: 20px; margin-bottom: 20px;">
            <h2 style="margin-top: 0; margin-bottom: 15px;">Create New Stream</h2>
            <form id="lem-create-stream-form" style="display: flex; align-items: flex-end; gap: 15px; flex-wrap: wrap;">
                <input type="hidden" id="lem-new-stream-provider" value="mux">
                
                <div style="flex: 1; min-width: 200px;">
                    <label for="lem-new-stream-name" style="display: block; font-weight: 600; margin-bottom: 5px;">Stream Name:</label>
                    <input type="text" id="lem-new-stream-name" class="regular-text" 
                           placeholder="e.g., Main Event Stream" required 
                           style="width: 100%;">
                    <p class="description" style="margin: 5px 0 0 0;">A friendly name to identify this stream</p>
                </div>
                
                <div style="min-width: 150px;">
                    <label for="lem-new-stream-playback-policy" style="display: block; font-weight: 600; margin-bottom: 5px;">Stream Policy:</label>
                    <select id="lem-new-stream-playback-policy" class="regular-text" style="width: 100%;" required>
                        <option value="public">Public</option>
                        <option value="signed">Signed</option>
                        <option value="public,signed">Public & Signed</option>
                    </select>
                    <p class="description" style="margin: 5px 0 0 0; font-size: 12px;">Live stream playback policy</p>
                </div>
                
                <div style="min-width: 150px;">
                    <label for="lem-new-stream-asset-policy" style="display: block; font-weight: 600; margin-bottom: 5px;">Asset Policy:</label>
                    <select id="lem-new-stream-asset-policy" class="regular-text" style="width: 100%;" required>
                        <option value="public">Public</option>
                        <option value="signed">Signed</option>
                        <option value="public,signed">Public & Signed</option>
                    </select>
                    <p class="description" style="margin: 5px 0 0 0; font-size: 12px;">Recorded asset playback policy</p>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; margin-bottom: 0;">
                        <input type="checkbox" id="lem-new-stream-reduced-latency" value="1">
                        <span>Reduced Latency</span>
                    </label>
                    <p class="description" style="margin: 0; font-size: 12px;">Enable for lower latency streaming</p>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; margin-bottom: 0;">
                        <input type="checkbox" id="lem-new-stream-test" value="1">
                        <span>Test Mode</span>
                    </label>
                    <p class="description" style="margin: 0; font-size: 12px;">Create as test stream</p>
                </div>
                
                <div>
                    <button type="submit" class="button button-primary">Create Stream</button>
                </div>
            </form>
        </div>
        
        <!-- Streams List -->
        <div class="postbox" style="padding: 20px;">
            <h2 style="margin-top: 0;">Your Streams</h2>
            
            <?php if (!empty($available_streams)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Name/ID</th>
                            <th>Status</th>
                            <th>Playback ID</th>
                            <th>Stream Key</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($available_streams as $stream): ?>
                            <?php
                            $stream_id = $stream['id'] ?? '';
                            $stream_name = $stream['passthrough'] ?? ($stream['id'] ?? 'Unnamed Stream');
                            $provider = $stream['_provider'] ?? 'mux';
                            $status = $stream['status'] ?? 'unknown';
                            // Handle playback_ids - can be array of objects or array of strings
                            $playback_ids = $stream['playback_ids'] ?? array();
                            $playback_id = 'N/A';
                            if (!empty($playback_ids)) {
                                if (isset($playback_ids[0]['id'])) {
                                    $playback_id = $playback_ids[0]['id'];
                                } elseif (is_string($playback_ids[0])) {
                                    $playback_id = $playback_ids[0];
                                } elseif (isset($playback_ids[0]) && is_array($playback_ids[0])) {
                                    // Try to find 'id' key in nested structure
                                    $playback_id = $playback_ids[0]['id'] ?? 'N/A';
                                }
                            }
                            $stream_key = $stream['stream_key'] ?? 'N/A';
                            $created_at = isset($stream['created_at']) ? date('M j, Y g:i A', strtotime($stream['created_at'])) : 'N/A';
                            
                            // Skip streams without ID (shouldn't happen, but safety check)
                            if (empty($stream_id)) {
                                continue;
                            }
                            ?>
                            <tr data-stream-id="<?php echo esc_attr($stream_id); ?>" data-provider="<?php echo esc_attr($provider); ?>">
                                <td>
                                    <span style="padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; 
                                        background: <?php echo $provider === 'mux' ? '#7c3aed' : '#dc2626'; ?>; 
                                        color: white;">
                                        <?php echo esc_html(strtoupper($provider)); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($stream_name); ?></strong>
                                    <br>
                                    <code style="font-size: 11px; color: #666;"><?php echo esc_html($stream_id); ?></code>
                                </td>
                                <td>
                                    <span style="padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; 
                                        background: <?php echo $status === 'active' ? '#4CAF50' : ($status === 'idle' ? '#9E9E9E' : '#FF9800'); ?>; 
                                        color: white;">
                                        <?php echo esc_html(ucfirst($status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <code style="font-size: 11px;"><?php echo esc_html($playback_id); ?></code>
                                </td>
                                <td>
                                    <code style="font-size: 11px;"><?php echo esc_html(substr($stream_key, 0, 20) . '...'); ?></code>
                                    <button type="button" class="button button-small lem-copy-key" 
                                            data-key="<?php echo esc_attr($stream_key); ?>" 
                                            title="Copy full key">
                                        Copy
                                    </button>
                                </td>
                                <td><?php echo esc_html($created_at); ?></td>
                                <td>
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <button type="button" class="button button-small lem-edit-stream" 
                                                data-stream-id="<?php echo esc_attr($stream_id); ?>"
                                                data-stream-name="<?php echo esc_attr($stream_name); ?>"
                                                data-reduced-latency="<?php echo isset($stream['reduced_latency']) && $stream['reduced_latency'] ? '1' : '0'; ?>">
                                            Edit
                                        </button>
                                        <a href="<?php echo admin_url('admin.php?page=live-event-manager-stream-setup&stream_id=' . urlencode($stream_id)); ?>" 
                                           class="button button-small">
                                            Setup
                                        </a>
                                        <button type="button" class="button button-small button-link-delete lem-delete-stream" 
                                                data-stream-id="<?php echo esc_attr($stream_id); ?>"
                                                data-stream-name="<?php echo esc_attr($stream_name); ?>">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="description">No streams found. Create your first stream above.</p>
            <?php endif; ?>
        </div>
        
    <?php endif; ?>
</div>

<!-- Edit Stream Modal -->
<div id="lem-edit-stream-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h2 style="margin-top: 0;">Edit Stream</h2>
        <form id="lem-edit-stream-form">
            <input type="hidden" id="lem-edit-stream-id" name="stream_id">
            <table class="form-table">
                <tr>
                    <th><label for="lem-edit-stream-name">Stream Name:</label></th>
                    <td>
                        <input type="text" id="lem-edit-stream-name" class="regular-text" required>
                        <p class="description">A friendly name to identify this stream</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Options:</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="lem-edit-stream-reduced-latency" value="1">
                            Reduced Latency
                        </label>
                        <p class="description">Enable for lower latency streaming</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Save Changes</button>
                <button type="button" class="button lem-cancel-edit" style="margin-left: 10px;">Cancel</button>
            </p>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo wp_create_nonce('lem_stream_management_nonce'); ?>';
    var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
    
    // Create stream
    $('#lem-create-stream-form').on('submit', function(e) {
        e.preventDefault();
        
        var provider = $('#lem-new-stream-provider').val();
        var name = $('#lem-new-stream-name').val().trim();
        var playbackPolicy = $('#lem-new-stream-playback-policy').val();
        var assetPolicy = $('#lem-new-stream-asset-policy').val();
        var reducedLatency = $('#lem-new-stream-reduced-latency').is(':checked');
        var testMode = $('#lem-new-stream-test').is(':checked');
        
        if (!name) {
            alert('Please enter a stream name');
            $('#lem-new-stream-name').focus();
            return;
        }
        
        // Convert comma-separated string to array if needed
        var playbackPolicies = playbackPolicy.split(',').map(function(p) {
            return p.trim();
        });
        
        var assetPolicies = assetPolicy.split(',').map(function(p) {
            return p.trim();
        });
        
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Creating...');
        
        $.post(ajaxUrl, {
            action: 'lem_create_stream',
            provider: provider,
            passthrough: name,
            playback_policies: JSON.stringify(playbackPolicies),
            asset_playback_policies: JSON.stringify(assetPolicies),
            reduced_latency: reducedLatency ? '1' : '0',
            test_mode: testMode ? '1' : '0',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                var streamData = response.data || {};
                var streamId = streamData.id || (streamData.data && streamData.data.id) || 'Unknown';
                var playbackIds = streamData.playback_ids || (streamData.data && streamData.data.playback_ids) || [];
                var playbackId = playbackIds.length > 0 ? playbackIds[0].id : 'N/A';
                console.log('Stream created:', streamData);
                console.log('Playback IDs:', playbackIds);
                alert('Stream created successfully!\n\nStream ID: ' + streamId + '\nPlayback ID: ' + playbackId);
                // Clear form
                $('#lem-new-stream-name').val('');
                $('#lem-new-stream-playback-policy').val('public');
                $('#lem-new-stream-asset-policy').val('public');
                $('#lem-new-stream-reduced-latency').prop('checked', false);
                $('#lem-new-stream-test').prop('checked', false);
                // Clear cache and reload after a short delay to ensure cache is cleared
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                var errorMsg = response.data || 'Failed to create stream';
                if (typeof errorMsg === 'object' && errorMsg.message) {
                    errorMsg = errorMsg.message;
                }
                alert('Error: ' + errorMsg);
                btn.prop('disabled', false).text('Create Stream');
            }
        }).fail(function(xhr, status, error) {
            console.error('Create stream error:', xhr, status, error);
            alert('Network error: ' + error + '. Please check the browser console for details.');
            btn.prop('disabled', false).text('Create Stream');
        });
    });
    
    // Edit stream
    $(document).on('click', '.lem-edit-stream', function() {
        var streamId = $(this).data('stream-id');
        var streamName = $(this).data('stream-name');
        var reducedLatency = $(this).data('reduced-latency') === '1';
        
        console.log('Edit stream clicked:', { streamId: streamId, streamName: streamName });
        
        if (!streamId) {
            alert('Stream ID is missing. Please refresh the page and try again.');
            return;
        }
        
        $('#lem-edit-stream-id').val(streamId);
        $('#lem-edit-stream-name').val(streamName);
        $('#lem-edit-stream-reduced-latency').prop('checked', reducedLatency);
        
        $('#lem-edit-stream-modal').css('display', 'flex');
    });
    
    // Cancel edit
    $('.lem-cancel-edit').on('click', function() {
        $('#lem-edit-stream-modal').hide();
    });
    
    // Close modal on background click
    $('#lem-edit-stream-modal').on('click', function(e) {
        if ($(e.target).is('#lem-edit-stream-modal')) {
            $(this).hide();
        }
    });
    
    // Save edit
    $('#lem-edit-stream-form').on('submit', function(e) {
        e.preventDefault();
        
        var streamId = $('#lem-edit-stream-id').val();
        var name = $('#lem-edit-stream-name').val().trim();
        var reducedLatency = $('#lem-edit-stream-reduced-latency').is(':checked');
        
        console.log('Updating stream:', { streamId: streamId, name: name });
        
        if (!name) {
            alert('Please enter a stream name');
            return;
        }
        
        if (!streamId) {
            alert('Stream ID is missing. Please close and reopen the edit dialog.');
            return;
        }
        
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving...');
        
        $.post(ajaxUrl, {
            action: 'lem_update_stream',
            stream_id: streamId,
            passthrough: name,
            reduced_latency: reducedLatency ? '1' : '0',
            nonce: nonce
        }, function(response) {
            console.log('Update response:', response);
            if (response.success) {
                alert('Stream updated successfully!');
                location.reload();
            } else {
                var errorMsg = response.data || 'Failed to update stream';
                if (typeof errorMsg === 'object' && errorMsg.message) {
                    errorMsg = errorMsg.message;
                }
                alert('Error: ' + errorMsg);
                btn.prop('disabled', false).text('Save Changes');
            }
        }).fail(function(xhr, status, error) {
            alert('Network error: ' + error + '. Please try again.');
            btn.prop('disabled', false).text('Save Changes');
        });
    });
    
    // Delete stream
    $(document).on('click', '.lem-delete-stream', function() {
        var streamId = $(this).data('stream-id');
        var streamName = $(this).data('stream-name');
        
        console.log('Delete stream clicked:', { streamId: streamId, streamName: streamName });
        
        if (!streamId) {
            alert('Stream ID is missing. Please refresh the page and try again.');
            return;
        }
        
        if (!confirm('Are you sure you want to delete "' + streamName + '"?\n\nThis action cannot be undone.')) {
            return;
        }
        
        var btn = $(this);
        btn.prop('disabled', true).text('Deleting...');
        
        $.post(ajaxUrl, {
            action: 'lem_delete_stream',
            stream_id: streamId,
            nonce: nonce
        }, function(response) {
            console.log('Delete response:', response);
            if (response.success) {
                alert('Stream deleted successfully!');
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Failed to delete stream'));
                btn.prop('disabled', false).text('Delete');
            }
        }).fail(function() {
            alert('Network error. Please try again.');
            btn.prop('disabled', false).text('Delete');
        });
    });
    
    // Copy stream key
    $('.lem-copy-key').on('click', function() {
        var key = $(this).data('key');
        var $temp = $('<input>');
        $('body').append($temp);
        $temp.val(key).select();
        document.execCommand('copy');
        $temp.remove();
        
        var btn = $(this);
        var originalText = btn.text();
        btn.text('Copied!').addClass('button-primary');
        setTimeout(function() {
            btn.text(originalText).removeClass('button-primary');
        }, 2000);
    });
});
</script>

<style>
#lem-edit-stream-modal {
    display: none;
}

.lem-copy-key {
    margin-left: 5px;
}

/* Responsive adjustments for inline form */
@media (max-width: 1200px) {
    #lem-create-stream-form {
        flex-wrap: wrap;
    }
    
    #lem-create-stream-form > div:first-child {
        flex: 1 1 100%;
        min-width: 100%;
    }
}

@media (max-width: 782px) {
    #lem-create-stream-form {
        flex-direction: column;
        align-items: stretch !important;
    }
    
    #lem-create-stream-form > div {
        width: 100%;
        min-width: 100% !important;
    }
    
    #lem-create-stream-form button {
        width: 100%;
        margin-top: 10px;
    }
}
</style>
