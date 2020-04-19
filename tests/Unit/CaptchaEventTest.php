<?php

namespace InfinityNext\LaravelCaptcha\Tests\Unit;

use InfinityNext\LaravelCaptcha\CaptchaAnswer;
use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use InfinityNext\LaravelCaptcha\Events\CaptchaWasAnswered;
use InfinityNext\LaravelCaptcha\Events\CaptchaWasCreated;
use InfinityNext\LaravelCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

class CaptchaEventTest extends TestCase
{
    public function testCreatedEvent()
    {
        Event::fake();
        $challenge = new CaptchaChallenge;

        Event::assertDispatched(CaptchaWasCreated::class, function ($event) {
            return true;
        });
    }

    public function testAnsweredEvent()
    {
        Event::fake();
        $challenge = new CaptchaChallenge;
        $answer = new CaptchaAnswer($challenge->getHash(), $challenge->getSolution());

        Event::assertDispatched(CaptchaWasAnswered::class, function ($event) {
            return true;
        });
    }


    public function testNotAnsweredEvent()
    {
        Event::fake();
        $challenge = new CaptchaChallenge;
        $answer = new CaptchaAnswer($challenge->getHash(), rand(0, 1000000));

        Event::assertNotDispatched(CaptchaWasAnswered::class);
    }
}
