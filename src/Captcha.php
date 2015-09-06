<?php namespace InfinityNext\BrennanCaptcha;

use Illuminate\Database\Eloquent\Model;
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
		return strtolower($answer) === strtolower($this->solution);
	}
	
	/**
	 * Generate the captcha image.
	 *
	 * @return array  ['model' => Captcha, 'captcha' => base64 data, 'height' => int, 'width' => int]
	 */
	public static function createCaptcha($profile = "default")
	{
		// Generate our answer from the charset and length config.
		$solution = static::createSolution($profile);
		
		$captcha = new static([
			'client_ip' => inet_pton(Request::ip()),
			'solution'  => $solution,
		]);
		
		$captcha->hash = hex2bin( sha1(config('app.key') . "-" . Request::ip() . "-" . $solution . "-" . $captcha->freshTimestamp()) );
		$captcha->save();
		
		return $captcha;
	}
	
	/**
	 * Creates a captcha image using the GD library.
	 *
	 * @author Frederick Brennan  @ctrlcctrlv
	 * @param  string  $profile
	 * @return GdResource
	 */
	protected function createGdCaptchaImage($profile)
	{
		// Pull our solution.
		$solution = $this->solution;
		
		// Select a font.
		$font = $this->getFontRandom();
		
		// Split the solution into pieces randomly between 1-3 chars long.
		$answerArray = array();
		
		for ($i = 0; $i < strlen($solution); $i)
		{
			$n = rand(1,3);
			
			$answerArray[] = substr($solution, $i, $n);
			
			$i += $n;
		}
		
		// We need to generate TTFBBOX for each of our substrings. This is the bounding box that the chosen font will take up and also the box within which we will draw our random dividng line.
		$bboxArray   = array();
		$totalWidth  = 0;
		$totalHeight = 0;
		
		foreach ($answerArray as $i => $t)
		{
			// gd supports writing text at an arbitrary angle. Using this can confuse OCR programs.
			$angle       = rand(-10,10);
			$bbox        = imagettfbbox($this->getFontSize($profile), $angle, $font, $t);
			$height      = abs($bbox[5] - $bbox[1]);
			$width       = abs($bbox[4] - $bbox[0]);
			$bboxArray[] = [
				'bbox'   => $bbox,
				'angle'  => $angle,
				'text'   => $t,
				'height' => $height,
				'width'  => $width
			];
			
			// With our height and widths, we now have to determine the minimum necessary to make sure our letters never overflow the canvas.
			$totalWidth += $width;
			
			if ($height > $totalHeight)
			{
				$totalHeight = $height;
			}
		}
		
		// Set up the GD image canvas and etc. for writing our letters
		$imgWidth  = max($this->getWidthMin($profile), $totalWidth);
		$imgHeight = max($this->getHeightMin($profile), $totalHeight);
		$img       = imagecreatetruecolor($imgWidth, $imgHeight);
		$white     = imagecolorallocate($img, 255, 255, 255);
		$black     = imagecolorallocate($img, 0, 0, 0);
		imagefilledrectangle($img, 0, 0, $imgWidth-1, $imgHeight-1, $white);
		imagesetthickness($img, 5);
		
		// Create images for each of our elements with IMGTTFTEXT.
		$x0 = 0;
		
		foreach ($bboxArray as $i => $bb)
		{
			imagettftext($img, $this->getFontSize($profile), $bb['angle'], $x0, $this->getHeightMin($profile)/2, $black, $font, $bb['text']);
			imageline($img, $x0, rand($this->getHeightMin($profile)*0.33, $this->getHeightMin($profile)*0.66), $x0+$bb['width'], rand($this->getHeightMin($profile)*0.33, $this->getHeightMin($profile)*0.66), $black);
			$x0 += $bb['width'];
		}
		
		ob_start();
		imagepng($img);
		$imageData = ob_get_contents();
		ob_end_clean();
		
		return $imageData;
	}
	
	protected static function createSolution($profile)
	{
		return substr(str_shuffle(static::getCharset($profile)), 0, rand(static::getLengthMin($profile), static::getLengthMax($profile)));
	}
	
	/**
	 * Passes SHA1 hex as binary to find model.
	 *
	 * @param  string  $hex
	 * @return Captcha
	 */
	public static function findWithHex($hex)
	{
		return static::where(['hash' => hex2bin($hex)])->first();
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
		$html .= "<input type=\"hidden\" name=\"captcha-hash\" value=\"{$captcha->getHash()}\" />";
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
	 * Get a random font.
	 *
	 * @return string  full path of a font file
	 */
	protected static function getFontRandom()
	{
		$fonts = static::getFonts();
		$font  = $fonts[array_rand($fonts)];
		
		return base_path() . "/" . $font;
	}
	
	/**
	 * Get all of our fonts.
	 *
	 * @return array  of font file locations
	 */
	protected static function getFonts()
	{
		return config("captcha.font.files");
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
	 * Returns captcha id as a hex string.
	 *
	 * @return string  in hex
	 */
	public function getHash()
	{
		return bin2hex($this->hash);
	}
	
	/**
	 * Returns our minimum image height.
	 *
	 * @return int  in pixels
	 */
	protected static function getHeightMin($profile)
	{
		return config("captcha.profiles.{$profile}.height_min");
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
	 * Returns our minimum image width.
	 *
	 * @return int  in pixels
	 */
	protected static function getWidthMin($profile)
	{
		return config("captcha.profiles.{$profile}.width_min");
	}
	
}
