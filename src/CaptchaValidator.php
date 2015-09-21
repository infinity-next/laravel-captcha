<?php namespace InfinityNext\BrennanCaptcha;

use Request;

class CaptchaValidator
{
	public function validateCaptcha($attribute, $value, $parameters)
	{
		return Captcha::answerCaptcha(Request::input('captcha-hash'), $value);
	}
}
