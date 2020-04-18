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
            return $captcha->getHash();
        }

        return redirect(config('captcha.route') . '/' . $captcha->getHash() . '.webp');
    }

    public function replace(CaptchaChallenge $captcha)
    {
        $captcha->replace();

        if (Request::wantsJson()) {
            return $captcha->getHash();
        }

        return redirect(config('captcha.route') . '/' . $captcha->getHash() . '.webp');
        //return redirect(config('captcha.route') . '/' . $captcha->getProfile() . '/' . $captcha->getHash() . '.webp');
    }
}
