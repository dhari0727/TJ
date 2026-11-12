# Connecting social accounts - setup guide

JourneyAI lets members share what they make in three tiers. Only the first two work without any action from the site owner.

| Tier | What | Setup needed |
|---|---|---|
| 1 | Share sheet: phone share (with the photo), links for WhatsApp, Facebook, X, LinkedIn, Bluesky, Pinterest, Telegram, Reddit, email, Copy link, Copy caption, Download image | None |
| 2 | Direct posting to **Bluesky** and **Mastodon** | None |
| 3 | Direct posting to **Facebook Page, Instagram, X, LinkedIn, Pinterest, Threads** (Instagram has its own section below) | You register an app with each service and enter its ID and secret in **Admin > Social apps** |

Until a Tier 3 service has both values saved, members see "Not set up by the site owner" and nothing is attempted.

## Rules the site enforces

- Only items that are **public and approved by moderation** can be shared or posted. This is checked again on the server at the moment of posting.
- Every post needs the member to press **Post now** after seeing the exact text and photos. There is no scheduling, no auto-posting and no cross-posting without ticking each account.
- A member can only post their own items, to their own accounts.
- Photos are re-encoded before they are sent, which removes EXIF and GPS data.
- Limits: 20 posts an hour per member, 10 an hour per member per service.
- Every post and disconnect is written to the audit log (never with tokens).

## One-time database setup

```
C:\xampp\mysql\bin\mysql.exe -u root project < sql/social.sql
```

The first time anything is encrypted, `private/social.key` is created (32 random bytes). `private/` is blocked from the web by `.htaccess`, and the key is in `.gitignore`. **Back this file up with your database.** If you lose it, members just have to reconnect their accounts.

## Redirect URI

Services that sign members in with OAuth (Mastodon, and all Tier 3 services) send the browser back to:

- Development: `http://localhost/travel_journel/social-callback.php`
- Production: `https://YOUR-DOMAIN/social-callback.php` (use the exact address the site is served from; set the `site_url` setting so the site builds the same address)

It must match **exactly**, character for character, including `http` vs `https`. Admin > Social apps shows the address the site is using.

## Tier 2 (no approval)

### Bluesky
Members connect with their handle and an **app password**: Bluesky > Settings > Privacy and security > App passwords > Add. The app password is used once to sign in and is not stored. Only the session tokens are kept, encrypted. Limits: 300 characters, up to 4 photos (each under 1 MB, resized automatically), alt text supported. Free.

### Mastodon
Members type their server (for example `mastodon.social`). The site registers itself on that server automatically and the member approves the `write:statuses write:media` permissions there. Limits are read from the server (usually 500 characters and 4 photos). Visibility can be Public or Unlisted. Free.

## Tier 3 (you register the apps)

Enter the values in **Admin > Social apps**. They are stored encrypted and never displayed again; the row just says "Set".

### Facebook Page (Meta app)
1. Go to developers.facebook.com > My Apps > Create App (type: Business).
2. Add the **Facebook Login for Business** product. Under Valid OAuth Redirect URIs add the redirect URI above.
3. Copy **App ID** and **App Secret** (Settings > Basic) into the "Facebook Page" row.
4. Permissions: `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`.
5. **Review:** while the app is in Development mode only people listed as app roles can connect. For everyone else, submit App Review for each permission (a screencast of the feature is required) and complete Business Verification.
6. Facebook posts go to a **Page** the member manages. Meta does not allow third-party posting to personal profiles.
7. Instagram no longer uses this app: see the Instagram section below.
8. Cost: free.

### Instagram

Two separate things, and only the second needs you:

**1. Instagram Kit (works today, no setup, no API).** On any public, approved journal, storybook, community photo, a trip plan with a share link, or a camera photo you made a public copy of, the owner presses **Share > Make Instagram images and caption**. The site draws real JPEGs (Feed 4:5 at 1080x1350, Square 1080x1080, Story 9:16 at 1080x1920, and a Carousel of up to 10 slides: cover, photos, a journey card, a quote, and a closing "Plan a trip like this" slide), with the key text kept inside the area Instagram does not crop or cover (a safe-area overlay can be shown). Cards use only saved data: place, days and cost come from the journal or plan, quotes are sentences from the member's own text. It also writes a caption (first line fits the 125 characters Instagram shows before "more", 2,200 maximum), 3 to 5 hashtags built from the place and tags already used in the community, and alt text. Images are re-encoded JPEGs with no EXIF or GPS. On a phone the Web Share button hands the image files to the share sheet and Instagram can be picked there; on a desktop the images download (a zip for carousels). **Instagram takes the images only; the caption must be pasted in the app**, so the Kit has a Copy caption button first. Stories cannot carry a caption, and stickers or a link sticker are added inside the Instagram app.

**2. Direct posting from the site (needs you and a public https site).** Uses **Instagram API with Instagram Login** (Instagram Business Login). The member logs in on instagram.com; the site never asks for or stores an Instagram username or password, only the OAuth token (encrypted). **No Facebook Page is needed.** The Instagram account must be a **Business or Creator** account.

1. developers.facebook.com > My Apps > Create App, type **Business**.
2. Add the product **Instagram** and open **API setup with Instagram login**.
3. Copy the **Instagram app ID** and **Instagram app secret** into the "Instagram" row of **Admin > Social apps**. They are not the Facebook App ID and Secret.
4. Under **Set up Instagram business login**, add the redirect URI (`https://YOUR-DOMAIN/social-callback.php`, exact match). Instagram requires https for live use. **Unverified:** whether Instagram accepts an `http://localhost` redirect URI for local testing. This was not tested against the real service; the code is only tested against a local mock.
5. Permissions requested: `instagram_business_basic`, `instagram_business_content_publish`.
6. **No App Review for your own account:** while the app is in Development mode, add the Instagram account as an **Instagram Tester** (App roles > Roles > Add People > Instagram Tester) and accept the invite in the Instagram app (Settings > Website permissions > Apps and websites > Tester invites). Other members can only log in after App Review (and Business Verification) for the two permissions.
7. Set the `site_url` setting to the public https address. Instagram **downloads each image from your site**, so a site on localhost or plain http cannot publish; the site refuses with a plain explanation instead of failing.
8. Members use **Profile > Connected accounts > Log in with Instagram**, and **Test connection** reports who Instagram says the sign-in belongs to.

How it works: the site creates a media container (`POST /<ig-user-id>/media` with `image_url`, `caption`, `alt_text`; carousels create one child container per slide with `is_carousel_item`, then a `CAROUSEL` container), polls `status_code` until `FINISHED`, then calls `/media_publish`. The images are staged for a few minutes behind `ig-media.php?t=<token>`: random 256-bit token, one image per token, only images a member just confirmed, expires after 15 minutes, deleted right after the post, nothing else is served and nothing is listed. The 60 day token refreshes itself when used within 10 days of expiry (Instagram refuses tokens younger than 24 hours or already expired; expired tokens show "Needs reconnecting"). The site stops at 25 API posts per account per 24 hours (Meta limits API-published posts per day and the number has changed over time; check current docs). Photos and photo carousels only; Reels and Stories are not published from the site. Cost: free.

#### Instagram: what works where

| | Local XAMPP (`localhost`) | Live site (public https) | Live site + Instagram app set up by the owner |
|---|---|---|---|
| Instagram Kit images, caption, hashtags, alt text | Works | Works | Works |
| Share to Instagram from a phone (Web Share with the image files; paste the caption) | Works on a phone that can open the page | Works | Works |
| Download images (zip for carousels) and post from the Instagram app | Works | Works | Works |
| Log in with Instagram, Test connection | Needs credentials; localhost redirect unverified | Works once credentials are saved | Works |
| Post directly from the site (feed, square, carousel) | Refused with an explanation | Refused until the app is set up | Works for the owner (Tester) without App Review; other members after App Review |
| Reels, Stories from the site | Not built | Not built | Not built |

### X
1. developer.x.com > Projects & Apps > create an app. Under User authentication settings choose **OAuth 2.0**, type **Web App / confidential client**, and add the redirect URI. Scopes used: `tweet.read tweet.write users.read media.write offline.access`.
2. Copy the **Client ID** and **Client Secret**.
3. **Cost and limits:** posting requires an API plan that includes writes. X changes its plans and prices often; the free tier is very small and may not include posting at all. If the plan does not allow it, members see a plain message. Posts are 280 characters (a link counts as 23), up to 4 photos.

### LinkedIn
1. linkedin.com/developers > Create app (it needs a LinkedIn Company Page to associate with).
2. Products tab: request **Sign In with LinkedIn using OpenID Connect** and **Share on LinkedIn** (gives `w_member_social`). Share on LinkedIn is usually approved quickly for the app owner's own testing; broad use may need review.
3. Auth tab: add the redirect URI. Copy **Client ID** and **Primary Client Secret**.
4. Tokens last about 60 days and refresh tokens are only given to partners, so members reconnect when it expires (shown as "Needs reconnecting"). Posts to the member's own profile, up to 3,000 characters, up to 9 photos. Free.

### Pinterest
1. developers.pinterest.com > My apps > Connect app. Add the redirect URI. Copy **App ID** and **App secret**.
2. New apps start with **Trial access**: pins created are visible only to the app owner until Pinterest approves **Standard access** (a short review with a demo video). Scopes: `boards:read pins:read pins:write user_accounts:read`.
3. A pin needs one photo and goes to the member's first board (they must have one). The site's link is attached when the site is public. Free.

### Threads
1. developers.facebook.com > create an app with the **Threads API** use case (a different app from the Facebook one). Add the redirect URI. Copy the **Threads App ID** and **Threads App Secret**.
2. Permissions: `threads_basic`, `threads_content_publish`. Members must be added as Threads testers until the app passes App Review.
3. Text posts (500 characters) work anywhere. A photo needs a **public https URL** like Instagram, so photo posts wait until the site is online. Free.

## Link previews (Open Graph)

Public journals, storybooks and community photos include `og:title`, `og:description`, `og:image` (a real uploaded photo, absolute URL) and Twitter Card tags, so shared links preview well. Private and pending items get no tags. The address comes from `ja_base_url()`: on production set the `site_url` setting to your https address so previews and shared links are absolute and correct. For destination pages call `ja_social_og([...])` inside `<head>` (see `ja-social.php`).

Facebook caches previews; use the Sharing Debugger (developers.facebook.com/tools/debug) to refresh one.

## Privacy

- Tokens are encrypted at rest (AES-256-GCM) with the key in `private/social.key`, and are never written to logs, audit rows or error messages. Responses to the browser never include tokens.
- We store the minimum: the account's id, handle and display name, the encrypted token(s), and a history of what the member posted (service, item, result, link). Post text is not stored.
- Members can **Disconnect** at any time (Profile > Connected accounts). The site asks the service to revoke the token where the service supports it (Bluesky, Mastodon, X) and then deletes the tokens either way. Posts already published stay on the service; the member removes those there.
- Deleting a JourneyAI account revokes and deletes every connection and the post history.
- Camera roll photos are private. Sharing one asks first, "This makes a public copy; your original stays private", and only that copy is ever sent.
- Members are asked for only what posting needs (for example Mastodon `write:statuses write:media`; no reading, following or messages).
- The site never posts without the member's confirmation of the exact text and photos for each chosen account.

## Testing without real accounts

`tests/instagram_test.py` covers the Instagram Kit (image sizes, JPEG, no EXIF, caption and hashtag rules, privacy refusals), the public image link, and a local mock of Instagram's login, token, container, status and publish endpoints. It never touches a real account. Test-only settings: `social_instagram_auth_base`, `social_instagram_api_base`, `social_instagram_graph_base`, `social_instagram_poll_ms` and `social_instagram_test_public` (honoured only when the site itself runs on localhost).

`tests/social_test.py` runs a local mock of Bluesky and Mastodon (a thread in the test), so connect, post, refresh, revoke and delete are exercised end to end without any real account. The mock server address is accepted only when the site itself is running on localhost (settings `social_bluesky_base`, or a `http://127.0.0.1:PORT` Mastodon server). On a public site only `https` servers that do not resolve to private addresses are allowed.

```
ml/venv/Scripts/python.exe tests/social_test.py
ml/venv/Scripts/python.exe tests/instagram_test.py
```
