<?php namespace InfinityNext\BrennanCaptcha;

use InfinityNext\BrennanCaptcha\Captcha;
use InfinityNext\BrennanCaptcha\CaptchaTableCommand;
use InfinityNext\BrennanCaptcha\CaptchaValidator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory;

use Illuminate\Routing\Router;

class CaptchaServiceProvider extends ServiceProvider {
	
	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;
	
	/**
	 * Boot the service provider.
	 *
	 * @return null
	 */
	public function boot(Router $router)
	{
		$this->publishes([
			__DIR__ . '/config/captcha.php' => config_path('captcha.php'),
		]);
		
		$this->mergeConfigFrom(
			__DIR__ . '/config/captcha.php', 'captcha'
		);
		
		$this->registerValidationRules($this->app['validator']);
		
		$this->app->singleton('captcha', function ($app) {
			return new Captcha();
		});
		
		$router->pattern('sha1', '[0-9a-f]{5,40}');
		
		/**
		 * @param  string  $hash
		 * @param  Captcha  $captcha
		 * @return \Intervention\Image\ImageManager
		 */
		$router->get(config('captcha.route'), function()
		{
			$captcha = Captcha::createCaptcha();
			return redirect(config('captcha.route') . '/' . $captcha->getHash() . '.png');
		});
		
		/**
		 * @param  string  $hash
		 * @param  Captcha  $captcha
		 * @return \Intervention\Image\ImageManager
		 */
		$router->get(config('captcha.route') . '/{sha1}.png', function($sha1)
		{
			$captcha = Captcha::findWithHex($sha1);
			
			return $captcha->getAsResponse();
		});
		
		/**
		 * @param  Captcha  $captcha
		 * @param  string  $config
		 * @return \Intervention\Image\ImageManager
		 */
		$router->get(config('captcha.route') . '/{config}/{sha1}.png', function($profile, $sha1)
		{
			$captcha = Captcha::findWithHex($sha1);
			
			return $captcha->getAsResponse($profile);
		});
	}
	
	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->app->bindShared('command.captcha.table', function ($app) {
			return new CaptchaTableCommand;
		});
		
		$this->commands('command.captcha.table');
	}
	
	/**
	 * Register custom validation rules.
	 *
	 * @return void
	 */
	protected function registerValidationRules($validator)
	{
		$validator->extend('captcha', 'InfinityNext\BrennanCaptcha\CaptchaValidator@validateCaptcha', 'validation.captcha');
	}
	
}
