<?php

namespace InfinityNext\LaravelCaptcha;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Cache;
use Config;
use DateTimeInterface;
use DB;
use Request;
use Session;

class Captcha extends Model
{
    /**
     * Attributes which are automatically sent through a Carbon instance on load.
     *
     * @var array
     */
    protected $dates = [
        'created_at',
        'cracked_at',
    ];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'cracked_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'hash',
        'client_ip',
        'client_session_id',
        'solution',
        'profile',
        'created_at',
        'cracked_at',
    ];

    /**
     * Attributes which do not exist but should be appended to the JSON output.
     *
     * @var array
     */
    protected $appends = [
        'hash_string',
    ];

    /**
     * Captcha models cached by their hex value.
     *
     * @static
     * @var array
     */
    protected static $modelSingletons = [];

    /**
     * The primary key that is used by ::get()
     *
     * @var string
     */
    protected $primaryKey = 'captcha_id';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table;

    /**
     * Disables `created_at` and `updated_at` auto-management.
     *
     * @var boolean
     */
    public $timestamps = false;

    /**
     * A flag to be set if this model has passed validation during this request.
     *
     * @var bool
     */
    protected $validated_this_request = false;

    /**
     * Attributes to be given in JSON responses for captchas.
     *
     * @var array
     */
    protected $visible = [
        'hash_string',
        'created_at',
    ];

    /**
     * Dynamically binds table and binds events.
     *
     * @return void
     */
    public function __construct()
    {
        // Make sure our table is correct.
        $this->setTable(Config::get('captcha.table'));

        // When creating a captcha, set the created_at timestamp.
        static::creating(function($captcha)
        {
            if (!isset($captcha->created_at)) {
                $captcha->created_at = $captcha->freshTimestamp();
            }

            return true;
        });

        static::saving(function($captcha)
        {
            return !App::isUnitTesting();
        });

        // Pass any additional parameters we have upstream.
        call_user_func_array(array($this, 'parent::' . __FUNCTION__), func_get_args());
    }

    /**
     * Marks the captcha answered
     *
     * @return Captcha.
     */
    public function crack()
    {
        $this->cracked_at = $this->freshTimestamp();
        $this->validated_this_request = true;
        return $this;
    }

    /**
     * Create a collection of models from plain arrays.
     *
     * @static
     * @param  array  $items
     * @param  string|null  $connection
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function hydrate(array $items, $connection = null)
    {
        $instance = (new static)->setConnection($connection);

        $items = array_map(function ($item) use ($instance) {
            // This loop unwraps content from stream resource objects.
            // PostgreSQL will return binary data as a stream, which does not
            // cache correctly. Doing this allows proper attribute mutation and
            // type casting without any headache or checking which database
            // system we are using before doing business logic.
            //
            // See: https://github.com/laravel/framework/issues/10847
            foreach ($item as $column => $datum) {
                if (is_resource($datum)) {
                    $item->{$column} = stream_get_contents($datum);
                }
            }

            return $instance->newFromBuilder($item);
        }, $items);

        return $instance->newCollection($items);
    }

    /**
     * Reverses a string, respecting multibyte characters.
     *
     * @static
     * @param  string  $string
     * @return string
     */
    protected static function mb_strrev($string)
    {
        $length   = mb_strlen($string);
        $reversed = "";

        while ($length-- > 0) {
            $reversed .= mb_substr($string, $length, 1);
        }

        return $reversed;
    }

    /**
     * Handles binary data for database connections.
     *
     * @param  binary  $bin
     * @return binary
     */
    protected static function escapeBinary($bin)
    {
        if (Config::get('captcha.escapeBinary', true) && DB::connection() instanceof PostgresConnection) {
            $bin = pg_escape_bytea($bin);
        }

        return $bin;
    }


    /**
     * Handles IP addresses for database connections.
     *
     * @param  binary  $bin
     * @return binary
     */
    protected static function escapeInet($inet = null)
    {
        if (is_null($inet)) {
            $inet = Request::ip();
        }

        if (Config::get('captcha.escapeInet', true)) {
            return static::escapeBinary(inet_pton($inet));
        }

        return $inet;
    }

    /**
     * Finds the last good captcha with this IP.
     *
     * @static
     * @param  binary|null  $ip  Optional. IP to search with. Defaults to client IP if NULL.
     * @return Captcha
     */
    public static function findWithIP($ip = null)
    {
        $ip = static::escapeInet($ip);

        $captcha = static::whereNull('cracked_at')
            ->where('client_ip', $ip)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($captcha && $captcha->exists) {
            return $captcha;
        }

        return false;
    }

    /**
     * Passes SHA1 hex as binary to find model.
     *
     * @static
     * @param  string  $hex
     * @return Captcha
     */
    public static function findWithHex($hex)
    {
        $hash = static::escapeBinary(hex2bin($hex));

        if (isset(static::$modelSingletons[$hash])) {
            return static::$modelSingletons[$hash];
        }

        $model = static::where(['hash' => $hash])->first();

        if ($model) {
            static::$modelSingletons[$hash] = $model;
        }

        return $model;
    }

    /**
     * Passes SHA1 hex as binary to find model.
     *
     * @static
     * @param  string  $hex
     * @return Captcha
     */
    public static function findWithSession($session_id = null)
    {
        if (is_null($session_id)) {
            $session_id = Session::getId();
        }

        $hash = static::escapeBinary(hex2bin($session_id));

        if (isset(static::$modelSingletons[$hash])) {
            return static::$modelSingletons[$hash];
        }

        $model = static::whereNull('cracked_at')
            ->where('client_session_id', $hash)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($model) {
            static::$modelSingletons[$hash] = $model;
        }

        return $model;
    }

    /**
     * Returns captcha id as a hex string.
     *
     * @return string  in hex
     */
    public function getHash()
    {
        return bin2hex(static::unescapeBinary($this->hash));
    }

    /**
     * Returns the hash as a string by requesting $this->hash_string.
     *
     * @return string  sha1 as hex
     */
    public function getHashStringAttribute()
    {
        return $this->getHash();
    }

    /**
     * Determines if the captcha has already been solved.
     *
     * @return boolean
     */
    public function isCracked()
    {
        return !is_null($this->cracked_at);
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     *
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->timestamp;
    }

    /**
     * Handles binary data for database connections.
     *
     * @param  binary  $bin
     * @return binary
     */
    protected static function unescapeBinary($bin)
    {
        if (is_resource($bin)) {
            $bin = stream_get_contents($bin);
        }

        if (DB::connection() instanceof PostgresConnection) {
            $bin = pg_unescape_bytea($bin);
        }

        return $bin;
    }

}
