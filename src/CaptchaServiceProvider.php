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
		$router->pattern('alphanumeric', '[0-9a-z]{1,32}');
		
		/**
		 * Redirects the user to a default profile captcha.
		 *
		 * @return Response  A redirect
		 */
		$router->get(config('captcha.route'), function()
		{
			$captcha = Captcha::createCaptcha();
			return redirect(config('captcha.route') . '/' . $captcha->getHash() . '.png');
		});
		
		/**
		 * Redirects the user to a specific profile captcha.
		 *
		 * @param  string  $profile
		 * @return Response  A redirect
		 */
		$router->get(config('captcha.route'). '/{alphanumeric}', function($profile)
		{
			$captcha = Captcha::createCaptcha();
			return redirect(config('captcha.route') . '/' . $profile . '/' . $captcha->getHash() . '.png');
		});
		
		/**
		 * Returns a JSON response with a new captcha hash.
		 *
		 * @return \Intervention\Image\ImageManager
		 */
		$router->get(config('captcha.route') . '.json', function()
		{
			return Captcha::createCaptcha();
		});
		
		/**
		 * Returns a JSON response with a new captcha hash.
		 *
		 * @param  string  $profile
		 * @return response  Redirect
		 */
		$router->get(config('captcha.route') . '/{alphanumeric}.json', function($profile)
		{
			return Captcha::createCaptcha($profile);
		});
		
		/**
		 * Displays a default profile captcha.
		 *
		 * @param  string  $hash
		 * @return Response  Image with headers
		 */
		$router->get(config('captcha.route') . '/{sha1}.png', function($sha1)
		{
			$captcha = Captcha::findWithHex($sha1);
			
			return $captcha->getAsResponse();
		});
		
		/**
		 * Displays a specific profile captcha.
		 *
		 * @param  string  $profile
		 * @param  string  $config
		 * @return Response  Image with headers
		 */
		$router->get(config('captcha.route') . '/{alphanumeric}/{sha1}.png', function($profile, $sha1)
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
