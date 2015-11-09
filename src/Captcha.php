<?php namespace InfinityNext\BrennanCaptcha;

use Illuminate\Database\Eloquent\Model;
use DB;
use Request;

class Captcha extends Model {
	
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table;
	
	/**
	 * The primary key that is used by ::get()
	 *
	 * @var string
	 */
	protected $primaryKey = 'captcha_id';
	
	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = ['hash', 'client_ip', 'solution', 'created_at', 'cracked_at'];
	
	/**
	 * Attributes to be given in JSON responses for captchas.
	 *
	 * @var array
	 */
	protected $visible = ['hash_string', 'created_at'];
	
	/**
	 * Pseudo-attributes to be given in JSON responses.
	 *
	 * @var array
	 */
	protected $appends = ['hash_string'];
	 
	
	/**
	 * Disables `created_at` and `updated_at` auto-management.
	 *
	 * @var boolean
	 */
	public $timestamps = false;
	
	/**
	 * Attributes which are automatically sent through a Carbon instance on load.
	 *
	 * @var array
	 */
	protected $dates = ['created_at', 'cracked_at'];
	
	
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
	 * @return boolean  If token was spent.
	 */
	public static function answerCaptcha($hash, $answer)
	{
		$captcha = static::findWithHex($hash);
		
		if ($captcha instanceof static)
		{
			if ($captcha->isCracked() || $captcha->isExpired())
			{
				return false;
			}
			
			if ($captcha->checkAnswer($answer))
			{
				$captcha->cracked_at = $captcha->freshTimestamp();
				$captcha->save();
				
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Determines if the supplied answer matches the solution string.
	 *
	 * @param  string  $answer
	 * @return boolean  If token was spent.
	 */
	public function checkAnswer($answer)
	{
		return mb_strtolower($answer) === mb_strtolower($this->solution);
	}
	
	/**
	 * Generate the captcha image.
	 *
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
		$ip = static::escapeBinary(inet_pton(Request::ip()));
		
		
		$captcha = new static([
			'client_ip' => $ip,
			'solution'  => $solution,
		]);
		
		$captcha->hash = static::escapeBinary(hex2bin( sha1(config('app.key') . "-" . Request::ip() . "-" . $solution . "-" . $captcha->freshTimestamp()) ));
		$captcha->save();
		
		return $captcha;
	}
	
	/**
	 * Creates a captcha image using the GD library.
	 *
	 * @author Fredrick Brennan  @ctrlcctrlv
	 * @param  string  $profile
	 * @return GdResource
	 */
	protected function createGdCaptchaImage($profile)
	{
		// Find a font.
		$font       = $this->getFontRandom($profile);
		
		if (!isset($font['stroke']) || !is_numeric($font['stroke']) || $font['stroke'] <= 0)
		{
			$font['stroke'] = 3;
		}
		
		// Get our solution.
		$solution = $this->solution;
		
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
		
		// Create images for each of our elements with imagefttext.
		$x0 = 10;
		
		foreach ($bboxArray as $x => $bb)
		{
			// Random color for different groups
			$randomColor    = $this->getColorRandom($profile);
			$mt_randomColor = imagecolorallocate($img, $randomColor[0], $randomColor[1], $randomColor[2]);
			
			imagefttext($img, $this->getFontSize($profile), $bb['angle'], $x0, $this->getHeight($profile) * 0.75, $mt_randomColor, $this->getFontPath($font), $bb['text']);
			imagesetthickness($img, $this->getFontSize($profile) / mt_rand(10,14));
			
			// Add flourishes
			for ($y = mt_rand(0,$this->getFlourishes($profile)); $y < $this->getFlourishes($profile); ++$y)
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
						mt_rand($this->getHeight($profile) * 0.33, $this->getHeight($profile) * 0.66),
						$x0 + $bb['width'],
						mt_rand($this->getHeight($profile) * 0.33, $this->getHeight($profile) * 0.66),
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
			
			for ($x = 0; $x < imagesx($img); $x++)
			{
				for ($y = 0; $y < imagesy($img); $y++)
				{
					$rgba = imagecolorsforindex($img, imagecolorat($img, $x, $y));
					$col  = imagecolorallocate($imgsine, $rgba["red"], $rgba["green"], $rgba["blue"]);
					
					$yloc = imagesy($imgsine) + ($factor / 2);
					$distorted_y = ($y + round(( $factor*sin($x/20) ))) + $yloc % $yloc;
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
	 * Handles binary data for database connections.
	 *
	 * @param  binary  $bin
	 * @return binary
	 */
	private static function escapeBinary($bin)
	{
		if (DB::connection() instanceof \Illuminate\Database\PostgresConnection)
		{
			$bin = pg_escape_bytea($bin);
		}
		
		return $bin;
	}
	
	/**
	 * Handles binary data for database connections.
	 *
	 * @param  binary  $bin
	 * @return binary
	 */
	private static function unescapeBinary($bin)
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
	
	/**
	 * Passes SHA1 hex as binary to find model.
	 *
	 * @param  string  $hex
	 * @return Captcha
	 */
	public static function findWithHex($hex)
	{
		$hash = static::escapeBinary(hex2bin($hex));
		return static::where(['hash' => $hash])->first();
	}
	
	/**
	 * Returns the captcha as form HTML.
	 *
	 * @param  string  $profile
	 * @return string  html
	 */
	public function getAsHtml($profile = "default")
	{
		$captcha = $this->createCaptcha();
		
		$html  = "";
		$html .= "<img src=\"" . url(config('captcha.route') . "/{$profile}/{$captcha->getHash()}.png") . "\" class=\"captcha\" />";
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
		
		$cacheTime       = 24 * 60 * 60;
		$responseImage   = $this->createGdCaptchaImage($profile);
		$responseSize    = strlen($responseImage);
		$responseHeaders = [
			'Cache-Control'       => "public, max-age={$cacheTime}, pre-check={$cacheTime}",
			'Expires'             => gmdate(DATE_RFC1123, time() + $cacheTime),
			'Last-Modified'       => gmdate(DATE_RFC1123, $this->created_at->timestamp),
			'Content-Disposition' => "inline",
			'Content-Length'      => $responseSize,
			'Content-Type'        => "image/png",
			'Filename'            => "{$this->getHash()}.png",
		];
		
		$response = \Response::stream(function() use ($responseImage) {
			echo $responseImage;
		}, 200, $responseHeaders);
		
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
	protected static function getFlourishes($profile)
	{
		return config("captcha.profiles.{$profile}.flourishes");
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
}
