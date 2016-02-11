Please study the other font folders in this directory to find out how to add more.

Do NOT use fonts that are not SIL OFL, Apache, WTFPL, PD, or similar license. Many of the handwriting fonts on dafont.com and other sites are so called "freeware", that is "free for personal use". 

I have heard of font authors launching frivolous lawsuits over breaking their licenses. 

Also, in my experience, PHP GD only likes TTF fonts. If you want to use PostScript, OTF or WOFF, use FontForge or similar to convert it to TTF.

Make sure you also add the font to src/config/captcha.php if you want to use it.

I generate it with the following script:

#!/bin/bash
> FONTLIST.txt;
PREPEND=vendor/infinity-next/laravel-captcha/fonts/;
printf "\t'fonts'\t=>\t[\n"
for f in */*.ttf; do
	printf "\t\t[\n\t\t\t'file' => '$PREPEND$f',\n\t\t\t'stroke' => 3,\n\t\t],\n" >> FONTLIST.txt; 
done
truncate --size=-2 FONTLIST.txt 

Then add FONTLIST.TXT to src/config/captcha.php in the right spot.
