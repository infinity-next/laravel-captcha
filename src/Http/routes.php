<?php

use Illuminate\Support\Facades\Route;

Route::get('/replace', 'CaptchaController@replace')->name('captcha.replace');
Route::get('/replace.json', 'CaptchaController@replace')->name('captcha.api.replace');
Route::get('/{captcha}.jpg', 'CaptchaController@image')->name('captcha.image');
Route::get('.json', 'CaptchaController@index')->name('captcha.api');
Route::get('/', 'CaptchaController@index')->name('captcha');
