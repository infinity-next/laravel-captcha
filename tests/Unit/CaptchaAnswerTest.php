<?php

namespace InfinityNext\LaravelCaptcha\Tests\Unit;

use InfinityNext\LaravelCaptcha\CaptchaAnswer;
use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use InfinityNext\LaravelCaptcha\Tests\TestCase;
use InvalidArgumentException;

class CaptchaAnswerTest extends TestCase
{
    public function testAnswer()
    {
        $challenge = new CaptchaChallenge;
        $hash = $challenge->getHash();
        $solution = $challenge->getSolution();

        $answer = new CaptchaAnswer($hash);
        $this->assertInstanceOf(CaptchaAnswer::class, $answer);
        $this->assertTrue($answer->answer($solution));
    }

    public function testAnswerWrong()
    {
        $challenge = new CaptchaChallenge;
        $hash = $challenge->getHash();
        $solution = $challenge->getSolution();

        $answer = new CaptchaAnswer($hash);
        $this->assertFalse($answer->answer(rand(0, 1000)));
    }

    public function testForget()
    {
        $challenge = new CaptchaChallenge;
        $hash = $challenge->getHash();

        $answer = new CaptchaAnswer($hash);
        $answer->forget();

        $this->expectException(InvalidArgumentException::class);
        $newAnswer = new CaptchaAnswer($hash);
    }
}
