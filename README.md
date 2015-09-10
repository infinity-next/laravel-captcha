Copyright 2015 Fredrick Brennan <admin@8chan.co>  
Integrated into Laravel by Joshua Moon <josh@jaw.sh>

Released under [AGPLv3](https://choosealicense.com/licenses/agpl-3.0/)

# Features
![](http://i.imgur.com/46FPTcQ.png)

## Customizable
Check out the well documented [config file](https://github.com/infinity-next/brennan-captcha/blob/master/src/config/captcha.php).

- Supports any open fonts with configurable font paths.
- Profiles for different fonts, text colors, and canvas colors.
  - Profile option for canvas color.
  - Profile option for canvas size.
  - Profile option for variable text length.
  - Profile option for text colors.
  - Profile option for sine wave.

## Databasing
Captchas are stored in a database table that will keep track of them between multiple pageviews.

Generated capatchas are stored in the database automatically.

Migration files for this are created dynamically. Table names can be adjusted by the config.

## Routing
Routes to the captcha are dynamic and can be adjusted in the config.

- Stores captcha codes in the database automatically.
- Generates a migration file for building the database table (config option for table name).
- Routing to generate this image (config option for actual route).
