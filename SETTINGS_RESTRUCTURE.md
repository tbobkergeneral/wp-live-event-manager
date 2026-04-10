# Settings Restructure Plan

## Current Structure Issues
- Settings page is too large with provider configs, Stripe, Redis all mixed
- Provider selection should be per-stream, not global
- WHIP publisher should be integrated into Stream Setup for Red5

## New Structure

### 1. Settings Page (`templates/settings-page.php`)
**Only contains:**
- Redis Configuration
- Stripe Configuration
- Webhook Documentation/URLs
- Debug Settings

### 2. Stream Vendors Page (`templates/stream-vendors-page.php`) - NEW
**Tabbed interface with:**
- **Mux Tab**: Mux API credentials (Key ID, Private Key, Token ID/Secret, Webhook Secret)
- **Red5 Tab**: Red5 credentials (Stream Manager, Conference Key/Secret, PubNub Keys)
- Both can be configured simultaneously (no "choose one")

### 3. Stream Manager Page (`templates/stream-management-page.php`)
**Updated to:**
- Ask for provider (Mux/Red5) when creating a new stream
- Use appropriate provider API for create/update/delete/list
- Show streams grouped by provider or with provider indicator

### 4. Stream Setup Page (`templates/stream-setup-page.php`)
**Updated to:**
- Select stream (shows Mux or Red5 streams based on which are configured)
- Show provider-specific details (RTMP for Mux, WHIP/WHEP for Red5)
- **Tabs for Red5**: RTMP Setup | WHIP Publisher
- **Tabs for Mux**: RTMP Switchboard | Simulcast Targets

### 5. Restrictions Page (`templates/restrictions-page.php`)
**Note**: Currently Mux-only. Will need Red5 implementation if Red5 has similar feature.

## Menu Structure
```
Live Events
├── All Events
├── Add New
├── User Guide
├── JWT Manager
├── Stream Vendors (NEW - for Mux/Red5 secrets)
├── Settings (Redis, Stripe, Webhooks)
├── Restrictions (Mux-only for now)
├── Device Settings
├── Stream Management (creates streams, asks for provider)
├── Stream Setup (configures existing streams, provider-aware)
└── System Debug
```
