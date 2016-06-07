<?php

if (!function_exists('captcha'))
{
	function captcha($profile = null)
	{
		if (is_null($profile))
		{
			return app('captcha');
		}

		return app('captcha')->getAsHtml($profile);
	}
}
