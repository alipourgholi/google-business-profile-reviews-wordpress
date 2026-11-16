# Google Business Profile Reviews for WordPress

WordPress plugin by **Ali Pourgholi** for displaying reviews and business information from a Google Business Profile you own or manage.

**Version 1.3.5 · WordPress 6.2+ · PHP 7.4+ · GPL-2.0-or-later**

[راهنمای فارسی](README-fa.md) · [Detailed reference](readme.txt)

This is an independent project, not an official Google plugin. It requires your own approved Google Cloud project and OAuth credentials. It does not scrape arbitrary businesses by Place ID. No credentials, business connection, or customer review dataset are bundled.

## Features

- Five layouts: grid, minimal, spotlight, carousel and wall.
- Light, dark and automatic themes; red, green and blue presets.
- Google-provided aggregate rating and review count, profile links and optional phone/hours.
- Optional three-second carousel autoplay with interaction pause and reduced-motion support. See the limitation below.
- Page-specific keyword matching, manual include/exclude and labeled general-review fallback.
- One selected business per WordPress installation; up to 500 recently updated reviews cached, with 1–50 cards per widget.
- Scheduled sync every 15 minutes via WP-Cron; 24-hour plugin cache lifetime.

## Installation

1. Download this repository using **Code → Download ZIP** and extract it.
2. Rename the extracted folder to `local-business-reviews`. Its root must contain `local-business-reviews.php`.
3. Upload that folder to `wp-content/plugins/`, or ZIP that folder and install through **Plugins → Add New → Upload Plugin**.
4. Activate **Google Business Profile Reviews for WordPress**.
5. Open **Settings → Google Business Profile Reviews**.

No Composer or npm build is required. Use HTTPS and a PHP installation with OpenSSL AES-256-GCM. Your server must be able to reach Google's HTTPS endpoints.

## Google connection

1. Follow Google's [prerequisites](https://developers.google.com/my-business/content/prereqs) and obtain Business Profile API access for your project.
2. Enable My Business Account Management API, My Business Business Information API, and Google My Business API for reviews. See [basic setup](https://developers.google.com/my-business/content/basic-setup).
3. Configure the OAuth consent screen and `https://www.googleapis.com/auth/business.manage` scope. Add your account as a test user when applicable.
4. Create a **Web application** OAuth client. Copy the exact HTTPS redirect URI shown in the plugin into the client's authorized redirect URIs.
5. Save the Client ID and client secret in WordPress. Click **Connect Google** and authorize the business owner/manager account.
6. Click **Load my business profiles**, select the correct profile, then **Sync now**.

API access approval and OAuth verification are separate. External OAuth apps in Testing generally receive seven-day refresh tokens for this scope; review Google's [OAuth documentation](https://developers.google.com/identity/protocols/oauth2/) before production use. The scope can permit management operations, although this plugin only reads business data and reviews; it does not edit listings or post replies.

## Display reviews

Add a Shortcode block to a page:

```text
[ap_google_business]
[ap_google_business style="carousel" autoplay="yes" count="9" theme="auto"]
[ap_google_business style="grid" count="6" info="yes"]
[ap_google_rating theme="light"]
```

| Attribute | Values / behavior |
| --- | --- |
| `style` | `grid`, `minimal`, `spotlight`, `carousel`, `wall` |
| `theme` | `auto`, `light`, `dark` |
| `count` | 1–50; default 6 |
| `layout` | `carousel` enables a slider for any style |
| `autoplay` | `yes` or `no`; applies to carousel layout |
| `info` | `yes` shows phone/hours outside carousel layout; default `no` |
| `related` | `auto` uses an enabled page rule; `no` displays business-wide reviews |

Appearance defaults come from the settings screen. Widget controls are currently English; review text supports automatic text direction. Automatic theme detection supports OS preferences and common dark-theme selectors, not every WordPress theme.

## Reviews by page

Open **Settings → Reviews by page**, choose a page/post, enable matching and add service phrases. Inspect matches before enabling optional automatic placement after content. Explicit exclusions override inclusion. Matching uses customer text, not replies or star ratings. It is keyword matching, not AI, and can miss context. Built-in suggestions focus on electrical/plumbing services; other industries can provide their own phrases.

Only public, published, non-password-protected pages are resolved by the public endpoint. The displayed rating remains the business-wide Google rating, even when cards are filtered by topic. If enabled, general fallback displays one clearly labeled suitable review; otherwise an empty result is hidden.

## Cache, privacy and limitations

The server renders the current cached snapshot into the page. JavaScript refreshes it on load and every five minutes, retaining fresh content during temporary failures and clearing expired data. Full-page/CDN caches can retain old HTML: use a conservative page-cache TTL and purge it after disconnecting. Without JavaScript, refresh/expiry and carousel controls do not run.

Allow same-origin `admin-ajax.php` requests with action `aplr_public` and exclude them from CDN caching. Visitors do not trigger Google API calls. WP-Cron depends on site traffic; use a server cron where appropriate.

Tokens and the client secret are encrypted in the WordPress database using a key derived from site salts and URL. Reconnect after migration or salt changes. Reviewer photos can make requests to Google; adapt your privacy disclosures and consent handling. The plugin does not generate review schema, fabricate reviews or filter by star rating. It provides no search-indexing guarantee.

Disconnect before uninstalling to revoke the Google grant. Deactivation stops scheduling; uninstall removes plugin settings, cached data and page rules, including multisite records. Never publish database dumps, OAuth secrets or tokens in issues.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| `redirect_uri_mismatch` | Exact Web-client callback URI, including HTTPS and query string |
| 403 | API approval, enabled APIs and manager access |
| 429 / zero quota | Project access approval or available quota |
| No profiles | Correct Google account and accepted manager invitation |
| No reviews | Profile selected, successful sync, fresh cache, AJAX allowed |
| Connection lost after migration | Re-enter credentials and reconnect; salts/URL affect encryption |

## Validation and development

For this publication, source review and JavaScript syntax validation were performed. PHP runtime checks, a fresh WordPress installation test and live Google OAuth/API testing were not performed. Test on staging with your own approved project before production use.

The executable behavior matches the supplied 1.3.5 package; only user-facing plugin names have been renamed. Documentation has been corrected. Known limitation: the carousel script supports a Pause/Resume button, but the PHP template does not render that button in 1.3.5. Use manual carousel mode (`autoplay="no"`, the default) when a persistent pause control is required. This publication does not update any existing production website.

Report reproducible non-sensitive bugs through GitHub Issues. Google API availability, eligibility and policies remain subject to Google. Review the [API policies](https://developers.google.com/my-business/content/policies), [terms](https://developers.google.com/my-business/content/terms) and [privacy policy](https://policies.google.com/privacy).

## License

Copyright Ali Pourgholi. Distributed under GPL version 2 or, at your option, any later version; see [LICENSE](LICENSE). Google names and marks belong to their respective owner and are not licensed by this project's GPL notice.

## Detailed Google credentials guide

See [GOOGLE-SETUP.md](GOOGLE-SETUP.md) for the English step-by-step guide to API approval, OAuth Client ID and Client Secret creation, callback configuration, and connection troubleshooting.
