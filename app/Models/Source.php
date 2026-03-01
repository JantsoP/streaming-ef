<?php

namespace App\Models;

use App\Enum\SourceStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'name',
        'slug',
        'description',
        'stream_key',
        'priority',
    ];

    protected $casts = [
        'status' => SourceStatusEnum::class,
        'stream_key' => 'encrypted',
    ];

    protected $hidden = [
        'stream_key',
    ];

    /**
     * Validation rules for the model.
     */
    public static function rules($id = null)
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('sources')->ignore($id)],
            'stream_key' => ['required', 'string', Rule::unique('sources')->ignore($id)],
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($source) {
            if (empty($source->slug)) {
                $source->slug = Str::slug($source->name);
            }
            // Generate a secure stream key for authentication
            if (empty($source->stream_key)) {
                // Generate a secure random key for the secret parameter
                $source->stream_key = Str::random(32);
            }
        });

        static::updating(function ($source) {
            // Update slug if name changes
            if ($source->isDirty('name') && !$source->isDirty('slug')) {
                $source->slug = Str::slug($source->name);
            }
            // Stream key should remain separate from slug for security
            // Only regenerate if explicitly cleared
            if ($source->isDirty('stream_key') && empty($source->stream_key)) {
                $source->stream_key = Str::random(32);
            }
        });
    }

    /**
     * Get the shows for this source.
     */
    public function shows()
    {
        return $this->hasMany(Show::class);
    }

    /**
     * Get viewer sessions for this source.
     */
    public function viewers()
    {
        return $this->hasMany(SourceUser::class);
    }

    /**
     * Get active viewer sessions for this source.
     */
    public function activeViewers()
    {
        return $this->hasMany(SourceUser::class)
            ->whereNull('left_at')
            ->where('last_heartbeat_at', '>', now()->subMinutes(3));
    }

    /**
     * Get currently live shows for this source.
     */
    public function liveShows()
    {
        return $this->shows()->where('status', 'live');
    }

    /**
     * Get upcoming shows for this source.
     */
    public function upcomingShows()
    {
        return $this->shows()
            ->where('status', 'scheduled')
            ->where('scheduled_start', '>', now())
            ->orderBy('scheduled_start');
    }

    /**
     * Check if source has any live shows.
     */
    public function hasLiveShow()
    {
        return $this->liveShows()->exists();
    }

    /**
     * Get the current live show if any.
     */
    public function currentLiveShow()
    {
        return $this->liveShows()->first();
    }

    /**
     * Get sources ordered by priority (descending) then by name.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'desc')->orderBy('name');
    }

    /**
     * Get the base RTMP server URL for OBS configuration.
     * Returns URL in format: rtmp://server:port/ingress
     */
    public function getRtmpServerUrl()
    {
        // Get the active origin server
        $originServer = \App\Models\Server::where('type', \App\Enum\ServerTypeEnum::ORIGIN)
            ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
            ->first();

        return "rtmp://{$originServer->hostname}:1935/ingress";
    }

    /**
     * Get the stream key for OBS configuration.
     * Returns: <slug>?secret=<stream_key>STr
     */
    public function getObsStreamKey()
    {
        return $this->slug.'?secret='.$this->stream_key;
    }

    /**
     * Get the full RTMP push URL (for reference/testing).
     * Returns URL in format: rtmp://server:port/ingress/<slug>?secret=<stream_key>
     */
    public function getRtmpPushUrl()
    {
        return $this->getRtmpServerUrl().'/'.$this->slug.'?secret='.$this->stream_key;
    }

    /**
     * Get the SRT server URL for OBS configuration.
     * Returns URL in format: srt://server:10080
     */
    public function getSrtServerUrl()
    {
        $originServer = \App\Models\Server::where('type', \App\Enum\ServerTypeEnum::ORIGIN)
            ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
            ->first();

        return "srt://{$originServer->hostname}:10080";
    }

    /**
     * Get the SRT stream key for OBS configuration.
     * Returns: #!::r=live/<slug>?secret=<stream_key>,m=publish
     */
    public function getSrtStreamKey()
    {
        return '#!::r=live/'.$this->slug.'?secret='.$this->stream_key.',m=publish';
    }

    /**
     * Get the full SRT URL for OBS — streamid embedded in the URL query string.
     * Use this in OBS Settings → Stream → Server (leave Stream Key empty).
     * Embedding the streamid avoids OBS URL-encoding the leading '#' to '%23',
     * which would break SRS's streamid parser.
     * Returns: srt://host:10080?streamid=#!::r=live/<slug>?secret=<key>,m=publish
     */
    public function getSrtObsUrl()
    {
        return $this->getSrtServerUrl().'?streamid='.$this->getSrtStreamKey();
    }

    /**
     * Get HLS master playlist URL.
     */
    public function getHlsUrl()
    {
        return route('hls.master', ['stream' => $this->slug]);
    }
}
