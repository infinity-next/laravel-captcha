<?php

namespace InfinityNext\LaravelCaptcha\Events;

use InfinityNext\LaravelCaptcha\CaptchaAnswer;
use Illuminate\Queue\SerializesModels;

class CaptchaWasAnswered
{
    use SerializesModels;

    /**
     * The board the event is being fired on.
     *
     * @var \InfinityNext\LaravelCaptcha\CaptchaChallenge
     */
    public $answer;

    /**
     * Create a new event instance.
     */
    public function __construct(CaptchaAnswer $answer)
    {
        $this->answer = $answer;
    }
}
