jQuery(document).ready(function($) {

    // Helper function to show messages.
    // type = 'success' | 'error'
    // persistent = true means the message stays until dismissed (no auto-fade).
    function showMessage(message, type, persistent) {
        type       = type       || 'success';
        persistent = persistent || false;

        var messageClass = type === 'success' ? 'lem-message-success' : 'lem-message-error';
        var messageHtml  = '<div class="lem-message ' + messageClass + '">' + message + '</div>';

        $('.lem-message').remove();

        // Try ticket block first; fall back to the watch-page chat header; last resort: body top.
        var target = $('.lem-ticket-sales-block, .lem-event-ticket-block').first();
        if (!target.length) target = $('.lem-chat-header');
        if (!target.length) target = $('body');

        if (target.is('body')) {
            // Fixed banner across top of screen.
            $(messageHtml).css({
                position: 'fixed', top: 0, left: 0, right: 0,
                zIndex: 99999, textAlign: 'center', padding: '10px'
            }).prependTo('body');
        } else {
            target.prepend(messageHtml);
        }

        if (!persistent) {
            setTimeout(function() { $('.lem-message').fadeOut(); }, 5000);
        }
    }
    
    $('.lem-form input[required]').on('blur', function() {
        var field = $(this);
        var value = field.val().trim();
        
        if (value === '') {
            field.addClass('lem-error');
            if (!field.next('.lem-error-message').length) {
                field.after('<div class="lem-error-message">This field is required.</div>');
            }
        } else {
            field.removeClass('lem-error');
            field.next('.lem-error-message').remove();
        }
    });
    
    $('.lem-form input[type="email"]').on('blur', function() {
        var field = $(this);
        var value = field.val().trim();
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        if (value !== '' && !emailRegex.test(value)) {
            field.addClass('lem-error');
            if (!field.next('.lem-error-message').length) {
                field.after('<div class="lem-error-message">Please enter a valid email address.</div>');
            }
        } else {
            field.removeClass('lem-error');
            field.next('.lem-error-message').remove();
        }
    });
    
    $('.lem-form').on('submit', function(e) {
        var form = $(this);
        var hasErrors = false;
        
        form.find('input[required]').each(function() {
            var field = $(this);
            var value = field.val().trim();
            
            if (value === '') {
                field.addClass('lem-error');
                if (!field.next('.lem-error-message').length) {
                    field.after('<div class="lem-error-message">This field is required.</div>');
                }
                hasErrors = true;
            }
        });
        
        form.find('input[type="email"]').each(function() {
            var field = $(this);
            var value = field.val().trim();
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (value !== '' && !emailRegex.test(value)) {
                field.addClass('lem-error');
                if (!field.next('.lem-error-message').length) {
                    field.after('<div class="lem-error-message">Please enter a valid email address.</div>');
                }
                hasErrors = true;
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
        }
    });
    
    // ============================================
    // Dark Theme Event Page Interactions
    // ============================================
    
    // Tab switching
    $('.lem-tab').on('click', function() {
        const tab = $(this).data('tab');
        $('.lem-tab').removeClass('lem-tab-active');
        $(this).addClass('lem-tab-active');
        $('.lem-tab-content').addClass('lem-hidden');
        $('#lem-' + tab + '-tab').removeClass('lem-hidden');
    });

    // ============================================
    // Ably Realtime Chat
    // ============================================

    /**
     * Initialises the Ably chat for the watch page.
     *
     * Requires:
     *   window.lemAblyEnabled   = true
     *   window.lemWatchHasAccess = true
     *   window.lemWatchEventId   = <int>
     *   window.lemViewerName     = <string>
     *   window.lemAblyAuthUrl    = admin-ajax.php URL
     *   window.lemNonce          = WP nonce
     *
     * Uses the existing .lem-chat-* HTML structure in single-event.php.
     */
    function initAblyChat() {
        if (!window.lemAblyEnabled)      return;
        if (!window.lemWatchHasAccess)   return;
        if (typeof Ably === 'undefined') return;

        var eventId     = window.lemWatchEventId;
        var viewerName  = window.lemViewerName || 'Viewer';
        var channelName = 'lem:chat:' + eventId;

        // Use authCallback so we can unwrap WP's {"success":true,"data":{...}} envelope.
        var ably = new Ably.Realtime({
            authCallback: function(tokenParams, callback) {
                $.post(window.lemAblyAuthUrl, {
                    action:   'lem_ably_token',
                    nonce:    window.lemNonce,
                    event_id: eventId
                }, function(response) {
                    if (response && response.success && response.data) {
                        callback(null, response.data);
                    } else {
                        callback(new Error((response && response.data) || 'Token request failed'));
                    }
                }, 'json').fail(function() {
                    callback(new Error('Token request network error'));
                });
            }
        });

        var channel = ably.channels.get(channelName, {
            params: { rewind: '2m' }
        });

        ably.connection.on('connected', function() {
            channel.presence.enter({ name: viewerName });
        });

        ably.connection.on('failed', function(stateChange) {
            console.warn('[LEM Chat] Ably connection failed:', stateChange.reason);
        });

        // Receive messages
        channel.subscribe('message', function(msg) {
            var d = msg.data || {};
            appendChatMessage(d.name || 'Viewer', d.text || '', d.ts || Date.now());
        });

        // Send on button click or Enter key
        $('#lem-chat-send').on('click', sendMessage);
        $('#lem-chat-input').on('keydown', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        function sendMessage() {
            var text = $('#lem-chat-input').val().trim();
            if (!text) return;

            channel.publish('message', {
                name: viewerName,
                text: text,
                ts:   Date.now()
            }, function(err) {
                if (err) console.warn('[LEM Chat] Publish error:', err);
            });

            $('#lem-chat-input').val('').focus();
        }

        function appendChatMessage(name, text, ts) {
            $('#lem-chat-empty').hide();

            var time = '';
            try {
                time = new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch (e) {}

            // Build DOM using jQuery text() so XSS is impossible
            var $msg     = $('<div class="lem-chat-message">');
            var $content = $('<div class="lem-chat-content">');
            var $meta    = $('<div style="display:flex;align-items:baseline;gap:0.4rem;margin-bottom:0.2rem;">');
            var $name    = $('<span class="lem-chat-name">').text(name);
            var $time    = $('<span class="lem-chat-time">').text(time);
            var $text    = $('<div class="lem-chat-text">').text(text);

            $meta.append($name, $time);
            $content.append($meta, $text);
            $msg.append($content);

            var $msgs = $('#lem-chat-messages');
            var isAtBottom = $msgs[0].scrollHeight - $msgs.scrollTop() - $msgs.outerHeight() < 60;
            $msgs.append($msg);
            if (isAtBottom) {
                $msgs.scrollTop($msgs[0].scrollHeight);
            }
        }
    }

    // Boot Ably chat after everything is ready
    if (window.lemAblyEnabled) {
        if (typeof Ably !== 'undefined') {
            initAblyChat();
        } else {
            // Ably SDK may still be loading — wait for it
            var ablyPollInterval = setInterval(function() {
                if (typeof Ably !== 'undefined') {
                    clearInterval(ablyPollInterval);
                    initAblyChat();
                }
            }, 100);
            // Give up after 10 s
            setTimeout(function() { clearInterval(ablyPollInterval); }, 10000);
        }
    }

    // Retry countdown
    if ($('#lem-retry-countdown').length) {
        let countdown = 59;
        const countdownInterval = setInterval(function() {
            countdown--;
            $('#lem-retry-countdown').text(countdown);
            if (countdown <= 0) {
                countdown = 59;
                // Trigger retry logic here - could check stream status
                // Trigger retry logic — could check stream status
            }
        }, 1000);
        
        // Clean up on page unload
        $(window).on('beforeunload', function() {
            clearInterval(countdownInterval);
        });
    }
});