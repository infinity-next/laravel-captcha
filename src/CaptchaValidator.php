<?php

namespace InfinityNext\LaravelCaptcha;

use InfinityNext\LaravelCaptcha\CaptchaAnswer;
use Request;

class CaptchaValidator
{
    public function validateCaptcha($attribute, $value, $parameters)
    {
        $captcha  = Request::input("{$attribute}_hash");
        $answer = new CaptchaAnswer($captcha, $value);

        return $answered;
    }
}
