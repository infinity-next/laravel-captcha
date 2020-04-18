<?php

namespace InfinityNext\LaravelCaptcha;

use InfinityNext\LaravelCaptcha\Captcha;
use InfinityNext\LaravelCaptcha\CaptchaTableCommand;
use InfinityNext\LaravelCaptcha\CaptchaValidator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory;
use Illuminate\Routing\Router;
use Request;

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
            return new Captcha;
        });

        include(realpath(dirname(__FILE__)) . "/../helpers/captcha.php");

        $router->pattern('sha1', '[0-9a-f]{5,40}');
        $router->pattern('alphanumeric', '[0-9a-z]{1,32}');

        $router->group(['middleware' => config('captcha.middleware', 'api')], function() use ($router)
        {
            /**
             * Destroys an existing captcha and returns a new one.
             *
             * @return Response  A redirect
             */
            $router->get(config('captcha.route'). '/replace', function()
            {
                $captcha = Captcha::replace(Request::get('hash', null));

                return redirect(config('captcha.route') . '/' . $captcha->profile . '/' . $captcha->getHash() . '.png');
            });

            /**
             * Destroys an existing captcha and returns a new one using JSON.
             *
             * @return \Intervention\Image\ImageManager
             */
            $router->get(config('captcha.route') . '/replace.json', function()
            {
                return Captcha::replace(Request::get('hash', null));
            });

            /**
             * Redirects the user to a default profile captcha.
             *
             * @return Response  A redirect
             */
            $router->get(config('captcha.route'), function()
            {
                $captcha = Captcha::findOrCreateCaptcha();

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
                $captcha = Captcha::findOrCreateCaptcha($profile);

                return redirect(config('captcha.route') . '/' . $profile . '/' . $captcha->getHash() . '.png');
            });

            /**
             * Returns a JSON response with a new captcha hash.
             *
             * @return \Intervention\Image\ImageManager
             */
            $router->get(config('captcha.route') . '.json', function()
            {
                return Captcha::findOrCreateCaptcha();
            });

            /**
             * Returns a JSON response with a new captcha hash.
             *
             * @param  string  $profile
             * @return response  Redirect
             */
            $router->get(config('captcha.route') . '/{alphanumeric}.json', function($profile)
            {
                return Captcha::findOrCreateCaptcha($profile);
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

                if ($captcha instanceof Captcha) {
                    return $captcha->getAsResponse();
                }

                return abort(404);
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

                if ($captcha instanceof Captcha) {
                    return $captcha->getAsResponse();
                }

                return abort(404);
            });
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
     * Register custom validation rules.
     *
     * @return void
     */
    protected function registerValidationRules($validator)
    {
        $validator->extend('captcha', 'InfinityNext\LaravelCaptcha\CaptchaValidator@validateCaptcha', 'validation.captcha');
    }
}
