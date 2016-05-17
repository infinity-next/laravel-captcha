<?php namespace InfinityNext\LaravelCaptcha;

use Request;

class CaptchaValidator
{
	public function validateCaptcha($attribute, $value, $parameters)
	{
		$captcha  = Request::input("{$attribute}_hash");
		$answered = !!Captcha::answerCaptcha($captcha, $value);

		return $answered;
	}
}
