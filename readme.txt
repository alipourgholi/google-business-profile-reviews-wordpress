=== Local Business Reviews by Ali Pourgholi ===
Contributors: alipourgholi
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.3.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display an authorized Google Business Profile with recent reviews, Google rating/count, phone, regular hours and Google profile/review links. Install the same ZIP on any HTTPS WordPress website; configure each installation independently. One selected business per installation. This is not a public Place-ID scraper and cannot read businesses your Google account does not manage.

== Installation ==
1. Plugins > Add New > Upload Plugin. Upload local-business-reviews-1.3.5.zip, install and activate.
2. Settings > Google Business Reviews. Use an approved Google Cloud project with GBP API access. Enable My Business Account Management API, My Business Business Information API and Google My Business API (v4 reviews).
3. Configure Google Auth Platform branding, audience and Data Access (business.manage). Add the authorizing account as a test user if in Testing. Testing refresh tokens can expire after seven days; set an appropriate production publishing status and complete any verification Google requests before unattended production use. API approval and OAuth app verification are separate processes.
4. Create an OAuth client of type Web application. Add EXACTLY the redirect URI shown in this plugin's settings. A Desktop client is unsuitable. Do not share one site's tokens with another site.
5. Save Client ID and Client secret. Connect Google with the business owner/manager account; approve access. Load profiles, select the matching business and Sync now.
6. Choose color preset, style, theme and heading. Insert a WordPress Shortcode block on any page. Works wherever the theme/builder executes WordPress shortcodes.

== Shortcodes ==
[ap_google_business]
[ap_google_business style="grid" theme="auto" count="6" info="yes"]
[ap_google_business style="minimal" theme="light" count="5"]
[ap_google_business style="spotlight" theme="dark" count="5"]
[ap_google_business style="carousel" theme="auto" count="9"]
[ap_google_business style="carousel" autoplay="yes" theme="auto" count="9"]
[ap_google_business style="wall" theme="auto" count="12"]
[ap_google_rating theme="dark"]

Use layout="carousel" with any style for a manual or automatic horizontal carousel. Example: [ap_google_business style="minimal" layout="carousel" autoplay="yes"].

Styles: grid, minimal, spotlight, carousel, wall. Theme: auto, light, dark. Count: 1–50 latest updated reviews, without rating filters. Compact rating badge displays the aggregate supplied by Google, not an average calculated from the visible cards. Auto follows OS appearance and common site .dark / .dark-mode / data-theme="dark" / data-color-scheme="dark" selectors. For themes with other selectors, choose light/dark explicitly or integrate scoped CSS variables. Manual carousel by default. Add autoplay="yes" to advance one card every 3 seconds, wrapping to the beginning. Pauses on mouse hover, touch/pointer hold, keyboard focus, hidden tab and when offscreen; resumes after the interaction ends. Known limitation in 1.3.5: the template does not render the Pause/Resume control supported by the script. Prefer autoplay="no" when a persistent pause control is required. Automatic movement is disabled for reduced-motion preferences. Slider supports buttons, touch and keyboard scrolling. Mobile responsive; reduced-motion respected.

== Cache / privacy / compatibility ==
PHP 7.4+ with OpenSSL AES-256-GCM, WordPress 6.2+, HTTPS and outbound HTTPS to Google required. No subscription, external font, frontend Google JavaScript or tracking added. Reviewer images may contact Google when loaded; adapt your privacy/consent policy as appropriate. No fake reviews or aggregate-rating schema is generated.

WP-Cron attempts sync every 15 minutes when the site has traffic. A server cron is recommended for quiet sites. Cache expires after 24 hours. A public same-origin AJAX endpoint renders only that fresh public cache; visitors never receive OAuth credentials and never trigger Google API calls. The initial HTML includes cached reviews. JavaScript refreshes them on load and every five minutes, and removes expired content. Fresh content is retained on transient request failures and tab switches. Configure your full-page cache TTL conservatively; without JavaScript, cached HTML can outlive the plugin cache. Allow admin-ajax.php action aplr_public through your firewall and exclude it from CDN caching. JavaScript is required for carousel controls and refresh/expiry handling. Without it, only the server-rendered snapshot is displayed. Search indexing is not guaranteed.

Tokens/secret are encrypted with a site-specific key derived from WordPress salts and URL. Do not clone live OAuth connections; disconnect/reconnect after migration or salt changes. Save a database backup before changes. Disconnect revokes the token and removes local Google data. Uninstall deletes local settings and data, including per-site records on multisite; disconnect first to revoke the Google grant remotely. Deactivation only stops this site's schedule. Site/network deletion does not delete the business at Google.

== Troubleshooting ==
403: verify project approval, enabled APIs and manager account.
429 / quota 0: project may still await GBP approval; do not submit duplicate requests.
redirect_uri_mismatch: match the exact HTTPS callback including action query.
No profiles: check the selected account and accepted business invitation.
No widget: connect/select/sync first, then allow local AJAX and JavaScript. Public visitors see no fake placeholder reviews.
Credentials cannot decrypt after migration: restore the original salts/domain or delete the aplr_vault option with trusted admin tooling and re-enter credentials; then reconnect.

== Validation / limits ==
Publication checks: source review and JavaScript syntax validation. PHP runtime, WordPress integration and live Google OAuth/API testing were not performed for this publication. Real Google OAuth and API verification still requires approved projects and actual Web OAuth clients. Production-site installation and live integration testing remain pending. Demo HTML is fictional, separate from the installable package, and never appears in production.

== Third-party services ==
Google authorization: accounts.google.com, oauth2.googleapis.com.
Google APIs: mybusinessaccountmanagement.googleapis.com, mybusinessbusinessinformation.googleapis.com, mybusiness.googleapis.com.
Purpose: read the authorized business profile and reviews; refresh/revoke access.
Scope: https://www.googleapis.com/auth/business.manage (Google does not provide a narrower review-read scope for these endpoints). Plugin uses GET for business operations and does not edit listings or publish replies.
Documentation: https://developers.google.com/my-business/
Google terms: https://developers.google.com/my-business/content/terms
Google privacy: https://policies.google.com/privacy

== 1.3.5 changes ==
Optional 3-second autoplay with interaction pause and accessible Pause/Resume control. Carousel timers and listeners are cleaned up when widgets refresh. 

== Page-specific reviews (1.3.5) ==
Settings > Reviews by page lists pages and posts, including drafts/private items for admin configuration only. Search and pagination are available. Configure each desired page: enable matching, review suggested service phrases from the title or enter custom synonyms (one per line), choose general fallback and optionally append the widget after main content. Automatic placement is OFF until explicitly selected. Existing shortcode blocks automatically use the current public page's enabled rule. Use related="no" to show business-wide reviews in a specific widget. No theme file edits are required.

The public AJAX endpoint only resolves rules for published, non-password-protected posts/pages. Matching uses whole phrases in the customer's original text (case insensitive, accents/hyphens normalized), never the business reply or star rating. It is a deterministic keyword system, not an AI service, and can miss ambiguous synonyms or context. Review the matches and manually Include/Exclude genuinely relevant reviews. Exclusion wins. Rules re-evaluate after each sync; new matching reviews can appear. Manual corrections are scoped to the connected business and apply only while that review remains in the fresh cache.

If no related reviews match and fallback is enabled, one written general review is selected in Google update order. The admin can designate a general review pool. Automatic fallback conservatively excludes known job/service words, configured page service phrases, and reviews without general-service language. It cannot guarantee semantic classification for arbitrary industries; review or curate the general pool. A fallback is clearly labeled "General feedback about this business". If no suitable fallback exists, the section is hidden. No reviews are fabricated or rewritten and no service-specific aggregate is calculated.

Sync fetches up to 10 pages / 500 recently updated reviews into the same expiring 24-hour performance cache. If more remain, an admin warning explains that matching is limited to the recent 500. Later-page errors preserve the previous complete cache; no half-downloaded set is published. Review text/photos are not copied into post metadata: page rules store only phrases, settings and opaque review identifiers. No visitor request triggers Google API calls. The general pool and manual corrections must be reviewed after selecting a different business.

== 1.3.5 changes ==
Add page/post rule manager, phrase matching, manual correction, optional after-content placement, general fallback and bounded review pagination. Existing styles, themes, carousel and 3-second autoplay retained. Google live OAuth/API and production-theme installation still await real approved credentials.

== 1.3.5 changes ==
Generic Red, Green and Blue preset labels; retain earlier color selections on upgrade.
