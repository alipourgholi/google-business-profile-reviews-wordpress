# Google credentials setup — Google Business Profile Reviews for WordPress 1.3.5

This guide connects one authorized Google Business Profile to your WordPress installation. You need an **OAuth Client ID and Client Secret**, not a Google Maps API key. Never put real secrets, downloaded credential JSON files, or tokens in this public repository.

## 1. Prepare your accounts

Use a Google account with owner or manager access to the Business Profile you want to display, and an administrator account on your WordPress site. Install and activate Google Business Profile Reviews for WordPress 1.3.5. Your site must support HTTPS and outgoing requests to Google.

Google requires approval for Business Profile API access. Its current prerequisites include managing a verified profile active for at least 60 days and having a website representing that business. Check the [official prerequisites](https://developers.google.com/my-business/content/prereqs) before applying; approval is not automatic.

## 2. Create a Google Cloud project and request access

1. Open [Google Cloud Console](https://console.cloud.google.com/).
2. Open the project selector, choose **New project**, give it a recognizable name, and create it.
3. Select that project. Record its **Project number** from the project dashboard (not just its name or Project ID).
4. Follow **Request access to the APIs** on the prerequisites page above. Open the linked GBP API contact form and select **Application for Basic API Access**.
5. Submit the requested information using an email listed as an owner or manager of the business. Wait for Google's review.

Creating an OAuth client does not by itself grant Business Profile API access. A zero API quota can indicate that approval is still missing. Keep all subsequent settings in the same approved project.

## 3. Enable the APIs

In that project, open **APIs & Services → Library**. Follow Google's [Business Profile basic setup](https://developers.google.com/my-business/content/basic-setup), including its current API enablement requirements.

The plugin directly uses these three services:

| API | Used for |
| --- | --- |
| My Business Account Management API | Listing accounts you can access |
| My Business Business Information API | Listing locations and reading business information |
| Google My Business API | Reading reviews through the v4 endpoint |

Search for each API and choose **Enable**. The Google My Business API may not be visible until access is approved. Google Maps/Places APIs do not replace these services for this plugin.

## 4. Configure the OAuth consent screen

Open **Google Auth Platform** (or **APIs & Services → OAuth consent screen**, depending on the Console layout). Complete the setup shown by Google:

1. In **Branding**, enter the app name, support email, and developer contact details. Use accurate website and privacy-policy details where requested.
2. In **Audience**, choose **External** if you use a personal Google account or authorize users outside your Workspace organization. **Internal** is only appropriate for an eligible organization and its users.
3. While testing an External app, add the Google account that manages the business to **Test users**.
4. In **Data Access**, add the scope `https://www.googleapis.com/auth/business.manage` when configuring requested scopes.

Google provides this management scope for the endpoints used here. Version 1.3.5 reads business information and reviews; it does not edit your listing or publish replies. Review Google's permission screen before granting access.

For a lasting production connection, complete Google's publishing and any applicable verification requirements. External apps left in **Testing** generally receive refresh tokens that expire after seven days for this scope. Changing to production does not replace API approval or automatically complete OAuth verification. Reconnect the plugin after completing the relevant changes. See [OAuth token expiration](https://developers.google.com/identity/protocols/oauth2#expiration) and [production readiness](https://developers.google.com/identity/protocols/oauth2/production-readiness/overview).

## 5. Copy your site's exact callback URL

In WordPress, open **Settings → Google Business Profile Reviews**. Under **Google Cloud credentials**, copy the value labeled **Exact authorized redirect URI**.

Example only:

```text
https://example.com/wp-admin/admin-post.php?action=aplr_callback
```

Always copy the URL displayed by your own installation. HTTPS, www/non-www, subdirectory, path, and query string must match exactly. Do not use the public home page or copy another website's callback URL.

## 6. Create the Client ID and Client Secret

1. In Google Auth Platform, open **Clients → Create client**. Alternatively use **APIs & Services → Credentials → Create credentials → OAuth client ID**.
2. Select **Web application** as the application type.
3. Give the client a recognizable name, such as `Reviews – production website`.
4. Under **Authorized redirect URIs**, add the exact callback copied in step 5.
5. This plugin's server-side callback does not require an Authorized JavaScript origin. Do not place the callback in the JavaScript origins field.
6. Create the client. Copy its **Client ID** and **Client Secret** using Google's displayed credential controls. Store the secret securely when it is shown. If it is no longer available, use the Console's supported secret replacement controls; do not guess the value.

Do not create an API key, Desktop app credential, or service-account key for these fields. Never share the Client Secret in a GitHub issue or screenshot. The Client ID commonly ends in `.apps.googleusercontent.com`; the secret is a different value.

## 7. Connect WordPress and select the business

1. Return to **Settings → Google Business Profile Reviews**.
2. Paste the Client ID into **Client ID** and the secret into **Client secret**.
3. Click **Save settings**. A blank secret field on later visits preserves the saved secret; it does not mean the secret is missing.
4. Click **Connect Google** (or **Reconnect Google** for an existing connection).
5. Sign in with the owner/manager account. Approve access for your own configured app.
6. Back in WordPress, click **Load my business profiles**, select the intended location from the returned options, and save the selection using the displayed controls.
7. Click **Sync now**. Confirm the selected profile and a recent successful sync time.

A connected account alone is not enough: a business must also be selected and successfully synchronized.

## 8. Display and verify reviews

Add a WordPress Shortcode block containing:

```text
[ap_google_business count="6" info="yes"]
```

For a compact rating badge:

```text
[ap_google_rating]
```

Open the published page and verify the business name and review content. Automatic synchronization uses WordPress scheduled events, so it depends on site traffic or a server cron setup. See [README.md](README.md) for caching behavior, styling, and the known 1.3.5 carousel limitation.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| `redirect_uri_mismatch` | Compare Google's authorized redirect URI against the exact URL displayed in WordPress, including the query string. |
| `invalid_client` | Check that both credentials belong to the same Web application client and that the secret was copied correctly. |
| Access blocked while testing | Add the signing-in account as a test user and check organization restrictions. Complete applicable verification requirements; do not bypass warnings for an unfamiliar app. |
| API missing, permission denied, or zero quota | Confirm approval for this project, API enablement, account access, and Workspace administrator policies. |
| Connection stops after about seven days | Check whether an External OAuth app is still in Testing. Complete production requirements and reconnect. |
| `invalid_grant` | Authorization may have expired or been revoked. Check consent/publishing state and reconnect. |
| No business profiles listed | Sign in with an account that actually owns/manages the desired location; confirm the correct project APIs are enabled. |
| Connected but no reviews | Select a location, run Sync now, confirm it has reviews, and inspect any displayed error. Check shortcode page rules and page caches. |

To remove the connection, use **Disconnect and remove cached data**. This revokes this site's token and clears its local cached Google data; it does not delete the Business Profile. Purge any full-page/CDN cache separately.

## Reference and scope

Guide checked against Google's documentation on 16 November 2026. Console labels and approval rules may change. The links above are authoritative for Google's requirements. This document describes configuration; it does not claim that a fresh Google project or WordPress installation was tested as part of this repository publication.
