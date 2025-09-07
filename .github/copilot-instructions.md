# Copilot Instructions for streaming-ef

## Project Architecture
- **Backend**: Laravel 12 (PHP 8.2+)
- **Frontend**: Vue 3 + Inertia.js 2
- **Admin Panel**: Filament 3
- **Real-time**: Pusher/Soketi (WebSockets)
-- **Streaming**: OME (RTMP/SRT/HLS/DASH/LL-HLS/LL-DASH)
- **Queue**: Laravel Horizon + Redis
- **Database**: MySQL 8.0
- **Infrastructure**: Hetzner Cloud API for server provisioning

## Key Components
-- **Streaming**: OME origin/edge servers, auto-provisioned via Hetzner
- **Models**: `User` (OpenID), `Server`, `Client`, `Message` (chat/moderation)
- **Real-time**: WebSocket for chat/events, stream status, chat rate limiting
- **Auto-scaling**: `AutoscalerService` + jobs for server lifecycle

## Developer Workflows
- Install: `composer install`, `npm install`
- Migrate/seed: `php artisan migrate --seed`
- Dev servers: `php artisan serve`, `npm run dev`, `php artisan horizon`, `php artisan octane:start`
- Testing: `php artisan test`, with suite/file options
- Code quality: `./vendor/bin/pint`, artisan cache/config/route/view clear
- Queue: `php artisan queue:work`, monitor via `/horizon`
- **Prefer Sail over Docker Compose** for local development

## Environment Variables
- `HETZNER_API_TOKEN`, `PUSHER_*`, `OIDC_*`, `STREAM_*`, `CHAT_*`

## Job Queue
- `CreateServerJob`, `DeleteServerJob`, `ScalingJob`, `ServerAssignmentJob`, chat moderation jobs

## Admin Panel
- Filament at `/admin`: server/client/user management, real-time widgets

## Project-Specific Rules
- **Never use `fetch()` or direct API calls**; always use Inertia.js 2 props for backend→frontend data
- Use Tailwind `-primary-` color, not `-gray-`
- No need to run build; `npm run dev` is always running

## References
- See `CLAUDE.md` for detailed architecture and workflow notes
- Key directories: `app/`, `config/`, `database/`, `docker/`, `resources/`, `routes/`, `tests/`
