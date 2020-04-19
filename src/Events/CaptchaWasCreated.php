<?php

namespace InfinityNext\LaravelCaptcha\Events;

use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use Illuminate\Queue\SerializesModels;

class CaptchaWasCreated
{
    use SerializesModels;

    /**
     * The board the event is being fired on.
     *
     * @var \InfinityNext\LaravelCaptcha\CaptchaChallenge
     */
    public $captcha;

    /**
     * Create a new event instance.
     */
    public function __construct(CaptchaChallenge $captcha)
    {
        $this->captcha = $captcha;
    }
}
