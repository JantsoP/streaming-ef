# Local Development Guide

Step-by-step instructions for running the full streaming stack on a local Ubuntu/Debian machine, including RTMP ingress, HLS playback, DVR recording, and VOD creation — all without needing an external identity provider or S3 bucket.

## Prerequisites

Install the following on your Ubuntu/Debian machine:

```bash
# Docker Engine (not Docker Desktop)
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Add your user to the docker group so you don't need sudo
sudo usermod -aG docker $USER
newgrp docker

# PHP CLI (for Composer — only needed for the Sail helper script)
# Add Sury PHP repository for PHP 8.2+
sudo apt-get install -y lsb-release ca-certificates curl
curl -sSL https://packages.sury.org/php/README.txt | sudo bash -x
sudo apt-get update
sudo apt-get install -y php8.2-cli php8.2-curl php8.2-xml php8.2-mbstring unzip

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js (for npm / Vite)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
```

---

## 1. Clone and configure

```bash
git clone https://github.com/your-org/streaming-ef.git
cd streaming-ef

# Install PHP dependencies (needed to get the `sail` script)
composer install --ignore-platform-reqs

# Set environment variables for sail (add to ~/.bashrc to make permanent)
export COMPOSE_FILE=docker-compose.local.yml
alias sail='./vendor/bin/sail'

# Create your local .env
cp .env.example .env
```

---

## 2. Edit `.env` for local dev

The `.env.example` already ships with MinIO defaults. Edit your `.env` file.

**If running on a VM/remote server**, replace `localhost` with your server's IP address (e.g., `192.168.86.129`):

```dotenv
APP_NAME="Streaming EF Local"     # IMPORTANT: keep the quotes!
APP_ENV=local
APP_KEY=                          # generated below
APP_DEBUG=true
APP_URL=http://192.168.86.129     # Use VM IP instead of localhost

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=streaming_ef
DB_USERNAME=sail
DB_PASSWORD=password

REDIS_HOST=redis
REDIS_PORT=6379

# WebSockets — Use VM IP for client connections from other machines
BROADCAST_DRIVER=reverb
REVERB_APP_ID=my-app-id        # any string
REVERB_APP_KEY=my-app-key      # any string
REVERB_APP_SECRET=my-app-secret  # any string
REVERB_HOST=192.168.86.129     # Use VM IP for external WebSocket connections
REVERB_PORT=6001
REVERB_SCHEME=http
# PUSHER_* mirror the REVERB_* values automatically via .env.example variable references

# Vite HMR — required when accessing from a remote machine/VM
# Without this, the browser tries to connect to localhost:5173 for hot reload
VITE_DEV_SERVER_HOST=192.168.86.129

# DVR → MinIO (use VM IP for browser access to recordings)
DVR_AWS_ACCESS_KEY_ID=minio
DVR_AWS_SECRET_ACCESS_KEY=minio123
DVR_AWS_BUCKET=recording
DVR_AWS_ENDPOINT=http://minio:9000          # Internal Docker network address
DVR_AWS_URL=http://192.168.86.129:9000/recording  # External browser access
DVR_AWS_USE_PATH_STYLE_ENDPOINT=true
DVR_EVENT_SLUG=local

# VOD: archive FFmpeg writes segments here during the stream;
# upload job reads from the same path (shared hls-content volume).
HLS_ARCHIVE_BASE_DIR=/var/www/hls/archive
# Flag files used to start/stop archive recording (created on Go Live / End Stream).
HLS_ARCHIVE_FLAGS_DIR=/var/www/hls/archive-flags
# Pause flag files used by the "Pause VOD Recording" admin button (intermissions).
HLS_ARCHIVE_PAUSE_DIR=/var/www/hls/archive-pause
# Short delay so archive FFmpeg can write #EXT-X-ENDLIST before the upload job runs.
VOD_CREATION_DELAY_SECONDS=15
```

**If running directly on your local machine**, use `localhost` instead:
- `APP_URL=http://localhost`
- `REVERB_HOST=localhost`
- `DVR_AWS_URL=http://localhost:9000/recording`
- `VITE_DEV_SERVER_HOST` — omit entirely (not needed locally)

Leave `OIDC_*`, `HETZNER_*`, `DNS_*` empty — they are not needed for local dev.

---

## 3. Start the stack

```bash
# Generate APP_KEY (runs on your host machine, not in Docker)
php artisan key:generate

# Build local images (dvr-uploader and ffmpeg-hls use local Dockerfiles)
sail build

# Start everything in the background
sail up -d

# Wait ~30 seconds for MySQL to fully start before proceeding to step 4
```

---

## 4. Run migrations and seed local data

**Important:** Make sure the containers are running (step 3) before running this command.

Migrations must run on **first install** — Docker starts a fresh empty MySQL container and migrations create all the tables and columns the app needs. This is a one-time step per fresh environment.

```bash
# Fix permissions for the entire project directory
sail exec laravel.test chown -R sail:sail /var/www/html

# Run migrations and seeders
sail artisan migrate --seed
```

> **Pulling new code later?** If a `git pull` includes new migration files, run `sail artisan migrate` (no `--seed`) to apply only the new columns/tables without re-seeding.

The seeder outputs OBS settings in the terminal:

```
╔══════════════════════════════════════════════╗
║           OBS CONFIGURATION SETTINGS         ║
╠══════════════════════════════════════════════╣
║ Server URL:  rtmp://localhost:1935/live      ║
║ Stream Key:  test-stream?key=test_secret_... ║
╚══════════════════════════════════════════════╝
```

Save the stream key — you will need it for OBS.

---

## 5. Create the MinIO bucket

**Option A — Web console (easiest):**

1. Open http://192.168.86.129:9001 (or http://localhost:9001 if running on your local machine)
2. Login: `minio` / `minio123`
3. Click **Create Bucket**, name it `recording`
4. Open bucket settings → **Access Policy** → set to **Public**

**Option B — CLI inside the MinIO container:**

```bash
sail exec minio sh -c "
  mc alias set local http://localhost:9000 minio minio123 &&
  mc mb --ignore-existing local/recording &&
  mc anonymous set public local/recording
"
```

---

## 6. Start the Vite dev server

In a separate terminal (runs hot-reloading for Vue/JS assets):

```bash
# Install npm dependencies first (one-time setup)
sail npm install

# Start Vite dev server
sail npm run dev
```

---

## 7. Log in without OIDC

Open **http://192.168.86.129** (or **http://localhost** if on your local machine) in your browser.

The login page shows a yellow **"Dev Admin Login (no OIDC)"** button at the bottom. Click it — you are instantly logged in as a full admin. No Eurofurence identity account needed.

---

## 8. Create a Show in the admin panel

1. Go to **http://192.168.86.129/admin** (or **http://localhost/admin** if on your local machine)
2. **Sources** → confirm `Test Stream` exists (created by the seeder)
3. **Shows** → Create a new show:
   - Title: anything
   - Source: `Test Stream`
   - Set `recordable = true` if you want to test DVR→VOD
   - Save
4. Click **Go Live** to mark it live (sets `actual_start`)

---

## 9. Stream from OBS

Both RTMP and SRT are active simultaneously — OBS operators can use either.

**RTMP (simplest, any OBS version):**
- Settings → Stream → Service: `Custom`
- Server: `rtmp://192.168.86.129:1935/live` (or `rtmp://localhost:1935/live` if OBS is on the same machine)
- Stream Key: _(from step 4 above)_

**SRT (lower latency, more robust on WiFi):**
- Settings → Stream → Service: `Custom`
- Server: `srt://192.168.86.129:10080?streamid=#!::r=live/<your-stream-slug>?secret=<your-stream-key>,m=publish`
  _(or replace `192.168.86.129` with `localhost` if OBS is on the same machine)_
- Stream Key: _(leave empty — the streamid is already in the Server URL above)_

> **Why the full URL?** OBS's SRT plugin places the Stream Key field value into the SRT `streamid` parameter, but some OBS versions URL-encode the leading `#` to `%23`, which breaks SRS's streamid parser. Putting the full streamid in the Server URL avoids this.

SRT reduces ingest latency from ~2–4 s to ~120 ms and recovers silently from packet loss that would stutter or drop an RTMP stream. On a reliable wired connection the difference is invisible; on venue WiFi it's meaningful.

Click **Start Streaming**. The FFmpeg transcoder (`origin-ffmpeg-hls`) will detect the RTMP stream (SRS bridges SRT → RTMP internally) and produce multi-bitrate HLS automatically.

---

## 10. Watch the stream

Browse to the show page at **http://192.168.86.129/show/\<slug\>** (or **http://localhost/show/\<slug\>** if on your local machine) — the player will load.

**Direct HLS URLs** for testing with VLC or ffplay (replace `192.168.86.129` with `localhost` if running locally):

```bash
# Master playlist (adaptive bitrate)
http://192.168.86.129:8085/live/test-stream/master.m3u8

# Individual qualities
http://192.168.86.129:8085/live/test-stream_fhd/index.m3u8
http://192.168.86.129:8085/live/test-stream_hd/index.m3u8
http://192.168.86.129:8085/live/test-stream_sd/index.m3u8

# VLC
vlc http://192.168.86.129:8085/live/test-stream_fhd/index.m3u8

# ffplay
ffplay http://192.168.86.129:8085/live/test-stream_fhd/index.m3u8
```

---

## 11. Test VOD creation

VOD creation is now instant — no re-encoding. When `origin-ffmpeg-hls` starts transcoding a stream it runs a **second FFmpeg process** that writes the same 480p/720p/1080p HLS segments to `/var/www/hls/archive/{stream}/` and keeps every segment (`hls_list_size 0`). When the show ends, a Laravel queued job uploads those pre-encoded segments to MinIO and creates the `Recording` row.

Archive recording is **controlled by Go Live / End Stream** — it only records what's between those two buttons. It does not start when OBS connects; it starts when you press Go Live.

**Pause during intermissions:**  
While a show is live, Filament → Shows → row actions (or the Edit page header) has **"Pause VOD Recording"** and **"Continue VOD Recording"** buttons. Pausing stops the archive FFmpeg mid-stream; resuming restarts it in append mode so the final VOD is seamless with no intermission content.

**Trigger flow:**

1. In Filament admin, press **Go Live** on an existing show (the archive flag file is written immediately)
2. Start streaming from OBS — archive FFmpeg starts within ~5 s of the stream being detected
3. Confirm archive FFmpeg started:
   ```bash
   sail logs -f origin-ffmpeg-hls | grep Archive
   # Should see: [Archive test-stream] Output #0, hls ...
   ```
4. _(Optional)_ Click **Pause VOD Recording** to skip an intermission, then **Continue VOD Recording** to resume
5. In Filament admin, click **End Stream** (removes archive flag, stops archive FFmpeg cleanly)
6. Make sure Horizon is running:
   ```bash
   sail artisan horizon
   ```
7. After ~15 seconds, `CreateVodFromShowJob` runs:
   - Checks `#EXT-X-ENDLIST` is present in archive playlists (retries if not)
   - Uploads all `.ts` + `.m3u8` files to MinIO under `recording/on-demand/local/<show-slug>/`
   - **Verifies** S3 file count and master playlist size before deleting local files
   - Deletes the local archive directory only after successful S3 verification
   - Creates a `Recording` row → `ProcessRecordingJob` extracts duration + thumbnail
8. Browse to **http://192.168.86.129/recordings** (or **http://localhost/recordings**) or check Filament → Recordings

**Verify files in MinIO:**
```bash
# Open the MinIO console and browse to:
# recording → on-demand → local → <show-slug>/
open http://192.168.86.129:9001  # or http://localhost:9001
```

**Watch archive FFmpeg write segments in real time:**
```bash
sail exec origin-ffmpeg-hls \
  ls -lh /var/www/hls/archive/test-stream/
```

---

## 12. Useful commands

```bash
# View all running containers
sail ps

# Follow Laravel logs
sail logs -f laravel.test

# Follow archive FFmpeg logs (watch segment writing for VOD)
sail logs -f origin-ffmpeg-hls

# Follow DVR uploader logs (SRS .mp4 segment backup uploads to MinIO)
sail logs -f dvr-uploader

# Follow SRS logs (RTMP ingest + DVR segment creation)
sail logs -f origin-srs

# Run artisan commands
sail artisan <command>

# Open a shell in the app container
sail shell

# Re-run seeders (safe — uses updateOrCreate)
sail artisan db:seed

# Wipe and re-seed from scratch
sail artisan migrate:fresh --seed

# Stop everything (keeps volumes)
sail down

# Stop and destroy all data (volumes too)
sail down -v
```

---

## Port reference

| Service | Port | Purpose |
|---|---|---|
| Laravel app | 80 | Main website + admin |
| Vite HMR | 5173 | JS/CSS hot reload |
| MySQL | 3306 | Database |
| Redis | 6379 | Cache / queues |
| Soketi | 6001 | WebSockets |
| SRS RTMP | 1935 | OBS stream input (TCP) |
| SRS SRT | 10080/udp | OBS stream input — lower latency, packet-loss tolerant |
| SRS HTTP | 8082 | SRS internal API |
| SRS API | 1985 | SRS management API |
| Origin Nginx | 8083 | Internal HLS auth |
| Origin Caddy | 8070 | Origin HLS HTTP |
| Edge Nginx | 8081 | Edge cache |
| **Edge Caddy** | **8085** | **Public HLS delivery** |
| MinIO S3 API | 9000 | DVR segment storage |
| MinIO Console | 9001 | MinIO web UI |

---

## Troubleshooting

**"Dev Admin Login" button not showing:**  
Confirm `APP_ENV=local` in `.env` and rebuild the config cache: `sail artisan config:clear`

**No HLS segments after streaming:**  
Check FFmpeg transcoder logs: `sail logs -f origin-ffmpeg-hls`  
Confirm SRS is receiving the stream: `curl http://localhost:1985/api/v1/streams/`

**SRT stream not connecting:**  
SRT uses UDP, which is blocked by some firewalls/VPNs. Confirm port 10080/udp is reachable.  
Check SRS logs for the stream ID: `sail logs -f origin-srs | grep srt`  
**OBS Server URL must be the full combined URL** — copy it from the Sources edit page in Filament (the "OBS SRT Server URL (Full)" field). Leave the OBS Stream Key field empty.  
Do NOT put the streamid in OBS's Stream Key field — some OBS versions URL-encode the `#` to `%23` which breaks SRS's streamid parser.

**DVR segments not appearing in MinIO:**  
These are the raw SRS `.mp4` backup segments (not used for VOD anymore but still uploaded).  
Check dvr-uploader: `sail logs -f dvr-uploader`  
Confirm the `recording` bucket exists and is public.

**Archive segments not being written:**  
Archive FFmpeg only starts when you press **Go Live** in Filament, not when OBS connects.  
Make sure you clicked Go Live before checking for segments.  
Check: `sail logs -f origin-ffmpeg-hls | grep "Archive"`  
If missing, confirm `ARCHIVE_BASE_DIR` and `ARCHIVE_FLAGS_DIR` are set in the ffmpeg service env and the `hls-content` volume is shared between `origin-ffmpeg-hls` and `laravel.test`.  
Inspect archive dir: `sail exec origin-ffmpeg-hls ls -lh /var/www/hls/archive/`

**VOD recording stuck on "Paused" after intermission:**  
Click **Continue VOD Recording** in Filament → Shows. If the button is missing, check that the show is still `live` and `recordable`.  
The pause flag file at `/var/www/hls/archive-pause/{slug}` can also be removed manually inside the container if the UI is unresponsive.

**VOD job fails with "#EXT-X-ENDLIST not found":**  
This means the archive FFmpeg was still writing when the job ran. It will auto-retry (up to 2× with backoff). Check `sail artisan horizon` to see the retry. You can also increase `VOD_CREATION_DELAY_SECONDS` in `.env`.

**VOD job fails with "S3 verification failed":**  
An upload was incomplete. The job throws and retries — local archive files are **not deleted** unless S3 verification passes, so no data is lost. Check MinIO connectivity: `curl http://localhost:9000/minio/health/live`

**VOD job not running at all:**  
Ensure Horizon is started: `sail artisan horizon`  
Check the `recordings` queue: `sail artisan queue:monitor recordings`  
Delay is `VOD_CREATION_DELAY_SECONDS=15` — job should appear within ~20 seconds of stopping the show.
**Caddy/Nginx healthchecks failing:**  
These are expected while the app is first booting. Wait 30 seconds and check again.
