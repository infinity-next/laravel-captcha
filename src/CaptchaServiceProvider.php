<?php

namespace InfinityNext\LaravelCaptcha;

use InfinityNext\LaravelCaptcha\Captcha;
use InfinityNext\LaravelCaptcha\CaptchaTableCommand;
use InfinityNext\LaravelCaptcha\CaptchaChallenge;
use InfinityNext\LaravelCaptcha\CaptchaValidator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory;
use Illuminate\Routing\Router;
use Request;
use Route;
use InvalidArgumentException;

class CaptchaServiceProvider extends ServiceProvider
{
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
            __DIR__ . '/../config/captcha.php' => config_path('captcha.php'),
        ]);

        $this->mergeConfigFrom(
            __DIR__ . '/../config/captcha.php', 'captcha'
        );

        $this->registerValidationRules($this->app['validator']);

        $this->app->singleton('captcha', function ($app) {
            return new CaptchaChallenge;
        });

        $router->bind('captcha', function ($value) {
            try {
                $captcha = new CaptchaChallenge($value);
            }
            catch (InvalidArgumentException $e) {
                return abort(404);
            }
            catch (\Exception $e) {
                dd($e);
            }

            return $captcha;
        });

        $router->pattern('captcha', '[A-Fa-f0-9]{64}');
        $router->pattern('captchaProfile', '[0-9a-z]{1,32}');

        Route::group($this->routeConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('command.captcha.table', function ($app) {
            return new CaptchaTableCommand;
        });

        $this->commands('command.captcha.table');
    }

    /**
     * Get the Telescope route group configuration array.
     *
     * @return array
     */
    private function routeConfiguration()
    {
        return [
            'namespace' => 'InfinityNext\LaravelCaptcha\Http\Controllers',
            'prefix' => config('captcha.path', '/captcha'),
            'middleware' => config('captcha.middleware', 'api'),
        ];
    }

    /**
     * Register custom validation rules.
     *
     * @return void
     */
    protected function registerValidationRules($validator)
    {
        $validator->extend('captcha', 'InfinityNext\LaravelCaptcha\CaptchaValidator@validateCaptcha', 'validation.captcha');
    }
}
