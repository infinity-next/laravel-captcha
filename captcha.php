<?php
error_reporting(E_ALL);
ini_set('display_errors', true);

// You're going to have to add these to Laravel's config system :)
$config = array();
$config['captcha_charset'] = 'abcdeFGhijklmnopQrstuvwxyZ';
$config['captcha_min'] = 6;
$config['captcha_max'] = 8;
// CAPTCHA sizes can vary a bit depending on font sizes and font metrics, but these are minimums.
$config['captcha_min_width'] = 200;
$config['captcha_min_height'] = 80;
$config['captcha_fontsdir'] = 'fonts/';
$config['captcha_fontpxsize'] = 40;

// Get a random font from FONTLIST.txt
function random_font() {
	global $config;

	$f = file($config['captcha_fontsdir'] . 'FONTLIST.txt');
	
	return trim( realpath('.') . '/' . $config['captcha_fontsdir'] . $f[array_rand($f)] );
}

/* Actually generate the CAPTCHA. This returns an array like
   generate_captcha() -> array('answer' => plaintext, 'captcha' => raw data);
*/
function generate_captcha() {
	global $config;

	// PHP requires full paths for GD TTF fonts. So we have to do this. See http://stackoverflow.com/a/10366726
	putenv('GDFONTPATH=' . realpath('.')); 

	// Some set up
	$font = random_font();

	$answer = substr(str_shuffle($config['captcha_charset']), 0, rand($config['captcha_min'], $config['captcha_max']));	

	// Split the answer into pieces randomly between 1-3 chars long.
	$answerArray = array();
	for ($i = 0; $i < strlen($answer); $i) {
		$n = rand(1,3);

		$answerArray[] = substr($answer, $i, $n);

		$i+=$n;
	}

	// We need to generate TTFBBOX for each of our substrings. This is the bounding box that the chosen font will take up and also the box within which we will draw our random dividng line.
	$bboxArray = array();
	$totalWidth=0;
	$totalHeight=0;
	foreach ($answerArray as $i => $t) {
		// gd supports writing text at an arbitrary angle. Using this can confuse OCR programs.
		$angle = rand(-10,10);

		$bbox = imagettfbbox($config['captcha_fontpxsize'], $angle, $font, $t);
		$height = abs($bbox[5] - $bbox[1]);
		$width = abs($bbox[4] - $bbox[0]);
		$bboxArray[] = array('bbox' => $bbox, 'angle' => $angle, 'text' => $t, 'height' => $height, 'width' => $width);
		// With our height and widths, we now have to determine the minimum necessary to make sure our letters never overflow the canvas.
		$totalWidth+=$width;
		if ($height > $totalHeight) $totalHeight = $height;
	}

	// Set up the GD image canvas and etc. for writing our letters
	$imgWidth = max($config['captcha_min_width'], $totalWidth);
	$imgHeight = max($config['captcha_min_height'], $totalHeight);
	$img = imagecreatetruecolor($imgWidth, $imgHeight);
	$white = imagecolorallocate($img, 255, 255, 255);
	$black = imagecolorallocate($img, 0, 0, 0);
	imagefilledrectangle($img, 0, 0, $imgWidth-1, $imgHeight-1, $white);
	imagesetthickness($img, 5);

	// Create images for each of our elements with IMGTTFTEXT.
	$x0 = 0;
	foreach ($bboxArray as $i => $bb) {
		imagettftext($img, $config['captcha_fontpxsize'], $bb['angle'], $x0, $config['captcha_min_height']/2, $black, $font, $bb['text']);	
		imageline($img, $x0, rand($config['captcha_min_height']*0.33, $config['captcha_min_height']*0.66), $x0+$bb['width'], rand($config['captcha_min_height']*0.33, $config['captcha_min_height']*0.66), $black);
		$x0+=$bb['width'];
	}

	// The reason I'm doing this is because GD's imagepng thing doesn't have an option for storing in a variable, it immediately prints the image data to stdout. To prevent that and so that I can store my image in return value, I use PHP's output buffer manipulation.
	ob_start();
	imagepng($img);
	$image_data = ob_get_contents();
	ob_end_clean();

	return array('answer' => $answer, 'captcha' => $image_data, 'height' => $imgHeight, 'width' => $imgWidth);
}

$captcha = generate_captcha();

?>
Size of image: <?=$captcha['width'] . 'x' . $captcha['height']?> <br />
CAPTCHA answer: <?=$captcha['answer']?>
<br/><br/>
<img src="data:image/png;base64,<?=base64_encode($captcha['captcha'])?>">

