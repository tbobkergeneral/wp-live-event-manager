# Live Event Manager

Secure, WordPress-native streaming paywall for live and on-demand events.  
Live Event Manager combines Stripe for checkout, Mux for playback, and JWT/Redis for access control while keeping the viewer workflow simple.

## Key Capabilities

- **Entitlement-aware event pages** – visitors with an active ticket see a “Watch Now” shortcut; others get purchase/resend options.
- **Stripe checkout + webhook pipeline** – paid events mint Mux-signed JWTs only after payment confirmation.
- **Mux playback security** – JWTs are scoped to the event’s playback ID and expire automatically.
- **Magic link + device swaps** – one-time links, device-change requests, and email/code resend UI on both confirmation and watch pages.
- **Redis or WP Object Cache support** – sessions and tokens are cached in Redis (phpredis or the Redis Object Cache drop-in); JWT fallback still works without Redis.
- **Admin tools** – settings pages for Stripe/Mux/Redis, JWT manager, device rules, and a detailed user guide.

## Requirements

- WordPress 5.0+, PHP 7.4+, MySQL 5.7+
- Stripe account with price IDs for paid events
- Mux account with signing key + playback restrictions
- Redis server (phpredis extension or Redis Object Cache plugin) for session storage

## Setup

1. **Install & activate** the plugin.
2. Open **Live Events → Settings** and provide Mux credentials, Stripe keys, and optional Redis details.
3. Create a new **Live Event** (custom post type) and:
   - Attach a Mux playback ID.
   - Choose the Stripe price (or mark the event free).
   - Publish the event.
4. Visit the event page to test entitlement detection:
   - Free events immediately issue a JWT and show the watch shortcut.
   - Paid events redirect to Stripe Checkout; on success the webhook emails the viewer a magic link.

More detail is available inside WordPress (`Live Events → User Guide`) or in `docs/user-guide.md`.

## Viewer Experience

- **Ticket purchase** – Event blocks validate the email, send viewers to Stripe if needed, and display status messages inline.
- **Auto watch shortcut** – After checkout (or when returning later), the event page calls `lem_check_event_access` to reveal the player inline—no dedicated watch page required.
- **Watch page** – Valid JWTs load the mux-player. If entitlement fails, the player hides and the resend form remains.
- **Magic link email** – Includes the one-time watch URL, the viewer’s 8-character access code, and instructions for resending the link.
- **Resend options** – Confirmation page, watch page, and event page all provide email/code forms that call `lem_regenerate_jwt`.

## Redis & Caching

- The plugin first tries the PHP Redis extension.  
- If it isn’t available, it automatically falls back to WordPress’ external object cache (e.g., Redis Object Cache plugin using Predis).  
- Disable Redis in settings only if you plan to rely exclusively on direct JWT links; magic links and session flows expect a cache layer.

## Admin Pages

- **Settings** – API credentials, Redis toggles, debug logging.
- **Playback Restrictions** – Manage Mux restriction lists.
- **JWT Manager** – List and revoke tokens, resend links.
- **Device Settings** – Configure identification rules and future fingerprinting.
- **Debug** – Run health checks, Redis tests, webhook diagnostics.
- **User Guide** – Full walkthrough for operators.

## Support Checklist

1. Use **Live Events → Debug** to verify Stripe, Mux, and Redis connectivity.
2. Check `wp-content/debug.log` (enable in `wp-config.php`) for `[LEM]` entries.
3. Inspect the **JWT Manager** to confirm tokens were minted.
4. Re-run Stripe webhook (Dashboard → Events) if payments aren’t issuing JWTs.

---

Built for production streaming workflows. Secure, Mux-backed access control with a viewer-friendly WordPress front end.