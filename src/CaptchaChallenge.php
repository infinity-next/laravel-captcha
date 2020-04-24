<?php

namespace InfinityNext\LaravelCaptcha;

use Carbon\Carbon;
use InfinityNext\LaravelCaptcha\Captcha as CaptchaModel;
use InfinityNext\LaravelCaptcha\Events\CaptchaWasCreated;
use Illuminate\Contracts\Support\Htmlable;
use Cache;
use Config;
use Event;
use InvalidArgumentException;
use OutOfRangeException;
use Request;
use Response;
use Session;

class CaptchaChallenge implements Htmlable
{
    /**
     * Captcha hash.
     *
     * @var string
     */
    protected $hash;

    /**
     * The captcha model.
     *
     * @var Captcha
     */
    protected $captcha;

    /**
     * Config profile.
     *
     * @var string
     */
    protected $profile = "default";

    /**
     * The session token.
     *
     * @var string
     */
    protected $session;

    /**
     * The solution challenge.
     *
     * @var string
     */
    protected $solution;

    /**
     * Generate the captcha image.
     *
     * @param  string  Optional. The hash to restore if supplied.
     * @return Captcha
     */
    public function __construct($hash = null)
    {
        $this->session = Session::getId();

        if (is_null($hash)) {
            $hash = $this->restoreSession();

            if (!$hash) {
                $captcha = $this->createCaptcha();
            }
        }
        else {
            $this->hash = $hash;
            $captcha = $this->restoreHash();

            if (!$captcha || !is_array($captcha)) {
                throw new InvalidArgumentException("Restoration from hash failed.");
                return $this;
            }
        }

        // restore from session
        //$captcha = CaptchaModel::findWithSession();
        //
        //if ($captcha instanceof Captcha) {
        //    $this->model = $captcha;
        //}
        //else {
        //    $this->model = $this->createCaptcha($profile);
        //}

        return $this;
    }

    public function createCaptcha()
    {
        // Generate our answer from the charset and length config.
        $timestamp = Carbon::now();
        $this->solution = $this->createSolution();
        $this->hash = hash('sha256', implode("-", [
            Config::get('app.key'),
            Request::ip(),
            $this->session,
            $this->solution,
            $timestamp,
        ]));

        //$captcha = new Captcha([
        //    'client_ip'         => CaptchaModel::escapeInet(),
        //    'client_session_id' => CaptchaModel::escapeBinary(hex2bin(Session::getId())),
        //    'solution'          => $solution,
        //    'profile'           => $this->profile,
        //]);
        //
        //$captcha->hash = CaptchaModel::escapeBinary(hex2bin(sha1(implode("-", [
        //    Config::get('app.key'),
        //    Request::ip(),
        //    Session::getId(),
        //    $solution,
        //    $captcha->freshTimestamp()
        //]))));
        //
        //$captcha->save();
        //
        //return $captcha;

        $this->captcha = [
            'hash' => $this->hash,
            'created_at' => $timestamp,
            'expires_at' => $timestamp->addMinutes($this->getExpireTime()),
            'solution' => $this->solution,
        ];

        $rememberTimer   = $this->getExpireTime();
        $rememberClosure = function () {
            return $this->captcha;
        };

        Cache::remember("laravel-captcha.session.{$this->session}", $rememberTimer, $rememberClosure);
        Cache::remember("laravel-captcha.captcha.{$this->hash}", $rememberTimer, $rememberClosure);

        Event::dispatch(new CaptchaWasCreated($this));
        return $this->captcha;
    }

    /**
     * Generate the captcha image.
     *
     * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
     * @return Captcha
     */
    public function createCaptchaImage($recreate = false)
    {
        $rememberTimer   = $this->getExpireTime();
        $rememberKey     = "laravel-captcha.captcha-image.{$this->hash}";
        $rememberClosure = function () {
            return $this->createGdCaptchaImage();
        };

        if ($recreate) {
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
    public function createGdCaptchaImage()
    {
        // Clock generation start time for debugging.
        $startTime = microtime(true);

        // Find a font.
        $font = $this->getFontRandom();

        if (!isset($font['stroke']) || !is_numeric($font['stroke']) || $font['stroke'] <= 0) {
            $font['stroke'] = 3;
        }

        // Get our solution.
        $solution = $this->solution;

        // Reverse the solution if the profile is for right-to-left script.
        if ($this->isRtl()) {
            $solution = $this->mb_strrev($solution);
        }

        // Split the solution into pieces between 1 and 3 characters long.
        $answerArray = array();
        for ($i = 0; $i < mb_strlen($solution); $i) {
            $n = mt_rand(1,3);

            $answerArray[] = mb_substr($solution, $i, $n);

            $i += $n;
        }

        // We need to generate TTFBBOX for each of our substrings.
        // This is the bounding box that the chosen font will take up and also the box within which we will draw our mt_random dividng line.
        $bboxArray   = array();
        $totalWidth  = 0;
        $totalHeight = 0;

        foreach ($answerArray as $i => $t) {
            // gd supports writing text at an arbitrary angle. Using this can confuse OCR programs.
            $angle  = mt_rand(-10,10);

            $bbox   = imageftbbox($this->getFontSize(), $angle, $this->getFontPath($font), $t);
            $height = abs($bbox[5] - $bbox[1]);
            $width  = abs($bbox[4] - $bbox[0]);

            // Spacing trick ruins some segmenters, add space mt_randomly to the right of some groups
            $rpadding = mt_rand(0,35);

            // With our height and widths, we now have to determine the minimum necessary to make sure our letters never overflow the canvas.
            $totalWidth += ($width+$rpadding);

            if ($height > $totalHeight) {
                $totalHeight = $height;
            }

            // Last letter should always have 10 px after it
            if ($i === sizeof($answerArray)-1) {
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
        $imgWidth    = max($this->getWidth(), $totalWidth) + 20; //+20 compensates for l/rpadding
        $imgHeight   = max($this->getHeight(), $totalHeight);
        $img         = imagecreatetruecolor($imgWidth, $imgHeight);

        $canvasColor = $this->getColorCanvas();
        $canvas      = imagecolorallocate($img, $canvasColor[0], $canvasColor[1], $canvasColor[2]);
        $red         = imagecolorallocate($img, 255, 0, 0);
        $green       = imagecolorallocate($img, 0, 128, 0);
        $blue        = imagecolorallocate($img, 0, 0, 255);
        $black       = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $imgWidth - 1, $imgHeight - 1, $canvas);

        if (function_exists("imageantialias")) {
            imageantialias($img, false);
        }

        $flourishes    = 0;
        $flourishesMin = $this->getFlourishesMin(); // Min per captcha
        $flourishesMax = $this->getFlourishesMax(); // Max per letter blocking

        // Create images for each of our elements with imagefttext.
        $x0 = 10;
        foreach ($bboxArray as $x => $bb) {
            // Random color for different groups
            $randomColor    = $this->getColorRandom();
            $mt_randomColor = imagecolorallocate($img, $randomColor[0], $randomColor[1], $randomColor[2]);
            $fontSize       = $this->getFontSize();
            $mt_randomSize  = mt_rand( $fontSize * 0.8, $fontSize * 1);

            imagefttext($img, $mt_randomSize, $bb['angle'], $x0, $this->getHeight() * 0.75, $mt_randomColor, $this->getFontPath($font), $bb['text']);
            imagesetthickness($img, $mt_randomSize / mt_rand(8,12));

            // Add flourishes
            // Get our change of having one.
            $y = mt_rand(0, $flourishesMax);

            // If we have too few and this is our last chance, heap it on.
            if ($flourishes < $flourishesMin && $x == count($bboxArray)) {
                $y = $flourishesMin - $flourishes;
            }

            for ($y = $y; $y < $flourishesMax; ++$y) {
                $choice = mt_rand(1,10);

                // Generate strikethrough
                if ($choice > 1 && $choice < 7) {
                    if (function_exists("imageantialias")) {
                        imageantialias($img, false);
                    }

                    imageline(
                        $img,
                        $x0,
                        mt_rand($this->getHeight() * 0.44, $this->getHeight() * 0.55),
                        $x0 + $bb['width'],
                        mt_rand($this->getHeight() * 0.44, $this->getHeight() * 0.55),
                        $mt_randomColor
                    );

                    if (function_exists("imageantialias")) {
                        imageantialias($img, false);
                    }
                }
                // Generate circle/arc
                else if ($choice >= 7) {
                    $arcdeg   = mt_rand(90, 359.9);
                    $arcstart = mt_rand(0, 360);
                    $arcend   = ($arcdeg + $arcstart);
                    $arcsize  = mt_rand($this->getHeight() * 0.4, $this->getHeight() * 0.9);

                    imagearc(
                        $img,
                        mt_rand($x0, $x0 + $bb['width']),
                        mt_rand(0, $this->getHeight()),
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

        if ($this->getSine()) {
            $factor     = mt_rand(5,10);
            $imgHeight += ($factor*2);
            $imgsine    = imagecreatetruecolor($imgWidth, $imgHeight);
            imagefilledrectangle($imgsine, 0, 0, $imgWidth - 1, $imgHeight - 1, $canvas);

            $imagesx = imagesx($img);
            $imagesy = imagesy($img);

            for ($x = 0; $x < $imagesx; ++$x) {
                for ($y = 0; $y < $imagesy; ++$y) {
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
        $finalWidth  = $this->getWidth();
        $finalHeight = $this->getHeight();

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
        if ($newHeight > $finalHeight) {
            $newHeight = $finalHeight;
            $newWidth  = round($newHeight * ($srcWidth / $srcHeight));
            $newX      = round(($finalWidth - $newWidth) / 2);
            $newY      = 0;
        }

        // Copy image on right place
        imagecopyresampled($imgFinal, $img, $newX, $newY, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);


        ob_start();
        imagejpeg($imgFinal);
        $imageData = ob_get_contents();
        ob_end_clean();

        return $imageData;
    }

    /**
     * Generates a UTF-8 solution respecting multibyte characters.
     *
     * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
     * @return string
     */
    protected function createSolution()
    {
        mb_regex_encoding('UTF-8');
        mb_internal_encoding('UTF-8');

        $charSet   = $this->getCharset();
        $setLength = mb_strlen($charSet);
        $minLength = $this->getLengthMin();
        $maxLength = $this->getLengthMax();

        $solLength = rand($minLength, $maxLength);
        $solString = "";

        for ($i = 0; $i < $solLength; ++$i) {
            $pos = rand(0, $setLength);
            $solString .= mb_substr($charSet, $pos, 1);
        }

        return $solString;
    }

    /**
     * Returns the full profile configuration.
     *
     * @return array|bool  False if not found.
     */
    public function fetchProfile($profile)
    {
        $profile = Config::get("captcha.profiles.{$profile}");

        return is_array($profile) ? $profile : false;
    }

    /**
     * Returns our character set.
     *
     * @return string  of individual characters
     */
    public function getCharset()
    {
        return Config::get("captcha.profiles.{$this->profile}.charset");
    }

    /**
     * Get a canvas color.
     *
     * @return string  full path of a font file
     */
    public function getColorCanvas()
    {
        return Config::get("captcha.profiles.{$this->profile}.canvas");
    }

    /**
     * Get a random color.
     *
     * @return array  of colors
     */
    public function getColorRandom()
    {
        $colors = $this->getColors();
        return $colors[array_rand($colors)];
    }

    /**
     * Get all of our fonts.
     *
     * @return array  of array of colors
     */
    public function getColors()
    {
        return Config::get("captcha.profiles.{$this->profile}.colors");
    }

    /**
     * Gets the time (in minutes) that a captcha will expire in.
     *
     * @return int  Expiry time in minutes
     */
    public function getExpireTime()
    {
        return Config::get("captcha.expires_in") * 60;
    }

    /**
     * Get all of our fonts.
     *
     * @return array  of font file locations
     */
    public function getFonts($profile = false)
    {
        if ($profile !== false && Config::get("captcha.profiles.{$profile}.fonts", false) !== false) {
            return Config::get("captcha.profiles.{$profile}.fonts");
        }

        return Config::get("captcha.fonts");
    }


    /**
     * Returns the absolute path to a font's file.
     *
     * @param  array  $font
     * @return string|bool
     *
     * @throws OutOfRangeException
     */
    public function getFontPath(array $font)
    {
        $paths = [
            'fonts',
            'vendor/infinity-next/laravel-captcha/fonts',
        ];

        foreach ($paths as $path) {
            $fullPath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $font['file'];

            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        throw new OutOfRangeException("Didn't find requested font.");
        return false;
    }

    /**
     * Get a random font.
     *
     * @return string  full path of a font file
     */
    public function getFontRandom($profile = false)
    {
        $fonts = $this->getFonts($profile);
        return $fonts[array_rand($fonts)];
    }

    /**
     * Returns our font size.
     *
     * @return int  in pixels
     */
    public function getFontSize()
    {
        return Config::get("captcha.profiles.{$this->profile}.font_size");
    }

    /**
     * Get our maximum number of flourishes.
     *
     * @return int  maximum number of flourishes
     */
    public function getFlourishesMax()
    {
        return Config::get("captcha.profiles.{$this->profile}.flourishes_max");
    }

    /**
     * Get our minimum number of flourishes.
     *
     * @return int  maximum number of flourishes
     */
    public function getFlourishesMin()
    {
        return Config::get("captcha.profiles.{$this->profile}.flourishes_min");
    }

    public function getHash()
    {
        return $this->hash;
    }

    /**
     * Returns our maximum image height.
     *
     * @return int  in pixels
     */
    public function getHeight()
    {
        return Config::get("captcha.profiles.{$this->profile}.height");
    }

    /**
     * Returns our maximum captcha length.
     *
     * @return int
     */
    public function getLengthMax()
    {
        return Config::get("captcha.profiles.{$this->profile}.length_max");
    }

    /**
     * Returns our minimum captcha length.
     *
     * @return int
     */
    public function getLengthMin()
    {
        return Config::get("captcha.profiles.{$this->profile}.length_min");
    }

    /**
     * @return string
     */
    public function getProfile()
    {
        return $this->profile;
    }

    /**
     * Returns if this profile has a sine wave.
     *
     * @return bool
     */
    public function getSine()
    {
        return !!Config::get("captcha.profiles.{$this->profile}.sine");
    }

    public function getSolution()
    {
        return $this->solution;
    }

    /**
     * Returns our maximum image width.
     *
     * @return int  in pixels
     */
    public function getWidth()
    {
        return Config::get("captcha.profiles.{$this->profile}.width");
    }

    /**
     * Returns if this profile is right-to-left.
     *
     * @return boolean
     */
    public function isRtl()
    {
        return !!Config::get("captcha.profiles.{$this->profile}.rtl");
    }

    /**
     * Destroys a captcha with that hash and replaces it.
     *
     * @param  string  Optional. SHA1 hash. Checks latest if not specified.
     * @return Captcha
     */
    public function replace($hash = null)
    {
        if ($this->captcha instanceof Captcha) {
            $captcha = $this->captcha;

            if ($captcha && $captcha->exists) {
                $profile = $captcha->profile;
                $captcha->forceDelete();
            }
        }
        else {
            Cache::forget("laravel-captcha.session.{$this->session}");
            Cache::forget("laravel-captcha.captcha.{$this->hash}");
            Cache::forget("laravel-captcha.captcha-image.{$this->hash}");
        }

        return $this->createCaptcha();
    }

    /**
     * Returns the captcha as a Laravel response.
     *
     * @return Response
     */
    public function response()
    {
        $responseImage   = $this->createCaptchaImage();
        $responseSize    = strlen($responseImage);
        $responseHeaders = [
            'Cache-Control'       => "no-cache, no-store, must-revalidate",
            'Pragma'              => "no-cache",
            'Expires'             => 0,
            'Last-Modified'       => gmdate(DATE_RFC1123, $this->captcha['created_at']->timestamp),
            'Content-Disposition' => "inline",
            'Content-Length'      => $responseSize,
            'Content-Type'        => "image/jpeg",
            'Filename'            => "{$this->hash}.jpg",
        ];

        return Response::make($responseImage, 200, $responseHeaders);
    }

    public function restoreCaptcha(array $captcha)
    {
        $this->captcha = $captcha;
        $this->hash = $captcha['hash'];
        $this->solution = $captcha['solution'];
    }

    /**
     * Restores a captcha by hash directly.
     *
     * @return string
     */
    public function restoreHash()
    {
        $captcha = Cache::get("laravel-captcha.captcha.{$this->hash}");

        if (!is_null($captcha)) {
            $this->restoreCaptcha($captcha);
        }

        return $captcha;
    }

    /**
     * Returns the captcha hash for this session.
     *
     * @return string
     */
    public function restoreSession()
    {
        $captcha = Cache::get("laravel-captcha.session.{$this->session}");

        if (!is_null($captcha)) {
            $this->restoreCaptcha($captcha);
        }

        return $captcha;
    }

    public function setProfile($profile = "default")
    {
        if ($this->fetchProfile($profile) === false) {
            throw new InvalidArgumentException("Profile supplied does not exist");
            return false;
        }

        $this->profile = $profile;
        return $this->profile;
    }

    /**
     * Returns the captcha as form HTML.
     *
     * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
     * @return string  html
     */
    public function toHtml()
    {
        $html  = "";
        $html .= "<img src=\"" . route('captcha.image', [ 'captcha' => $this->getHash() ]) . "\" class=\"captcha\" />";
        $html .= "<input type=\"hidden\" name=\"captcha_hash\" value=\"{$this->getHash()}\" />";

        return $html;
    }

    public function toJson()
    {
        return [
            'hash_string' => $this->getHash(),
            'expires_at' => $this->getExpireTime()
        ];
    }
}
