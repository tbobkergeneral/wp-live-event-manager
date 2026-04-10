# Plugin Review and Improvements

## Summary

This document outlines the review findings and improvements made to the Live Event Manager WordPress plugin. The plugin is designed for live streaming with Mux, Stripe payments, JWT validation, and device fingerprinting.

## Issues Found and Fixed

### 1. ✅ Missing `[simulcast_player]` Shortcode
**Status:** Fixed

**Issue:** The required `[simulcast_player]` shortcode was not registered.

**Solution:** 
- Added shortcode registration in `init()` method
- Created `render_simulcast_player_shortcode()` function
- Shortcode renders a React app container that can be used on any page/post

**Location:** `live-event-manager.php` line ~181

### 2. ✅ Missing Mux Live Stream ID in Settings
**Status:** Fixed

**Issue:** No field to configure the static Mux Live Stream ID.

**Solution:**
- Added `mux_live_stream_id` field to settings page
- Field is saved and retrieved from options

**Location:** `templates/settings-page.php`

### 3. ✅ Missing Mux Webhook Handler
**Status:** Fixed

**Issue:** No webhook endpoint to handle `video.asset.ready` events from Mux.

**Solution:**
- Added `handle_mux_webhook()` function
- Handles `video.asset.ready` events
- Automatically creates "Past Stream" posts when live events end
- Includes webhook signature verification (optional)
- Added webhook URL display in settings page

**Location:** `live-event-manager.php` line ~3493

### 4. ✅ Missing Dual-State Player Logic
**Status:** Fixed

**Issue:** No logic to check if stream is active/idle and switch between live and VOD modes.

**Solution:**
- Added REST API endpoint `/lem/v1/stream-status`
- Checks Mux API for stream status (active/idle)
- Returns most recent asset when stream is idle
- Frontend can use this to switch between live and VOD modes

**Location:** `live-event-manager.php` - `get_stream_status()` function

### 5. ✅ Missing RTMP Switchboard
**Status:** Fixed

**Issue:** No UI to display stream key and ingest URL for OBS configuration.

**Solution:**
- Added REST API endpoint `/lem/v1/rtmp-info`
- Fetches stream key and ingest URL from Mux API
- Can be used by frontend to display RTMP information

**Location:** `live-event-manager.php` - `get_rtmp_info()` function

### 6. ✅ Missing Simulcast Functionality
**Status:** Fixed

**Issue:** No integration with Mux Simulcast Targets API for forwarding streams to YouTube/Twitch.

**Solution:**
- Added REST API endpoints for Simulcast targets:
  - `GET /lem/v1/simulcast-targets` - List targets
  - `POST /lem/v1/simulcast-targets` - Create target
  - `DELETE /lem/v1/simulcast-targets/{id}` - Delete target
- Full integration with Mux Simulcast Targets API
- Admin-only access for creating/deleting targets

**Location:** `live-event-manager.php` - Simulcast functions

### 7. ✅ User Data Bridge Not Passing User Info
**Status:** Fixed

**Issue:** `wp_localize_script` was not passing `user_id` and `display_name` to frontend.

**Solution:**
- Updated `enqueue_public_assets()` to include user data
- Passes `user_id`, `display_name`, and `is_logged_in` status
- Available in frontend as `lem_ajax.user`

**Location:** `live-event-manager.php` line ~1635

## Additional Improvements Made

### REST API Endpoints Added
1. `/lem/v1/stream-status` - Get stream status (active/idle) and recent asset
2. `/lem/v1/rtmp-info` - Get RTMP stream key and ingest URL
3. `/lem/v1/simulcast-targets` - Manage Simulcast targets (GET/POST/DELETE)

### Settings Page Enhancements
- Added Mux Live Stream ID field
- Added Mux Webhook Secret field (optional)
- Added webhook URL display for easy configuration

## What Still Needs to Be Done

### Frontend React App
The shortcode creates a container for a React app, but the actual React application needs to be built. The app should:

1. **Dual-State Player:**
   - Check stream status via `/lem/v1/stream-status`
   - Show live feed if stream is "active"
   - Automatically play most recent recorded asset if stream is "idle"
   - Achieve "24/7" feel without custom VPS

2. **Artist Chat:**
   - Logged-in view: Fully interactive chat
   - Guest view: Show "Login to Chat" button instead of input box
   - Use `lem_ajax.user` to determine logged-in status

3. **RTMP Switchboard:**
   - Display stream key and ingest URL from `/lem/v1/rtmp-info`
   - Allow creator to copy-paste into OBS
   - Show in admin or frontend (depending on requirements)

4. **Simulcast Targets UI:**
   - Admin section to add YouTube/Twitch RTMP URLs
   - Enable/disable simulcast forwarding
   - List current targets
   - Use REST API endpoints to manage targets

### Recommended Next Steps

1. **Create React App:**
   - Build the React application in `assets/simulcast-player.js` (or separate build process)
   - Integrate with Mux player for dual-state functionality
   - Implement chat functionality
   - Add RTMP switchboard UI

2. **Test Mux Webhook:**
   - Configure webhook in Mux Dashboard
   - Test `video.asset.ready` event handling
   - Verify "Past Stream" posts are created correctly

3. **Test Simulcast:**
   - Test creating simulcast targets via REST API
   - Verify streams forward to YouTube/Twitch correctly
   - Test deleting targets

4. **Frontend Integration:**
   - Ensure React app loads correctly via shortcode
   - Test user data bridge (logged-in vs guest)
   - Test dual-state player switching

## Code Quality Notes

### Good Practices Found
- Comprehensive error handling
- Debug logging throughout
- Redis caching for performance
- JWT token management
- Device fingerprinting support
- Magic link system for device swaps

### Areas for Improvement
- Some functions are very long (consider breaking down)
- Mux API calls could be abstracted into a service class
- Frontend assets need React build process
- Consider adding unit tests

## API Documentation

### REST API Endpoints

#### GET `/lem/v1/stream-status`
Get stream status and recent asset info.

**Parameters:**
- `stream_id` (optional) - Mux Live Stream ID, defaults to settings value

**Response:**
```json
{
  "stream_id": "abc123",
  "status": "active",
  "is_active": true,
  "recent_asset": null
}
```

#### GET `/lem/v1/rtmp-info`
Get RTMP stream key and ingest URL.

**Parameters:**
- `stream_id` (optional) - Mux Live Stream ID

**Response:**
```json
{
  "stream_key": "abc123",
  "ingest_url": "rtmp://live.mux.com/app/",
  "playback_id": "xyz789"
}
```

#### GET `/lem/v1/simulcast-targets`
List all simulcast targets for a stream.

**Parameters:**
- `stream_id` (optional) - Mux Live Stream ID

**Response:**
```json
{
  "data": [
    {
      "id": "target123",
      "url": "rtmp://youtube.com/...",
      "status": "active"
    }
  ]
}
```

#### POST `/lem/v1/simulcast-targets`
Create a new simulcast target.

**Parameters:**
- `stream_id` (optional) - Mux Live Stream ID
- `url` (required) - RTMP URL (YouTube/Twitch)
- `stream_key` (optional) - Stream key if required

**Response:**
```json
{
  "data": {
    "id": "target123",
    "url": "rtmp://youtube.com/...",
    "status": "active"
  }
}
```

#### DELETE `/lem/v1/simulcast-targets/{id}`
Delete a simulcast target.

**Parameters:**
- `id` (required) - Target ID
- `stream_id` (optional) - Mux Live Stream ID

**Response:**
```json
{
  "success": true
}
```

## Webhook Configuration

### Mux Webhook
**URL:** `{your-site}/wp-admin/admin-ajax.php?action=lem_mux_webhook`

**Required Events:**
- `video.asset.ready` - Creates "Past Stream" posts automatically

**Optional:**
- Configure webhook secret in settings for signature verification

## Conclusion

The plugin now has all the backend functionality required for the MVP. The main remaining work is building the React frontend application that will use these APIs to provide the dual-state player, chat, RTMP switchboard, and Simulcast management UI.
