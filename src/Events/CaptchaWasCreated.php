<?php

namespace InfinityNext\LaravelCaptcha\Events;

use App\Events\Event;
use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use Illuminate\Queue\SerializesModels;

class CaptchaWasCreated extends Event
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
