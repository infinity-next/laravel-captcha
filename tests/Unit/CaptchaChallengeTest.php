<?php

namespace InfinityNext\LaravelCaptcha\Tests\Unit;

use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use InfinityNext\LaravelCaptcha\Tests\TestCase;
use Cache;
use InvalidArgumentException;

class CaptchaChallengeTest extends TestCase
{
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

    public function testHtml()
    {
        $challenge = new CaptchaChallenge;
        $html = $challenge->toHtml();

        $this->assertTrue(strpos($html, $challenge->getHash() . ".webp") !== false);
    }

    public function testReplace()
    {
        $challenge = new CaptchaChallenge;
        $hash1 = $challenge->getHash();
        $this->assertSame(strlen($hash1), 64);

        $challenge->replace();
        $hash2 = $challenge->getHash();
        $this->assertSame(strlen($hash2), 64);
        $this->assertNotSame($hash1, $hash2);
    }
}
