<?php namespace InfinityNext\LaravelCaptcha\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Provides a Facade for the Brennan Captcha.
 *
 * @author Jaw-sh
 * @see InfinityNext\LaravelCaptcha\Captcha
 */
class Captcha extends Facade {
	
	protected static function getFacadeAccessor()
	{
		return "Captcha";
	}
	
}
