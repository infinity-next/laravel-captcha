<?php

namespace InfinityNext\LaravelCaptcha\Tests\Feature;

use InfinityNext\LaravelCaptcha\CaptchaServiceProvider;
use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use InfinityNext\LaravelCaptcha\Tests\TestCase;
use Cache;
use InvalidArgumentException;
use Route;

class CaptchaRouteTest extends TestCase
{
    public function setup() : void
    {
        parent::setup();
    }

    public function testNamedRoute()
    {
        $this->assertEquals(
            url(config('captcha.path')),
            route('captcha')
        );
    }

    public function testCaptchaImageRoute()
    {
        $challenge = new CaptchaChallenge;

        $this->get(route('captcha.image', [ 'captcha' => $challenge->getHash() ]))
            ->assertOk();
    }

    public function testCaptchaIndexRoute()
    {
        $challenge = new CaptchaChallenge;

        $this->get(route('captcha'))
            ->assertRedirect();
    }


    public function testCaptchaReplaceRoute()
    {
        $challenge = new CaptchaChallenge;

        $this->get(route('captcha.replace'))
            ->assertRedirect();
    }
}
