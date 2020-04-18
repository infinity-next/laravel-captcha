<?php

return [

    /**
     * The table utilized by the Brennan Captch model and migration.
     *
     * @var string  table_name
     */
    'table'      => "captcha",

    /**
     * Route hooked by the captcha service.
     * If "captcha", image URL will be:
     * //base.url/captcha/profile/sha1.png
     *
     * This needs to be an actual path because we concatenate.
     *
     * @var string  /route/to/captcha/
     */
    'path'        => "captcha",

    /**
     * Passed directly into the 'middleware' parameter of the routing group used for
     * all API points.
     *
     * @var string|array  Middleware group name or array of middleware classes.
     */
    'middleware'  => "api",

    /**
     * Expiry time (in minutes) for a captcha.
     * It's imporatnt to have a short expiry time for your images.
     * If it's very long, it makes it easier for human captcha crackers to store answers.
     *
     * @var int  in minutes
     */
    'expires_in' => 5,

    /**
     * Font file locations.
     *
     * @var array  of file paths relative to application base
     */
    'fonts'      => [
        [
            'file' => 'cfonts/Deutsch.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/FFF_Tusj.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Kingthings_Calligraphica_2.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Kingthings_Calligraphica_Italic.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Kingthings_Calligraphica_Light.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Komika_Hand_Bold_Italic.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Komika_Hand_Bold.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Komika_Hand_Italic.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Komika_Hand.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Komika_Parch.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/Lilly__.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/SF_Cartoonist_Hand_Bold_Italic.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/SF_Cartoonist_Hand_Bold.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/SF_Cartoonist_Hand_Italic.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'cfonts/SF_Cartoonist_Hand.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'Gochi_Hand/GochiHand-Regular.ttf',
            'stroke' => 3,
        ],
        [
            'file' => 'Patrick_Hand/PatrickHand-Regular.ttf',
            'stroke' => 3,
        ]
    ],

    /**
     * Captcha image profiles.
     *
     * @var array  of arrays
     */
    'profiles'   => [

        /**
         * The default captcha profile.
         * All settings available to you are demonstrated here.
         *
         * @var array  of settings
         */
        'default' => [
            /**
             * Characters that can appear in the image.
             * Solutions are case-insensitive, but charsets are.
             * Lower-case F, G, Q and Z are omitted by default because of cursive lettering being hard to distinguish.
             * This should also support international or ASCII characters, if you're daring enough.
             *
             * @var string  of individual characters
             */
            'charset'    => 'AaBbCcDdEeFGHhIJKkLMmNnOoPpQRrSsTtUuVvWwXxYyZ',

            /**
             * Valid colors for the character sets.
             *
             * @var array  of R,G,B colors.
             */
            'colors'      => [
                [255, 0,   0  ],
                [0,   128, 0  ],
                [0,   0,   255],
            ],

            /**
             * Color of the backdrop.
             *
             * @var array  R,G,B color
             */
            'canvas'      => [255,255,255],

            /**
             * Minimum characters per captcha.
             *
             * @var int
             */
            'length_min'  => 6,

            /**
             * Maximum characters per captcha.
             *
             * @var int
             */
            'length_max'  => 8,

            /**
             * Applies a sine wave fx through the captcha.
             *
             * WARNING: A sine is very effective at busting bots,
             * but it is also costly to produce. Do not use with
             * a high resolution captcha image.
             *
             * @var boolean
             */
            'sine'        => true,

            /**
             * Whether the profile is for right-to-left script.
             *
             * @var boolean
             */
            'rtl'         => false,

            /**
             * Captcha image width.
             *
             * @var int
             */
            'width'       => 300,

            /**
             * Maximum image width.
             *
             * @var int
             */
            'height'      => 100,

            /**
             * Maximum font size in pixels.
             * Also affects the size of flourishes.
             *
             * @var int
             */
            'font_size'   => 48,

            /**
             * Minimum number of lines or circles to draw per ENTIRE CAPTCHA.
             *
             * @var int
             */
            'flourishes_min' => 1,

            /**
             * Maximum number of lines or circles to draw per letter blocking.
             *
             * @var int
             */
            'flourishes_max' => 2,
        ],

        /**
         * The dark captcha profile.
         *
         * @var array  of settings
         */
        'dark' => [
            'charset'    => 'AaBbCcDdEeFGHhIJKkLMmNnOoPpQRrSsTtUuVvWwXxYyZ',

            'colors'      => [
                [255, 0,   0  ],
                [0,   128, 0  ],
                [0,   0,   255],
            ],

            'canvas'      => [15,15,15],

            'length_min'  => 6,

            'length_max'  => 8,

            'sine'        => true,

            'rtl'         => false,

            'width'       => 560,

            'height'      => 160,

            'font_size'   => 96,

            'flourishes'  => 2,
        ],

        /**
         * A captcha utilizing the Japanese alphabet and common Kanji (Chinese) characters.
         *
         * @var array  of settings
         */
        'japanese' => [
            'fonts'      => [
                [
                    'file' => 'ipaexm/ipaexm.ttf',
                    'stroke' => 3,
                ],
            ],

            'charset'    => '日一大年中会人本月長国出上十生子分東三行同今高金時手見市力米自前円合立内二事社者地京間田体学下目五後' . // Common Kanji
                            'あいうえおきくけこぎぐげごしiすせそじずぜぞちiつuてとぢづでどにぬねのひふへほびぶべぼぴぷぺぽみむめもゆよりるれろをん', // Japanese

            'colors'      => [
                [255, 0,   0  ],
                [0,   128, 0  ],
                [0,   0,   255],
            ],

            'canvas'      => [255,255,255],

            'length_min'  => 3,

            'length_max'  => 4,

            'sine'        => true,

            'rtl'         => false,

            'width'       => 560,

            'height'      => 160,

            'font_size'   => 96,

            'flourishes'  => 0,
        ],

        /**
         * This character set does not utilize any Japanese characters. It instead uses additional Chinese characters.
         *
         * @var array  of settings
         */
        'chinese' => [
            'fonts'      => [
                [
                    'file' => 'ipaexm/ipaexm.ttf',
                    'stroke' => 3,
                ],
            ],

            'charset'    => '日一大年中会人本月長国出上十生子分東三行同今高金時手見市力米自前円合立内二事社者地京間田体学下目五後',

            'colors'      => [
                [255, 0,   0  ],
                [0,   128, 0  ],
                [0,   0,   255],
            ],

            'canvas'      => [255,255,255],

            'length_min'  => 4,

            'length_max'  => 5,

            'sine'        => true,

            'rtl'         => false,

            'width'       => 560,

            'height'      => 160,

            'font_size'   => 96,

            'flourishes'  => 0,
        ],

                /**
         * A captcha utilizing the Arabic alphabet.
         *
         * @var array  of settings
         */
        'arabic' => [
            'fonts'      => [
                [
                    'file' => 'SIL/Lateef/LateefRegOT.ttf',
                    'stroke' => 3,
                ],
            ],

            'charset'    => 'بتثجحخدذرزسشصضطظعغفقكلمني',

            'colors'      => [
                [255, 0,   0  ],
                [0,   128, 0  ],
                [0,   0,   255],
            ],

            'canvas'      => [255,255,255],

            'length_min'  => 4,

            'length_max'  => 5,

            'sine'        => true,

            'rtl'         => true,

            'width'       => 560,

            'height'      => 160,

            'font_size'   => 96,

            'flourishes'  => 0,
        ],
    ],
];
