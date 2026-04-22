# Live Event Manager

WordPress plugin for a **secure streaming paywall**: Stripe checkout, pluggable streaming (**Mux** or self-hosted **OvenMediaEngine**), JWT access control, and **Upstash Redis** (HTTP) for sessions—without requiring `phpredis`.

**Current release:** 1.1.0 (declared in `live-event-manager.php`).

## License

This project is licensed under the **GNU General Public License v2.0 or later**—the same license family as WordPress. See [`LICENSE`](LICENSE) for the full text.

Bundled libraries (e.g. **firebase/php-jwt**, **stripe/stripe-php**) remain under their respective licenses in `vendor/`.

## Features (overview)

- Entitlement-aware event pages, magic links, device rules, optional **Ably** chat
- **Mux** or **OME** as configurable streaming providers; vendor credentials under **Live Events → Vendors**
- Stripe Checkout + webhooks; **Payments** screen with export
- JWT issuance, **Access Tokens** / **Revoke access**, REST namespace `lem/v1` (including edge-oriented JWT checks)
- Template packs (ZIP install, `wp-content/lem-templates/`), blocks and `[simulcast_player]` shortcode
- Admin: streams, vendors, settings, devices, templates, user guide, debug tools

For operator-focused setup steps and UI walkthrough, see **Live Events → User Guide** in wp-admin and [`docs/user-guide.md`](docs/user-guide.md) (menu labels in the doc may occasionally lag the UI).

## Requirements

| Item | Notes |
|------|--------|
| WordPress | 5.0+ |
| PHP | 7.4+ (newer PHP recommended) |
| MySQL / MariaDB | 5.7+ compatible |
| Stripe | Account + Price IDs for paid events |
| Upstash Redis | REST URL + token for production session / magic-link behavior |
| Streaming | Credentials for **Mux** and/or **OME** per your active provider |
| Optional | **Ably** for live chat |

## Installation

1. Copy the plugin folder into `wp-content/plugins/` (or install from a release ZIP).
2. Activate **Live Event Manager** in **Plugins**.
3. Configure **Live Events → Settings** (Upstash, Stripe, active provider, optional Ably).
4. Configure **Live Events → Vendors** for Mux/OME as needed.
5. Create **Live Event** posts (`lem_event`), set free vs paid and Stripe price where applicable.

## Development

### Layout

| Path | Purpose |
|------|---------|
| `live-event-manager.php` | Bootstrap, main class, many hooks and AJAX handlers |
| `includes/` | Core helpers (cache, access, devices, templates, …) |
| `services/` | Magic links, streaming providers |
| `templates/` | Admin and front-end PHP templates |
| `template-packs/` | Bundled starter / premium-dark packs |
| `vendor/` | Composer dependencies (**committed** so a git clone works without running Composer) |

### PHP dependencies

```bash
composer install
```

`composer.json` declares **firebase/php-jwt** and **stripe/stripe-php**. After upgrading dependencies, run `composer update` locally and commit both `composer.lock` and `vendor/` if your workflow keeps vendors in git (as this repo does).

### Coding standards

Match existing WordPress APIs in the codebase: nonces for AJAX, capability checks for admin actions, prepared SQL where used, and escaping on output.

### Tests

Automated tests are not yet part of this repository. Contributions that add a minimal PHPUnit or WP-CLI test harness are welcome (see **Contributing**).

## Security

If you believe you have found a security vulnerability, please **do not** open a public issue with exploit details. Open a **[GitHub Security Advisory](https://github.com/tbobkergeneral/wp-live-event-manager/security/advisories/new)** (preferred) or contact the maintainers privately so a fix can be coordinated.

## Contributing

1. Open an **issue** first for larger changes (architecture, new providers, breaking behavior) so maintainers can align on direction.
2. Use **focused pull requests** with a clear description of user-visible behavior and any migration notes for site operators.
3. For WordPress.org–style plugins, avoid committing secrets; use local `.env` or wp-config constants only on your machine (`.env` is gitignored).

Bug reports and documentation improvements are always appreciated.

## Support & troubleshooting

1. **Live Events → Debug** — Stripe, Mux/OME, Upstash checks.
2. Enable `WP_DEBUG_LOG` and watch for `[LEM]` lines in `wp-content/debug.log`.
3. **Access Tokens** / **Revoke access** if playback should or should not be allowed.
4. Replay Stripe webhooks from the Stripe Dashboard if payment succeeded but access was not issued.

## REST API (short reference)

- Base: `wp-json/lem/v1/…` (most routes expect an authenticated admin with appropriate capabilities).
- `POST /lem/v1/check-jwt-status` — intended for edge revocation checks alongside signature validation.

---

**Disclaimer:** Third-party service names (Stripe, Mux, Upstash, Ably, OvenMediaEngine) are trademarks of their respective owners. This plugin is not affiliated with those vendors unless explicitly stated elsewhere by the project owners.
