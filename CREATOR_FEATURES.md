# Creator Features & Workflow

## What's New for Creators

### 1. ✅ Stream Setup Page
**Location:** Live Events → Stream Setup

A dedicated admin page for managing your live stream:

- **RTMP Switchboard**: Get your stream key and ingest URL with one-click copy buttons
- **OBS Setup Instructions**: Step-by-step guide for configuring OBS
- **Stream Status**: Real-time status indicator (active/idle)
- **Simulcast Management**: Add/remove YouTube, Twitch, or other RTMP targets

**How to Use:**
1. Go to **Live Events → Stream Setup**
2. Copy your RTMP credentials
3. Paste into OBS Settings → Stream
4. Add Simulcast targets to forward to YouTube/Twitch
5. Start streaming!

### 2. ✅ Per-Event Live Stream ID
**Location:** Event Edit Page → Event Details Meta Box

Each event can now have its own Live Stream ID, or use the global setting:

- **Event-specific**: Set `_lem_live_stream_id` in the event meta box
- **Global fallback**: Uses Settings → Mux Live Stream ID if not set
- **Quick access**: "Get RTMP Info & Setup Stream" button links to Stream Setup page

**Why This Matters:**
- Multiple events can use different Mux streams
- Better organization for creators with multiple channels
- Flexible setup for recurring events

### 3. ✅ Enhanced Event Template
**Location:** `templates/single-event.php`

Improved viewer experience:

- **Better button layout**: Responsive flex layout for action buttons
- **Creator tools section**: Quick links for logged-in creators
  - Stream Setup
  - Edit Event
  - View Public Page
- **Modern design**: Clean, professional appearance

### 4. ✅ Stream Setup Integration
The Stream Setup page can be accessed:
- From the admin menu: **Live Events → Stream Setup**
- From event meta box: "Get RTMP Info & Setup Stream" button
- With event context: `?event_id=123` parameter for event-specific setup

## Creator Workflow

### Creating a New Event

1. **Create Event**
   - Go to **Live Events → Add New**
   - Fill in title, description, featured image
   - Set event date & time

2. **Configure Stream**
   - **Mux Playback ID**: Required (for video player)
   - **Mux Live Stream ID**: Optional (for RTMP/Simulcast)
     - Leave empty to use global setting
     - Or set per-event for multiple streams
   - **Playback Restriction**: Optional (for domain restrictions)

3. **Set Pricing**
   - Choose Free or Paid
   - If Paid: Add Stripe Price ID and Display Price

4. **Publish**
   - Event page automatically renders ticket block
   - Viewers can reserve access

### Setting Up Your Stream

1. **Get RTMP Credentials**
   - Go to **Live Events → Stream Setup**
   - Copy Stream Key and Ingest URL
   - Or click "Get RTMP Info & Setup Stream" from event edit page

2. **Configure OBS**
   - Open OBS Settings → Stream
   - Service: Custom
   - Server: Paste Ingest URL
   - Stream Key: Paste Stream Key
   - Click OK

3. **Add Simulcast Targets (Optional)**
   - In Stream Setup page, scroll to "Simulcast Targets"
   - Add YouTube URL: `rtmp://a.rtmp.youtube.com/live2/[YOUR_KEY]`
   - Add Twitch URL: `rtmp://live.twitch.tv/app/[YOUR_KEY]`
   - Stream will automatically forward to all targets

4. **Start Streaming**
   - Click "Start Streaming" in OBS
   - Stream will appear on your event page
   - Also forwarded to Simulcast targets

### Managing Events

**View Event as Creator:**
- When logged in, event pages show "Creator Tools" section
- Quick access to Stream Setup, Edit, and View Public Page

**Monitor Stream Status:**
- Stream Setup page shows real-time status
- Active = Live and streaming
- Idle = Not streaming (VOD available if recorded)

**Manage Simulcast:**
- Add/remove targets from Stream Setup page
- Targets forward stream automatically when active
- No need to configure multiple OBS outputs

## Technical Details

### Live Stream ID Resolution
1. Check event meta `_lem_live_stream_id`
2. Fall back to global setting `mux_live_stream_id`
3. Use for RTMP info, stream status, and Simulcast

### REST API Endpoints Used
- `GET /lem/v1/rtmp-info` - Get RTMP credentials
- `GET /lem/v1/stream-status` - Get stream status
- `GET /lem/v1/simulcast-targets` - List targets
- `POST /lem/v1/simulcast-targets` - Create target
- `DELETE /lem/v1/simulcast-targets/{id}` - Delete target

### AJAX Handlers
- `lem_get_rtmp_info` - Fetch RTMP info
- `lem_create_simulcast_target` - Add Simulcast target
- `lem_delete_simulcast_target` - Remove Simulcast target

## Best Practices

1. **Use Per-Event Stream IDs** for:
   - Multiple concurrent events
   - Different stream configurations
   - Better organization

2. **Use Global Stream ID** for:
   - Single stream setup
   - Simpler configuration
   - Default stream

3. **Set Up Simulcast Before Going Live**:
   - Add targets before starting stream
   - Test with a short stream first
   - Monitor status in Stream Setup page

4. **Keep RTMP Credentials Secure**:
   - Don't share stream keys publicly
   - Regenerate if compromised
   - Use playback restrictions for extra security

## Troubleshooting

**Can't see RTMP info:**
- Check Mux API credentials in Settings
- Verify Live Stream ID is set
- Check Stream Setup page for errors

**Simulcast not working:**
- Verify RTMP URL format is correct
- Check target status in Stream Setup
- Ensure stream is active

**Stream status not updating:**
- Click "Refresh Status" button
- Check Mux API connection
- Verify stream is actually active in Mux dashboard

## Next Steps

The plugin now provides a complete creator workflow:
- ✅ Event creation with flexible stream configuration
- ✅ RTMP switchboard for OBS setup
- ✅ Simulcast management for multi-platform streaming
- ✅ Stream status monitoring
- ✅ Enhanced event templates

**Still To Do:**
- Frontend React app for `[simulcast_player]` shortcode
- Real-time chat integration
- Advanced analytics dashboard
