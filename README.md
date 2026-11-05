# Music App API

A Laravel 13 JSON API for email and social sign-in, profile management, a licensed music catalog, personal downloads, favorites, playlists, rights-reviewed TikTok import requests, and queued lossless-master audio processing.

## Requirements and setup

- PHP 8.3+
- Composer
- MySQL or SQLite
- FFmpeg and FFprobe on the queue worker for audio processing
- A configured Laravel filesystem disk for profile images and licensed audio

## Render Docker deployment

Connect this backend repository to Render as a **Web Service**, select **Docker** as the runtime, set the Dockerfile path to `./Dockerfile`, and use the repository root as the build context. The included Dockerfile serves Laravel on port `10000`, installs FFmpeg for the audio queue worker, and starts Apache and the worker together. The health-check path is `/up`.

Configure these values in Render's private environment settings before deploying:

- `APP_ENV=production`, `APP_DEBUG=false`, and a newly generated `APP_KEY` (`php artisan key:generate --show` generates a value; set it privately in Render).
- `APP_URL` to the deployed API HTTPS URL and `FRONTEND_URL` to the deployed website HTTPS URL.
- `DB_CONNECTION=pgsql` and `DATABASE_URL` (or `DB_URL`) from a Render PostgreSQL database.
- `FILESYSTEM_DISK=public` and a persistent disk mounted at `/var/www/html/storage/app`; the container filesystem itself is not persistent.
- `CORS_ALLOWED_ORIGINS` set to the frontend HTTPS origin only.
- SMTP settings (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`) for registration codes and password-reset links.
- `CACHE_STORE=database`, `SESSION_DRIVER=database`, and `QUEUE_CONNECTION=database`.

The container entrypoint applies migrations, seeds API response messages, and creates the public-storage link on startup. In production, the seeder also inserts metadata for the three demo tracks; audio files are not included in the repository or Docker image, so playback remains unavailable until audio is uploaded. Free instances have no persistent disk, so uploaded audio and avatars can be lost when the service restarts.

To provision the initial demo administrator without shell access, set `DEMO_ADMIN_EMAIL` (defaults to `admin@sonara.demo`) and a private `DEMO_ADMIN_PASSWORD` of at least 16 characters in Render's environment settings. The seeder creates this verified admin once and does not reset its password on subsequent deploys. If no password is configured, no demo admin is created. Store the APP key, database URL, admin password, and mail credentials only in Render's private environment settings, never in Git or chat.

To build the image locally from this repository root, run `docker build -t sonora-api .`. The Docker build excludes local `.env`, vendor, and generated files.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Configure `DB_*`, mail, `FRONTEND_URL`, any social provider credentials, and Zalo OA/ZNS values in `.env`, then run:

```powershell
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
```

To import rights-cleared MP3 files from your Windows Downloads folder into the catalog:

```powershell
php artisan tracks:import (Join-Path $env:USERPROFILE 'Downloads') --confirm-rights
```

The importer reads MP3 durations with FFprobe, stores audio on the `public` disk, and can be run again without duplicating files. Imported titles use the filenames; set `--license-name="..."` to provide a more specific license or rights attribution. Only import audio you are authorized to distribute.

Signed-in users can add up to 10 MP3 songs at a time from the catalog's **Add songs** button, edit each title before upload, and remove their own submissions from the catalog. Each file can be up to 200 MiB. In the `php.ini` used by `php artisan serve`, set `upload_tmp_dir` to an existing writable folder (for example, `C:\Users\<your-user>\AppData\Local\Temp`), `upload_max_filesize = 200M`, and `post_max_size = 210M`, then restart the server to apply the settings. Find the active configuration path with `php --ini`.

## Administrator management

After migrations are applied, promote an existing account from the backend terminal:

```powershell
php artisan users:promote-admin admin@example.com
```

Only run this command for an account you control. Administrators see the management screen after signing in. It provides account lock/unlock, song activation/deactivation and removal, song uploads, and a queue for resolving or dismissing user-submitted song reports. Locked accounts cannot sign in or use authenticated API routes; inactive songs are hidden from the public catalog and cannot be played or downloaded. Users can report songs from the Discover catalog.

For asynchronous audio processing, run a queue worker in a second terminal:

```powershell
php artisan queue:work --timeout=5400
```

Social providers require configured provider apps and real client access/identity tokens from the client application. Set `FACEBOOK_APP_ID` and `FACEBOOK_APP_SECRET`, `TIKTOK_CLIENT_KEY`, and `APPLE_CLIENT_ID`. “iCloud sign-in” is implemented as Sign in with Apple. For registration OTP, `channel` may be `email`, `icloud`, or `zalo`; both email channels deliver to the supplied email address, including an `@icloud.com` address. Zalo delivery requires `ZALO_OA_ACCESS_TOKEN`, an approved `ZALO_OTP_TEMPLATE_ID`, and matching `ZALO_OTP_CODE_FIELD` / `ZALO_OTP_EXPIRY_FIELD` template variables.

API routes are prefixed with `/api`. Authenticate with the bearer token returned by registration or login:

```http
Authorization: Bearer <token>
Accept: application/json
```

## API endpoints

| Method | Endpoint | Access | Purpose |
|---|---|---|---|
| POST | `/api/register` | Public | Request registration OTP (`name`, `channel`, `email` for email/icloud or E.164 `phone` for zalo, `password`, `password_confirmation`) |
| POST | `/api/register/verify` | Public | Verify `challenge_id` and six-digit `code`; create the account and return its token |
| POST | `/api/register/resend-otp` | Public | Resend using `challenge_id`; 60-second cooldown and hourly limits apply |
| POST | `/api/login` | Public | Get an API token |
| POST | `/api/auth/facebook` | Public | Verify a Facebook user access token and sign in |
| POST | `/api/auth/tiktok` | Public | Verify a TikTok user access token and sign in |
| POST | `/api/auth/apple` | Public | Verify an Apple identity JWT and sign in |
| POST | `/api/forgot-password` | Public | Send a password reset email |
| POST | `/api/reset-password` | Public | Reset password with `email`, `token`, `password`, and `password_confirmation` |
| POST | `/api/logout` | Authenticated | Revoke the current token |
| GET | `/api/profile` | Authenticated | Read profile |
| PUT | `/api/profile` | Authenticated | Update `name`, `bio`, `favorite_items`, or upload an `avatar` image |
| GET | `/api/tracks?q=...` | Public | Search and paginate catalog tracks |
| GET | `/api/tracks/{track}` | Public | Read track details |
| GET | `/api/tracks/{track}/download` | Authenticated | Download a rights-cleared track and add it to the user's library |
| GET | `/api/me/downloads` | Authenticated | List downloaded tracks |
| GET | `/api/me/favorites` | Authenticated | List favorite tracks |
| POST/DELETE | `/api/tracks/{track}/favorite` | Authenticated | Add/remove a favorite |
| GET/POST | `/api/playlists` | Authenticated | List/create the current user's playlists |
| GET/PUT/DELETE | `/api/playlists/{playlist}` | Authenticated | Read/update/delete an owned playlist |
| POST | `/api/playlists/{playlist}/tracks/{track}` | Authenticated | Add a track |
| DELETE | `/api/playlists/{playlist}/tracks/{track}` | Authenticated | Remove a track |
| GET | `/api/me/tiktok-imports` | Authenticated | List TikTok import review requests |
| POST | `/api/tiktok-imports` | Authenticated | Submit an HTTPS TikTok URL with `rights_confirmed: true` |
| POST | `/api/audio-processing` | Authenticated | Submit rights-cleared WAV/AIFF/FLAC master audio as multipart form-data |
| GET | `/api/audio-processing/{audioProcessing}` | Authenticated | Check queued audio processing status |
| GET | `/api/audio-processing/{audioProcessing}/files/{format}` | Authenticated | Download `master`, normalized `aac`, or normalized `mp3` |

Track search only returns tracks provisioned in the catalog. A track is downloadable only when its `downloadable` flag is true and its licensed audio file exists on the configured `public` storage disk. Keep catalog provisioning restricted to trusted administrators or a licensed provider; this starter API intentionally does not expose an upload endpoint for arbitrary audio.

TikTok submissions are stored with `review_required` status and a rights-attestation timestamp. This project does not scrape TikTok, bypass access controls, or extract audio from arbitrary video links. Completing an authorized import requires a rights-review workflow and an integration that is permitted to provide the audio.

Audio uploads are restricted to WAV, AIFF, or FLAC lossless sources, must include a rights confirmation, and are limited to 200 MiB and 30 minutes. For uploads near the limit, configure PHP `upload_max_filesize` and `post_max_size` to at least 200M. The worker preserves the source master and creates 48 kHz AAC (256 kb/s) and MP3 (320 kb/s) streams using FFmpeg's two-pass EBU R128 loudness normalization (-14 LUFS integrated, -1 dBTP true peak, 11 LU LRA). Actual sound quality still depends on the source recording/master; transcoding cannot restore quality missing from a lossy or poor-quality source, and this does not promise identical processing to TikTok.

JSON endpoints use a common envelope:

```json
{
  "success": true,
  "message": {
    "code": "auth.logged_in",
    "text": "Logged in successfully."
  },
  "data": {}
}
```

Message codes and localized text are stored in the `api_messages` table and seeded by `php artisan db:seed`. Validation errors use the same envelope and include field details in `errors`. Binary file downloads are returned as file streams instead of JSON. An importable Postman collection with sample payloads and collection variables is at [postman/Music-App-API.postman_collection.json](./postman/Music-App-API.postman_collection.json).

Registration creates no account until the OTP is verified. Codes are stored hashed, expire after 10 minutes, are limited to five verification attempts, and can be resent after a cooldown. Email OTPs use the configured Laravel mail transport; Zalo phone delivery uses the Zalo OA ZNS template API. Configure a production mail provider and a Zalo-approved OTP template before enabling those channels.

Expired challenge records, which contain only password/code hashes, can be removed with `php artisan model:prune --model="App\\Models\\RegistrationOtp"`; schedule this command regularly in production.

## Tests

```powershell
php artisan test
```
