<?php namespace InfinityNext\LaravelCaptcha;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Support\Htmlable;

use Cache;
use DateTime;
use DateTimeInterface;
use DB;
use Request;
use Session;

class Captcha extends Model implements Htmlable
{
	/**
	 * Attributes which are automatically sent through a Carbon instance on load.
	 *
	 * @var array
	 */
	protected $dates = [
		'created_at',
		'cracked_at',
		'expires_at',
	];

	/**
	 * The attributes that should be casted to native types.
	 *
	 * @var array
	 */
	protected $casts = [
		'solution' => 'string',
		'profile' => 'string',
		'created_at' => 'datetime',
		'cracked_at' => 'datetime',
		'expires_at' => 'datetime',
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
		'expires_at',
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
		'expires_at',
	];


	/**
	 * Dynamically binds table and binds events.
	 *
	 * @return void
	 */
	public function __construct()
	{
		// Make sure our table is correct.
		$this->setTable(config('captcha.table'));

		// When creating a captcha, set the created_at timestamp.
		static::creating(function($captcha)
		{
			if (!isset($captcha->created_at))
			{
				$captcha->created_at = $captcha->freshTimestamp();
			}

			return true;
		});


		// Pass any additional parameters we have upstream.
		call_user_func_array(array($this, 'parent::' . __FUNCTION__), func_get_args());
	}

	/**
	 * Checks a captcha code against the stored solution.
	 * This will spend the token if it's available.
	 *
	 * @param  string  $hash
	 * @param  string  $answer
	 * @return boolean|null  Boolean if token was spent, NULL if no captcha found.
	 */
	public static function answerCaptcha($hash, $answer)
	{
		$captcha = static::findWithHex($hash);

		if ($captcha instanceof static)
		{
			if ($captcha->validated_this_request)
			{
				return true;
			}

			if ($captcha->isCracked() || $captcha->isExpired())
			{
				return false;
			}

			if ($captcha->checkAnswer($answer))
			{
				$captcha->cracked_at = $captcha->freshTimestamp();
				$captcha->validated_this_request = true;
				$captcha->save();

				return true;
			}
		}

		return null;
	}

	/**
	 * Determines if the supplied answer matches the solution string.
	 *
	 * @param  string  $answer
	 * @return boolean  If token was spent.
	 */
	public function checkAnswer($answer)
	{
		return str_replace(" ", "", mb_strtolower($answer)) === mb_strtolower($this->solution);
	}

	/**
	 * Generate the captcha image.
	 *
	 * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
	 * @return Captcha
	 */
	public static function createCaptcha($profile = "default")
	{
		if (static::getProfile($profile) === false)
		{
			return abort(404);
		}

		// Generate our answer from the charset and length config.
		$solution = static::createSolution($profile);

		// Returns an IP for insertion.
		// Corrects PostgreSQL issues.
		$ip = static::escapeInet();
		$session_id = static::escapeBinary(hex2bin(Session::getId()));

		$captcha = new static([
			'client_ip'         => $ip,
			'client_session_id' => $session_id,
			'solution'          => $solution,
			'profile'           => $profile,
		]);

		$captcha->hash = static::escapeBinary(hex2bin(sha1(implode("-", [
			config('app.key'),
			Request::ip(),
			Session::getId(),
			$solution,
			$captcha->freshTimestamp()
		]))));

		$captcha->save();

		return $captcha;
	}

	/**
	 * Generate the captcha image.
	 *
	 * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
	 * @return Captcha
	 */
	protected function createCaptchaImage($profile = "default", $recreate = false)
	{
		$rememberTimer   = 5;
		$rememberKey     = "laravel-captcha.captcha.{$this->getHash()}";
		$rememberClosure = function() use ($profile) {
			return $this->createGdCaptchaImage($profile);
		};

		if ($recreate)
		{
			Cache::forget($rememberKey);
		}

		return Cache::remember($rememberKey, $rememberTimer, $rememberClosure);
	}

	/**
	 * Creates a captcha image using the GD library.
	 *
	 * @author Fredrick Brennan  @ctrlcctrlv
	 * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
	 * @return GdResource
	 */
	protected function createGdCaptchaImage($profile)
	{
		// Clock generation start time for debugging.
		$startTime = microtime(true);

		// Find a font.
		$font = $this->getFontRandom($profile);

		if (!isset($font['stroke']) || !is_numeric($font['stroke']) || $font['stroke'] <= 0)
		{
			$font['stroke'] = 3;
		}

		// Get our solution.
		$solution = $this->solution;

		// Reverse the solution if the profile is for right-to-left script.
		if ($this->isRTL($profile))
		{
			$solution = $this->mb_strrev($solution);
		}

		// Split the solution into pieces between 1 and 3 characters long.
		$answerArray = array();
		for ($i = 0; $i < mb_strlen($solution); $i)
		{
			$n = mt_rand(1,3);

			$answerArray[] = mb_substr($solution, $i, $n);

			$i += $n;
		}

		// We need to generate TTFBBOX for each of our substrings.
		// This is the bounding box that the chosen font will take up and also the box within which we will draw our mt_random dividng line.
		$bboxArray   = array();
		$totalWidth  = 0;
		$totalHeight = 0;

		foreach ($answerArray as $i => $t)
		{
			// gd supports writing text at an arbitrary angle. Using this can confuse OCR programs.
			$angle  = mt_rand(-10,10);

			$bbox   = imageftbbox($this->getFontSize($profile), $angle, $this->getFontPath($font), $t);
			$height = abs($bbox[5] - $bbox[1]);
			$width  = abs($bbox[4] - $bbox[0]);

			// Spacing trick ruins some segmenters, add space mt_randomly to the right of some groups
			$rpadding = mt_rand(0,35);

			// With our height and widths, we now have to determine the minimum necessary to make sure our letters never overflow the canvas.
			$totalWidth += ($width+$rpadding);

			if ($height > $totalHeight)
			{
				$totalHeight = $height;
			}

			// Last letter should always have 10 px after it
			if ($i === sizeof($answerArray)-1)
			{
				$rpadding += 10;
			}

			$bboxArray[] = [
				'bbox'     => $bbox,
				'angle'    => $angle,
				'text'     => $t,
				'height'   => $height,
				'width'    => $width,
				'rpadding' => $rpadding
			];
		}

		// Set up the GD image canvas and etc. for writing our letters
		$imgWidth    = max($this->getWidth($profile), $totalWidth) + 20; //+20 compensates for l/rpadding
		$imgHeight   = max($this->getHeight($profile), $totalHeight);
		$img         = imagecreatetruecolor($imgWidth, $imgHeight);

		$canvasColor = $this->getColorCanvas($profile);
		$canvas      = imagecolorallocate($img, $canvasColor[0], $canvasColor[1], $canvasColor[2]);
		$red         = imagecolorallocate($img, 255, 0, 0);
		$green       = imagecolorallocate($img, 0, 128, 0);
		$blue        = imagecolorallocate($img, 0, 0, 255);
		$black       = imagecolorallocate($img, 0, 0, 0);
		imagefilledrectangle($img, 0, 0, $imgWidth - 1, $imgHeight - 1, $canvas);

		if (function_exists("imageantialias"))
		{
			imageantialias($img, false);
		}

		$flourishes    = 0;
		$flourishesMin = $this->getFlourishesMin($profile); // Min per captcha
		$flourishesMax = $this->getFlourishesMax($profile); // Max per letter blocking

		// Create images for each of our elements with imagefttext.
		$x0 = 10;
		foreach ($bboxArray as $x => $bb)
		{
			// Random color for different groups
			$randomColor    = $this->getColorRandom($profile);
			$mt_randomColor = imagecolorallocate($img, $randomColor[0], $randomColor[1], $randomColor[2]);
			$fontSize       = $this->getFontSize($profile);
			$mt_randomSize  = mt_rand( $fontSize * 0.8, $fontSize * 1);

			imagefttext($img, $mt_randomSize, $bb['angle'], $x0, $this->getHeight($profile) * 0.75, $mt_randomColor, $this->getFontPath($font), $bb['text']);
			imagesetthickness($img, $mt_randomSize / mt_rand(8,12));

			// Add flourishes
			// Get our change of having one.
			$y = mt_rand(0, $flourishesMax);

			// If we have too few and this is our last chance, heap it on.
			if ($flourishes < $flourishesMin && $x == count($bboxArray))
			{
				$y = $flourishesMin - $flourishes;
			}

			for ($y = $y; $y < $flourishesMax; ++$y)
			{
				$choice = mt_rand(1,10);

				// Generate strikethrough
				if ($choice > 1 && $choice < 7)
				{
					if (function_exists("imageantialias"))
					{
						imageantialias($img, false);
					}

					imageline(
						$img,
						$x0,
						mt_rand($this->getHeight($profile) * 0.44, $this->getHeight($profile) * 0.55),
						$x0 + $bb['width'],
						mt_rand($this->getHeight($profile) * 0.44, $this->getHeight($profile) * 0.55),
						$mt_randomColor
					);

					if (function_exists("imageantialias"))
					{
						imageantialias($img, false);
					}
				}
				// Generate circle/arc
				else if ($choice >= 7)
				{
					$arcdeg   = mt_rand(90, 359.9);
					$arcstart = mt_rand(0, 360);
					$arcend   = ($arcdeg + $arcstart);
					$arcsize  = mt_rand($this->getHeight($profile) * 0.4, $this->getHeight($profile) * 0.9);

					imagearc(
						$img,
						mt_rand($x0, $x0 + $bb['width']),
						mt_rand(0, $this->getHeight($profile)),
						$arcsize,
						$arcsize,
						$arcstart,
						$arcend,
						$mt_randomColor
					);
				}
			}

			$x0 += ($bb['width'] + $bb['rpadding']);
		}

		if ($this->getSine($profile))
		{
			$factor     = mt_rand(5,10);
			$imgHeight += ($factor*2);
			$imgsine    = imagecreatetruecolor($imgWidth, $imgHeight);
			imagefilledrectangle($imgsine, 0, 0, $imgWidth - 1, $imgHeight - 1, $canvas);

			$imagesx = imagesx($img);
			$imagesy = imagesy($img);

			for ($x = 0; $x < $imagesx; ++$x)
			{
				for ($y = 0; $y < $imagesy; ++$y)
				{
					$rgba = imagecolorsforindex($img, imagecolorat($img, $x, $y));
					$col  = imagecolorallocate($imgsine, $rgba["red"], $rgba["green"], $rgba["blue"]);

					$yloc = imagesy($imgsine) + ($factor / 2);
					$distorted_y = ($y + round(( $factor * sin($x / 20) ))) + $yloc % $yloc;
					imagesetpixel($imgsine, $x, $distorted_y, $col);
				}
			}

			$img = $imgsine;
		}


		// Resize and crop
		$finalWidth  = $this->getWidth($profile);
		$finalHeight = $this->getHeight($profile);

		$srcWidth    = imagesx($img);
		$srcHeight   = imagesy($img);

		$imgFinal    = imagecreatetruecolor($finalWidth, $finalHeight);
		imagefilledrectangle($imgFinal, 0, 0, $finalWidth - 1, $finalHeight - 1, $canvas);

		// Try to match destination image by width
		$newWidth    = $finalWidth;
		$newHeight   = round($newWidth * ($srcHeight / $srcWidth));
		$newX        = 0;
		$newY        = round(($finalHeight - $newHeight) / 2);

		// If match by width failed and destination image does not fit, try by height
		if ($newHeight > $finalHeight)
		{
			$newHeight = $finalHeight;
			$newWidth  = round($newHeight * ($srcWidth / $srcHeight));
			$newX      = round(($finalWidth - $newWidth) / 2);
			$newY      = 0;
		}

		// Copy image on right place
		imagecopyresampled($imgFinal, $img, $newX, $newY, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);


		ob_start();
		imagepng($imgFinal);
		$imageData = ob_get_contents();
		ob_end_clean();

		return $imageData;
	}

	/**
	 * Generates a UTF-8 solution respecting multibyte characters.
	 *
	 * @static
	 * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
	 * @return string
	 */
	protected static function createSolution($profile)
	{
		mb_regex_encoding('UTF-8');
		mb_internal_encoding('UTF-8');

		$charSet   = static::getCharset($profile);
		$setLength = mb_strlen($charSet);
		$minLength = static::getLengthMin($profile);
		$maxLength = static::getLengthMax($profile);

		$solLength = rand($minLength, $maxLength);
		$solString = "";

		for ($i = 0; $i < $solLength; ++$i)
		{
			$pos = rand(0, $setLength);
			$solString .= mb_substr($charSet, $pos, 1);
		}

		return $solString;
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
			foreach ($item as $column => $datum)
			{
				if (is_resource($datum))
				{
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

		while ($length-- > 0)
		{
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
		if (config('captcha.escapeBinary', true) && DB::connection() instanceof \Illuminate\Database\PostgresConnection)
		{
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
		if (is_null($inet))
		{
			$inet = Request::ip();
		}

		if (config('captcha.escapeInet', true))
		{
			return static::escapeBinary(inet_pton($inet));
		}

		return $inet;
	}

	/**
	 * Finds an existing captcha for the requesting client or generates a new one.
	 *
	 * @static
	 * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
	 * @return Captcha
	 */
	public static function findOrCreateCaptcha($profile = "default")
	{
		$captcha = static::findWithSession();

		if ($captcha instanceof static)
		{
			return $captcha;
		}

		return static::createCaptcha($profile);
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

		$captcha = static::whereValid()
			->where('client_ip', $ip)
			->orderBy('created_at', 'desc')
			->first();

		if ($captcha && $captcha->exists)
		{
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

		if (isset(static::$modelSingletons[$hash]))
		{
			return static::$modelSingletons[$hash];
		}

		$model = static::where(['hash' => $hash])->first();

		if ($model)
		{
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
		if (is_null($session_id))
		{
			$session_id = Session::getId();
		}

		$hash = static::escapeBinary(hex2bin($session_id));

		if (isset(static::$modelSingletons[$hash]))
		{
			return static::$modelSingletons[$hash];
		}

		$model = static::whereValid()
			->where('client_session_id', $hash)
			->orderBy('created_at', 'desc')
			->first();

		if ($model)
		{
			static::$modelSingletons[$hash] = $model;
		}

		return $model;
	}

	/**
	 * Returns the captcha as form HTML.
	 *
	 * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
	 * @return string  html
	 */
	public function getAsHtml($profile = "default")
	{
		if (!$this->exists || !$this->getHash())
		{
			$captcha = $this->createCaptcha($profile);
		}
		else
		{
			$captcha = $this;
		}

		$html  = "";
		$html .= "<img src=\"" . url(config('captcha.route') . "/{$profile}/{$captcha->getHash()}.png") . "\" class=\"captcha\" data-expires-at=\"".$this->expires_at."\" />";
		$html .= "<input type=\"hidden\" name=\"captcha_hash\" value=\"{$captcha->getHash()}\" />";
		return $html;
	}

	/**
	 * Returns the captcha as a Laravel response.
	 *
	 * @param  string  $profile
	 * @return Response
	 */
	public function getAsResponse($profile = "default")
	{
		if (static::getProfile($profile) === false)
		{
			return abort(404);
		}

		$responseImage   = $this->createCaptchaImage($profile);
		$responseSize    = strlen($responseImage);
		$responseHeaders = [
			'Cache-Control'       => "no-cache, no-store, must-revalidate",
			'Pragma'              => "no-cache",
			'Expires'             => 0,
			'Last-Modified'       => gmdate(DATE_RFC1123, $this->created_at->timestamp),
			'Content-Disposition' => "inline",
			'Content-Length'      => $responseSize,
			'Content-Type'        => "image/png",
			'Filename'            => "{$this->getHash()}.png",
		];

		$response = \Response::make($responseImage, 200, $responseHeaders);

		return $response;
	}

	/**
	 * Returns our character set.
	 *
	 * @return string  of individual characters
	 */
	protected static function getCharset($profile)
	{
		return config("captcha.profiles.{$profile}.charset");
	}

	/**
	 * Get a canvas color.
	 *
	 * @return string  full path of a font file
	 */
	protected static function getColorCanvas($profile)
	{
		return config("captcha.profiles.{$profile}.canvas");
	}

	/**
	 * Get a random color.
	 *
	 * @return array  of colors
	 */
	protected static function getColorRandom($profile)
	{
		$colors = static::getColors($profile);
		return $colors[array_rand($colors)];
	}

	/**
	 * Get all of our fonts.
	 *
	 * @return array  of array of colors
	 */
	protected static function getColors($profile)
	{
		return config("captcha.profiles.{$profile}.colors");
	}

	/**
	 * Gets the time (in minutes) that a captcha will expire in.
	 *
	 * @return int  Expiry time in minutes
	 */
	protected static function getExpireTime()
	{
		return config("captcha.expires_in");
	}

	/**
	 * Appends an expiry time
	 *
	 * @return string  sha1 as hex
	 */
	public function getExpiresAtAttribute()
	{
		$timestamp = $this->created_at;

		if (!($timestamp instanceof Carbon))
		{
			$timestamp = $this->freshTimestamp();
		}

		return $timestamp->addMinutes((int) $this->getExpireTime())->timestamp;
	}

	/**
	 * Get a random font.
	 *
	 * @return string  full path of a font file
	 */
	protected static function getFontRandom($profile = false)
	{
		$fonts = static::getFonts($profile);
		return $fonts[array_rand($fonts)];
	}

	/**
	 * Get all of our fonts.
	 *
	 * @return array  of font file locations
	 */
	protected static function getFonts($profile = false)
	{
		if ($profile !== false && config("captcha.profiles.{$profile}.fonts", false) !== false)
		{
			return config("captcha.profiles.{$profile}.fonts");
		}

		return config("captcha.fonts");
	}

	/**
	 * Returns the absolute path to a font's file.
	 *
	 * @return string
	 */
	protected static function getFontPath(array $font)
	{
		return base_path() . DIRECTORY_SEPARATOR . $font['file'];
	}

	/**
	 * Returns our font size.
	 *
	 * @return int  in pixels
	 */
	protected static function getFontSize($profile)
	{
		return config("captcha.profiles.{$profile}.font_size");
	}

	/**
	 * Get our maximum number of flourishes.
	 *
	 * @return int  maximum number of flourishes
	 */
	protected static function getFlourishesMax($profile)
	{
		return config("captcha.profiles.{$profile}.flourishes_max");
	}

	/**
	 * Get our minimum number of flourishes.
	 *
	 * @return int  maximum number of flourishes
	 */
	protected static function getFlourishesMin($profile)
	{
		return config("captcha.profiles.{$profile}.flourishes_min");
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
	 * Returns our maximum image height.
	 *
	 * @return int  in pixels
	 */
	protected static function getHeight($profile)
	{
		return config("captcha.profiles.{$profile}.height");
	}

	/**
	 * Returns our maximum captcha length.
	 *
	 * @return int
	 */
	protected static function getLengthMax($profile)
	{
		return config("captcha.profiles.{$profile}.length_max");
	}

	/**
	 * Returns our minimum captcha length.
	 *
	 * @return int
	 */
	protected static function getLengthMin($profile)
	{
		return config("captcha.profiles.{$profile}.length_min");
	}

	/**
	 * Returns the full profile configuration.
	 *
	 * @return array|boolean  False if not found.
	 */
	protected static function getProfile($profile)
	{
		$profile = config("captcha.profiles.{$profile}");

		return is_array($profile) ? $profile : false;
	}

	/**
	 * Returns if this profile has a sine wave.
	 *
	 * @return boolean
	 */
	protected static function getSine($profile)
	{
		return !!config("captcha.profiles.{$profile}.sine");
	}

	/**
	 * Returns our maximum image width.
	 *
	 * @return int  in pixels
	 */
	protected static function getWidth($profile)
	{
		return config("captcha.profiles.{$profile}.width");
	}

	/**
	 * Returns if this profile is right-to-left.
	 *
	 * @return boolean
	 */
	protected static function isRTL($profile)
	{
		return !!config("captcha.profiles.{$profile}.rtl");
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
	 * Determines if the captcha has expired.
	 *
	 * @return boolean
	 */
	public function isExpired()
	{
		return $this->created_at->addMinutes($this->getExpireTime())->isPast();
	}

	/**
	 * Destroys a captcha with that hash and replaces it.
	 *
	 * @static
	 * @param  string  Optional. SHA1 hash. Checks latest if not specified.
	 * @return static
	 */
	public static function replace($hash = null)
	{
		if (!$hash)
		{
			$captcha = static::findWithSession();
		}
		else
		{
			$captcha = static::findWithHex($hash);
		}

		if ($captcha && $captcha->exists)
		{
			$waitTime = max($captcha->created_at->addSeconds(1)->timestamp - Carbon::now()->timestamp, 0);

			if (!$waitTime)
			{
				$profile = $captcha->profile;
				$captcha->forceDelete();

				return static::createCaptcha($profile);
			}
			else
			{
				abort(429);
			}
		}

		return static::createCaptcha();
	}

	/**
	 * Refines query to only contain "good" captchas.
	 *
	 * @return Builder
	 */
	public function scopeWhereValid($query)
	{
		return $query->where(function($query) {
			$query->whereNull('cracked_at');
			$query->where('created_at', '>=', Carbon::now()->subMinutes(static::getExpireTime()));
		});
	}

	/**
	 * Prepare a date for array / JSON serialization.
	 *
	 * @param  \DateTime  $date
	 *
	 * @return string
	 */
	protected function serializeDate(DateTime $date)
	{
		return $date->timestamp;
	}

	/**
	 * Returns the captcha as form HTML.
	 *
	 * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
	 * @return string  html
	 */
	public function toHtml()
	{
		return $this->getAsHtml($this->profile);
	}

	/**
	 * Handles binary data for database connections.
	 *
	 * @param  binary  $bin
	 * @return binary
	 */
	protected static function unescapeBinary($bin)
	{
		if (is_resource($bin))
		{
			$bin = stream_get_contents($bin);
		}

		if (DB::connection() instanceof \Illuminate\Database\PostgresConnection)
		{
			$bin = pg_unescape_bytea($bin);
		}

		return $bin;
	}

}
