<?php

namespace InfinityNext\LaravelCaptcha;

use InfinityNext\LaravelCaptcha\CaptchaAnswer;
use InvalidArgumentException;
use Request;

class CaptchaValidator
{
    public function validateCaptcha($attribute, $value, $parameters)
    {
        $captcha  = Request::input("{$attribute}_hash");

        try {
            $answer = new CaptchaAnswer($captcha, $value);
        }
        catch (InvalidArgumentException $e) {
            // expired or bad
            return false;
        }

        return $answer->isAnswered();
    }
}
