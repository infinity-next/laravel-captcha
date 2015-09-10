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
		// Find a font.
		$font       = $this->getFontRandom();
		
		if (!isset($font['stroke']) || !is_numeric($font['stroke']) || $font['stroke'] <= 0)
		{
			$font['stroke'] = 3;
		}
		
		// Get our solution.
		$solution = $this->solution;
		
		// Split the solution into pieces between 1 and 3 characters long.
		$answerArray = array();
		for ($i = 0; $i < strlen($solution); $i)
		{
			$n = mt_rand(1,3);
			
			$answerArray[] = substr($solution, $i, $n);
			
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
			
			$bbox   = imagettfbbox($this->getFontSize($profile), $angle, $this->getFontPath($font), $t);
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
		$imgWidth    = max($this->getWidthMin($profile), $totalWidth) + 20; //+20 compensates for l/rpadding
		$imgHeight   = max($this->getHeightMin($profile), $totalHeight);
		$img         = imagecreatetruecolor($imgWidth, $imgHeight);
		
		$canvasColor = $this->getColorCanvas($profile);
		$canvas      = imagecolorallocate($img, $canvasColor[0], $canvasColor[1], $canvasColor[2]);
		$red         = imagecolorallocate($img, 255, 0, 0);
		$green       = imagecolorallocate($img, 0, 128, 0);
		$blue        = imagecolorallocate($img, 0, 0, 255);
		$black       = imagecolorallocate($img, 0, 0, 0);
		imagefilledrectangle($img, 0, 0, $imgWidth - 1, $imgHeight - 1, $canvas);
		imagesetthickness($img, $font['stroke']);
		
		// Create images for each of our elements with IMGTTFTEXT.
		$x0 = 10;
		
		foreach ($bboxArray as $i => $bb)
		{
			// Random color for different groups
			$randomColor    = $this->getColorRandom($profile);
			$mt_randomColor = imagecolorallocate($img, $randomColor[0], $randomColor[1], $randomColor[2]);
			
			imagettftext($img, $this->getFontSize($profile), $bb['angle'], $x0, $this->getHeightMin($profile) * 0.75, $mt_randomColor, $this->getFontPath($font), $bb['text']);
			
			$choice = mt_rand(1,10);
			
			// Generate strikethrough
			if ($choice > 1 && $choice < 7)
			{
				imageline(
					$img,
					$x0,
					mt_rand($this->getHeightMin($profile) * 0.33, $this->getHeightMin($profile) * 0.66),
					$x0 + $bb['width'],
					mt_rand($this->getHeightMin($profile) * 0.33, $this->getHeightMin($profile) * 0.66),
					$mt_randomColor
				);
			}
			// Generate circle/arc
			else if ($choice >= 7)
			{
				$arcsize = mt_rand(50,80);
				imagearc(
					$img,
					mt_rand($x0, $x0 + $bb['width']),
					mt_rand(0, $this->getHeightMin($profile)),
					$arcsize,
					$arcsize,
					0,
					359.9, // GD has an issue with thickness and circles.
					$mt_randomColor
				);
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
		$finalWidth  = $this->getWidthMax($profile);
		$finalHeight = $this->getHeightMax($profile);
		
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
	 * Get a random font.
	 *
	 * @return string  full path of a font file
	 */
	protected static function getFontRandom()
	{
		$fonts = static::getFonts();
		return $fonts[array_rand($fonts)];
	}
	
	/**
	 * Get all of our fonts.
	 *
	 * @return array  of font file locations
	 */
	protected static function getFonts()
	{
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
	 * Returns captcha id as a hex string.
	 *
	 * @return string  in hex
	 */
	public function getHash()
	{
		return bin2hex($this->hash);
	}
	
	/**
	 * Returns our maximum image height.
	 *
	 * @return int  in pixels
	 */
	protected static function getHeightMax($profile)
	{
		return config("captcha.profiles.{$profile}.height_max");
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
	protected static function getWidthMax($profile)
	{
		return config("captcha.profiles.{$profile}.width_max");
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
