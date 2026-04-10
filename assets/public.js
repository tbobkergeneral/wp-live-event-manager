jQuery(document).ready(function($) {
    
    // JWT Refresh System
    var jwtRefreshInterval = null;
    var currentJwt = null;
    var jwtExpiresAt = null;
    var refreshDurationMinutes = 15; // Default, will be updated from server
    
    // Initialize JWT refresh system
    function initJwtRefresh() {
        var isWatchContext = window.lemWatchContext === 'event' || window.location.pathname === '/watch' || window.location.pathname.startsWith('/watch/');

        if (!isWatchContext) {
            return;
        }

        if (typeof window.lemWatchHasAccess !== 'undefined' && window.lemWatchHasAccess === false) {
            return;
        }

        if (typeof window.lemInitialJwt !== 'undefined' && window.lemInitialJwt) {
            currentJwt = window.lemInitialJwt;
        }

        getJwtSettings();
    }
    
    // Get JWT settings from server
    function getJwtSettings() {
        $.ajax({
            url: '/wp-json/lem/v1/jwt-settings',
            type: 'GET',
            success: function(response) {
                if (response && response.jwt_refresh_duration_minutes) {
                    refreshDurationMinutes = response.jwt_refresh_duration_minutes;
                    console.log('JWT settings loaded:', response);
                    startJwtRefresh();
                } else {
                    console.error('Failed to load JWT settings');
                    startJwtRefresh(); // Fallback to defaults
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading JWT settings:', error);
                startJwtRefresh(); // Fallback to defaults
            }
        });
    }
    
    // Start JWT refresh cycle
    function startJwtRefresh() {
        if (jwtRefreshInterval) {
            clearInterval(jwtRefreshInterval);
        }
        refreshJwt();
    }

    function setSessionCookie(sessionId) {
        if (!sessionId) {
            return;
        }
        var secureFlag = window.location.protocol === 'https:' ? ';secure' : '';
        var maxAge = 60 * 60 * 24; // 24 hours
        document.cookie = 'lem_session_id=' + sessionId + ';path=/;max-age=' + maxAge + ';samesite=Lax' + secureFlag;
    }
    
    // Refresh JWT token
    function refreshJwt() {
        $.ajax({
            url: lem_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lem_refresh_jwt',
                nonce: lem_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    currentJwt = response.data.jwt;
                    jwtExpiresAt = response.data.expires_at;
                    refreshDurationMinutes = response.data.refresh_duration_minutes || 15;
                    
                    updateMuxPlayerJwt(currentJwt);
                    
                    var refreshIntervalMinutes = response.data.refresh_in_minutes || (refreshDurationMinutes - 1);
                    var refreshIntervalMs = refreshIntervalMinutes * 60 * 1000;
                    
                    if (jwtRefreshInterval) {
                        clearInterval(jwtRefreshInterval);
                    }
                    jwtRefreshInterval = setInterval(refreshJwt, refreshIntervalMs);
                    
                    console.log('JWT refreshed successfully. Next refresh in ' + refreshIntervalMinutes + ' minutes.');
                } else {
                    console.error('JWT refresh failed:', response.data);
                    handleJwtRefreshFailure();
                }
            },
            error: function(xhr, status, error) {
                console.error('JWT refresh error:', error);
                handleJwtRefreshFailure();
            }
        });
    }
    
    // Update Mux player with new JWT
    function updateMuxPlayerJwt(newJwt) {
        var muxPlayer = document.querySelector('mux-player');
        if (muxPlayer && newJwt) {
            muxPlayer.setAttribute('playback-token', newJwt);
            muxPlayer.setAttribute('token', newJwt);
            console.log('Mux player JWT updated');
        }
    }
    
    // Handle JWT refresh failure
    function handleJwtRefreshFailure() {
        if (jwtRefreshInterval) {
            clearInterval(jwtRefreshInterval);
            jwtRefreshInterval = null;
        }
        
        showMessage('Your session has expired. Please refresh the page or request a new access link.', 'error');
        
        setTimeout(function() {
            if (window.lemWatchContext === 'event') {
                window.location.reload();
            } else {
                window.location.href = '/events';
            }
        }, 5000);
    }
    
    // Clean up on page unload
    $(window).on('beforeunload', function() {
        if (jwtRefreshInterval) {
            clearInterval(jwtRefreshInterval);
        }
    });
    
    // Helper function to show messages
    function showMessage(message, type = 'success') {
        var messageClass = type === 'success' ? 'lem-message-success' : 'lem-message-error';
        var messageHtml = '<div class="lem-message ' + messageClass + '">' + message + '</div>';
        
        $('.lem-message').remove();
        var target = $('.lem-ticket-sales-block');
        if (!target.length) {
            target = $('.lem-event-ticket-block').first();
        }
        if (target.length) {
            target.prepend(messageHtml);
        }
        
        setTimeout(function() {
            $('.lem-message').fadeOut();
        }, 5000);
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
    
    initJwtRefresh();
    
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

    // Retry countdown
    if ($('#lem-retry-countdown').length) {
        let countdown = 59;
        const countdownInterval = setInterval(function() {
            countdown--;
            $('#lem-retry-countdown').text(countdown);
            if (countdown <= 0) {
                countdown = 59;
                // Trigger retry logic here - could check stream status
                console.log('Retrying stream connection...');
            }
        }, 1000);
        
        // Clean up on page unload
        $(window).on('beforeunload', function() {
            clearInterval(countdownInterval);
        });
    }
});