<?php

namespace InfinityNext\LaravelCaptcha\Http\Controllers;

use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Request;

class CaptchaController extends Controller
{
    public function image(CaptchaChallenge $captcha)
    {
        return $captcha->response();
    }

    /**
     * Redirects the user to a default profile captcha.
     *
     * @return Response  A redirect
     */
    public function index()
    {
        $captcha = new CaptchaChallenge();

        if (Request::wantsJson()) {
            return $captcha->toJson();
        }

        return redirect(config('captcha.route') . '/' . $captcha->getHash() . '.jpg');
    }

    public function replace(CaptchaChallenge $captcha)
    {
        $captcha->replace();

        if (Request::wantsJson()) {
            return $captcha->toJson();
        }

        return redirect(config('captcha.route') . '/' . $captcha->getHash() . '.jpg');
        //return redirect(config('captcha.route') . '/' . $captcha->getProfile() . '/' . $captcha->getHash() . '.jpg');
    }
}
