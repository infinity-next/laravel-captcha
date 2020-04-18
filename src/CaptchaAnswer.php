<?php

namespace InfinityNext\LaravelCaptcha;

use InfinityNext\LaravelCaptcha\Captcha as CaptchaModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Cache;
use InvalidArgumentException;
use Session;

class CaptchaAnswer
{
    /**
     * Provided answer.
     *
     * @var string
     */
    protected $answer;

    /**
     * If this answer was successful.
     *
     * @var bool
     */
    protected $answered = false;

    /**
     * Hash of captcha we're answering.
     *
     * @var string
     */
    protected $hash;

    /**
     * Captcha we're answering.
     *
     * @var InfinityNext\LaravelCaptcha\Captcha
     */
    protected $captcha;

    /**
     * Checks a captcha code against the stored solution.
     * This will spend the token if it's available.
     *
     * @param  string  $hash
     * @param  string  $answer
     * @return bool|null  Boolean if token was spent, NULL if no captcha found.
     *
     * @throws Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function __construct($hash = null, $answer = null)
    {
        if (!is_null($hash)) {
            $this->setCaptcha($hash);

            if (!is_null($answer)) {
                $this->answer($answer);
            }
        }

        return $this;
    }

    /**
     * Attempt to answer the captcha.
     *
     * @param  string  $answer
     * @return bool
     *
     * @throws InvalidArgumentException
     */
    public function answer($answer)
    {
        $captcha = $this->captcha;

        // throw if we dont have a model yet
        if (is_null($captcha)) {
            throw new InvalidArgumentException("Attempting to answer a captcha that was not supplied.");
            return $this->answered = false;
        }

        // certain checks need to apply to model captchas.
        if ($captcha instanceof CaptchaModel) {
            // if this captcha was answered this request cycle return true
            if ($captcha->validated_this_request) {
                return $this->answered = true;
            }

            // if this captcha has already been answered return false
            if ($captcha->isCracked() || $captcha->isExpired()) {
                throw new InvalidArgumentException("Attempting to answer a captcha that was already answered.");
                return $this->answered = false;
            }

            $this->solution = $captcha->solution;
        }
        else {
            $this->solution = $captcha['solution'];
        }

        if (str_replace(" ", "", mb_strtolower($answer)) === mb_strtolower($this->solution)) {
            return $this->answered = true;
        }

        return $this->answered = false;
    }

    /**
     * Deletes captcha data from storage.
     *
     * @return void
     */
    public function forget()
    {
        Cache::forget("laravel-captcha.session." . Session::getId());
        Cache::forget("laravel-captcha.captcha.{$this->hash}");
        Cache::forget("laravel-captcha.captcha-image.{$this->hash}");
    }

    /**
     * @return Captcha
     */
    public function getCaptcha()
    {
        return $this->captcha;
    }

    /**
     * @return bool
     */
    public function isAnswered()
    {
        return $this->answered;
    }

    /**
     * @return Captcha
     */
    public function setCaptcha($hash)
    {
        if ($hash instanceof Captcha) {
            $this->hash = $hash;
            $this->captcha = $hash;
        }
        else {
            //$captcha = Captcha::findWithHex($hash);

            //if (!($captcha instanceof Captcha)) {
            //    throw new ModelNotFoundException;
            //}

            $captcha = Cache::get("laravel-captcha.captcha.{$hash}");

            if (is_null($captcha)) {
                throw new InvalidArgumentException;
            }

            $this->hash = $hash;
            $this->captcha = $captcha;
        }

        return $this->captcha;
    }
}
