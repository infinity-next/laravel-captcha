<?php

namespace InfinityNext\LaravelCaptcha\Tests\Unit;

use InfinityNext\LaravelCaptcha\CaptchaAnswer;
use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use InfinityNext\LaravelCaptcha\Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Cache;
use InvalidArgumentException;

class CaptchaChallengeTest extends TestCase
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

    public function testCreateHash()
    {
        $challenge = new CaptchaChallenge;

        $this->assertInstanceOf(CaptchaChallenge::class, $challenge);
        $this->assertSame(strlen($challenge->getHash()), 64);
    }

    public function testCreateGd()
    {
        $challenge = new CaptchaChallenge;

        $tmp = tmpfile();
        fwrite($tmp, $challenge->createGdCaptchaImage());

        $this->assertSame("image/webp", mime_content_type($tmp));

        unlink(stream_get_meta_data($tmp)['uri']);
    }

    public function testCreateImage()
    {
        $challenge = new CaptchaChallenge;
        $challenge->createCaptchaImage();

        $token = "laravel-captcha.captcha-image.{$challenge->getHash()}";
        $this->assertTrue(Cache::has($token));

        $tmp = tmpfile();
        fwrite($tmp, Cache::get($token));

        $this->assertSame("image/webp", mime_content_type($tmp));

        unlink(stream_get_meta_data($tmp)['uri']);
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
