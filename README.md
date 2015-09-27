# Core Features

![Example Captcha](https://cloud.githubusercontent.com/assets/9262991/10121734/304a129a-652d-11e5-8405-8702ba0dc924.png)

The core features are:

- Stores captcha codes in the database automatically.
- Generates a migration file for building the database table (config option for table name).
- Built in `capatcha` option for the Laravel validators.
- `captcha()` helper for quickly popping in a new captcha.
- Routing
  - Includes configurable base route (`captcha` can instead be `assets/security-image`, for instance)
  - Simple routes for generating a brand new captcha (`captcha` will 302 to a new image)
  - Accepts routing for profiles (`captcha/default` 302s to a new image using the `default` profile)
- JSON API
  - Accessing any route with the `.json` suffix will return identifying information about your captcha. Helps with click-to-reload features.
- Configurable global settings.
  - Fonts and their outline stroke width.
  - Captcha expiry time.
- Profiles for different fonts, text colors, and canvas colors.
  - Profile option for characters accepted (letters and numbers, customizing the alphabet used).
  - Profile option for canvas color.
  - Profile option for canvas size.
  - Profile option for character count.
  - Profile option for width and height.
  - Profile option for text colors.
  - Profile option for sine wave.
  - Profile option for maximum number of "flourishes", or arcs and lines to draw.
  - Set fonts unique to each profile.

# Copyright
Copyright 2015 Fredrick Brennan <admin@8chan.co>

Released under AGPLv3
