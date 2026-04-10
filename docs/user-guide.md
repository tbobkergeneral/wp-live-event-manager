# Live Event Manager – User Guide

## Overview
Secure WordPress streaming paywall that combines Stripe checkout, Mux streaming, and JWT/Redis access control. Event pages automatically surface a "Watch Now" button when a viewer already holds an active ticket.

## Quick Start

### 1. Initial Setup
1. **Activate the plugin** in WordPress Admin → Plugins
2. **Configure Stream Vendors** (Live Events → Stream Vendors)
   - Enter Mux API credentials (Token ID, Token Secret, Signing Key ID, Private Key)
   - Note: Additional streaming providers can be added in the future
3. **Configure Settings** (Live Events → Settings)
   - **Stripe**: Enter publishable/secret keys and webhook secrets (test & live)
   - **Redis**: Enable and configure Redis connection (host, port, password, database)
   - **Webhooks**: Copy webhook URLs for Stripe and Mux configuration
4. **Test Connections**: Use the Redis test button to verify connectivity

### 2. Create Your First Stream
1. Go to **Live Events → Stream Management**
2. Enter a **Stream Name** (e.g., "Main Event Stream")
3. Optionally enable **Reduced Latency** or **Test Mode**
4. Click **Create Stream**
5. The stream will appear in your streams list

### 3. Create Your First Event
1. Go to **Live Events → Add New**
2. Fill in **Title**, **Description**, and **Featured Image**
3. In **Event Details**:
   - **Select Stream**: Choose from dropdown (shows streams from both providers)
   - **Playback ID**: Auto-populated from selected stream
   - **Live Stream ID**: Auto-populated from selected stream
   - **Event Date & Time**: Set when the event occurs
   - **Event Type**: Choose Free or Paid
   - **Stripe Price ID**: Required for paid events
4. **Publish** the event

## Complete Workflow

### Step 1: Configure Streaming Providers

#### Mux Setup
1. Go to **Live Events → Stream Vendors → Mux Tab**
2. Enter your Mux credentials:
   - **Mux Signing Key ID**: From Mux Dashboard → Settings → Signing Keys
   - **Mux Private Key (Base64)**: Your signing private key
   - **Mux API Token ID**: For API access
   - **Mux API Token Secret**: For API access
   - **Mux Webhook Secret**: Optional, for webhook verification
3. Save settings

### Step 2: Create Streams

#### Using Stream Manager
1. Go to **Live Events → Stream Management**
2. **Enter Stream Name**: A friendly name for your stream
3. **Options**:
   - **Reduced Latency**: Enable for lower latency
   - **Test Mode**: Create as test stream
4. Click **Create Stream**
5. Stream appears in list with Mux badge

**Note**: 
- **Mux streams** are created via Mux API and include playback IDs
- Streams are automatically configured with public playback policies

### Step 3: Create Events

1. **Navigate to Events → Add New**
2. **Basic Information**:
   - Title, description, featured image
   - Event date & time
3. **Stream Configuration**:
   - **Select Stream**: Dropdown shows all Mux streams
   - **Playback ID**: Auto-filled from selected stream
   - **Live Stream ID**: Auto-filled from selected stream
4. **Pricing**:
   - **Free Event**: No payment required
   - **Paid Event**: Requires Stripe Price ID and Display Price
5. **Publish** the event

### Step 4: Publish Your Stream

1. Go to **Live Events → Publisher**
2. **Select Event** from dropdown
3. View **RTMP Publishing** section:
   - **Stream Key**: Copy to clipboard
   - **Ingest URL**: Copy to clipboard
4. **Configure OBS**:
   - Settings → Stream
   - Service: Custom
   - Server: Paste Ingest URL
   - Stream Key: Paste Stream Key
5. **Add Simulcast Targets** (optional):
   - Go to **Stream Setup** page
   - Add YouTube/Twitch RTMP URLs
   - Stream forwards automatically when active

### Step 5: Manage Streams

#### Stream Management Page
- **Create**: New streams with provider selection
- **Edit**: Update stream names and settings
- **Delete**: Remove streams via Mux API
- **View**: All streams with provider indicators

#### Stream Setup Page
- **Select Stream**: Choose from Mux streams
- **Mux Streams**: Show RTMP switchboard and Simulcast targets

## Admin Pages Reference

### Core Pages
- **All Events**: List and manage all live events
- **Add New**: Create new events
- **User Guide**: This comprehensive guide

### Configuration Pages
- **Stream Vendors**: Configure Mux credentials (can be extended for additional providers)
- **Settings**: Redis, Stripe, webhook documentation, debug settings
- **Stream Management**: Create, edit, delete Mux streams
- **Stream Setup**: Configure existing streams (RTMP switchboard and Simulcast targets)

### Management Pages
- **Publisher**: Select event → view provider-specific publish instructions
- **JWT Manager**: View, revoke tokens, resend magic links
- **Restrictions**: Manage Mux playback restrictions
- **Device Settings**: Configure device identification
- **System Debug**: Health checks, Redis tests, diagnostics

## Viewer Experience

### Free Events
1. Visitor enters email on event page
2. JWT generated immediately
3. Magic link sent via email
4. Click link → Watch page with player

### Paid Events
1. Visitor enters email on event page
2. Redirected to Stripe Checkout
3. After payment, webhook processes:
   - JWT generated
   - Magic link sent via email
4. Click link → Watch page with player

### Access Features
- **Magic Links**: One-time access URLs with 8-character code
- **Resend Links**: Request new link using email + code
- **Device Swap**: Change devices if needed
- **Session Persistence**: Redis-backed session management

## Features

### Mux Integration
- **RTMP Publishing**: Standard RTMP for OBS/Streamlabs
- **Simulcast Targets**: Forward to YouTube, Twitch automatically
- **Playback Restrictions**: Domain/IP restrictions
- **Asset Recording**: Automatic VOD creation
- **Dual-State Player**: Live mode + VOD fallback
- **Stream Management**: Create, edit, delete streams via API

## Best Practices

### Stream Management
1. **Use descriptive stream names** for easy identification
2. **Create streams before events** for better organization
3. **Test streams** before going live
4. **Keep credentials secure** - never share publicly

### Event Creation
1. **Select streams from dropdown** to auto-fill details
2. **Use per-event streams** for multiple concurrent events
3. **Set event dates** for scheduling
4. **Test free events** before creating paid ones

### Publishing
1. **Use Publisher page** for provider-specific instructions
2. **Test connection** before going live
3. **Set up Simulcast** (Mux) before starting stream
4. **Monitor stream status** in Stream Setup page

## Troubleshooting

### Authentication Issues
- **403 Forbidden**: Check Stream Manager URL, username, password
- **401 Unauthorized**: Verify credentials in Stream Vendors
- **Check debug log**: `wp-content/debug.log` for detailed errors

### Stream Creation Issues
- **Mux**: Verify API Token ID/Secret in Stream Vendors
- Ensure Mux is configured before creating streams

### Publishing Issues
- **Mux RTMP**: Verify stream is active, check credentials
- Use Publisher page for correct RTMP URLs

### Event Access Issues
- **JWT not working**: Check JWT Manager, verify token not revoked
- **Magic link expired**: Request new link using email + code
- **Redis issues**: Test connection in Settings page

## Technical Details

### Stream ID Resolution
1. Event meta `_lem_live_stream_id` (if set)
2. Provider-specific default (if configured)
3. Used for publishing and playback

### API Endpoints
- **Mux**: Uses Mux Video API v1
- Authenticated via Basic Auth (Token ID/Secret)

## Support

When reporting issues, include:
- Steps to reproduce
- Relevant URLs and event IDs
- Debug log excerpts (`wp-content/debug.log`)
- Mux API configuration status
