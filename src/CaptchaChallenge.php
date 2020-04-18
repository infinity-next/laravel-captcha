<?php

namespace InfinityNext\LaravelCaptcha;

use Carbon\Carbon;
use InfinityNext\LaravelCaptcha\Captcha as CaptchaModel;
use Cache;
use Config;
use InvalidArgumentException;
use OutOfRangeException;
use Request;
use Session;

class CaptchaChallenge
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
    protected $profile;

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
     * @param  string  $profile  Optional. Captcha config profile. Defaults to "default".
     * @return Captcha
     */
    public function __construct($profile = "default")
    {
        if ($this->getProfile($profile) === false) {
            throw new InvalidArgumentException("Profile supplied does not exist");
            return false;
        }

        $this->profile = $profile;
        $this->session = Session::getId();
        $hash = $this->restore();

        if (!$hash) {
            $captcha = $this->createCaptcha();
            $hash = $captcha['hash'];
            $solution = $captcha['solution'];
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

        $this->captcha = $captcha;
        $this->hash = $hash;
        $this->solution = $solution;

        return $this;
    }

    public function createCaptcha()
    {
        $rememberTimer   = $this->getExpireTime();
        $rememberKey     = "laravel-captcha.captcha.{$this->hash}";
        $rememberClosure = function () {
            // Generate our answer from the charset and length config.
            $solution = $this->createSolution();
            $timestamp = Carbon::now();

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

            $hash = hash('sha256', implode("-", [
                Config::get('app.key'),
                Request::ip(),
                $this->session,
                $solution,
                $timestamp,
            ]));

            return [
                'hash' => $hash,
                'created_at' => $timestamp,
                'expires_at' => $timestamp->addMinutes($this->getExpireTime()),
                'solution' => $solution,
            ];
        };

        return Cache::remember($rememberKey, $rememberTimer, $rememberClosure);
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
        imagewebp($imgFinal);
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
        return Config::get("captcha.expires_in");
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
            'fonts/',
            //'vendor/infinity-next/laravel-captcha/fonts',
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
     * Returns the full profile configuration.
     *
     * @return array|bool  False if not found.
     */
    public function getProfile($profile)
    {
        $profile = Config::get("captcha.profiles.{$profile}");

        return is_array($profile) ? $profile : false;
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
        if ($this->model instanceof Captcha) {
            $captcha = $this->model;
        }

        if ($captcha && $captcha->exists) {
            $profile = $captcha->profile;
            $captcha->forceDelete();
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
            'Last-Modified'       => gmdate(DATE_RFC1123, $this->created_at->timestamp),
            'Content-Disposition' => "inline",
            'Content-Length'      => $responseSize,
            'Content-Type'        => "image/png",
            'Filename'            => "{$this->hash}.webp",
        ];

        return Response::make($responseImage, 200, $responseHeaders);
    }

    /**
     * Returns the captcha hash for this session.
     *
     * @return string
     */
    public function restore()
    {
        return Cache::get("laravel-captcha.captcha.{$this->session}");
    }
}
