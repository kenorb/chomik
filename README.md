ChomikDownloader
================

Scripts for downloading files from chomikuj.pl

Usage
=====

    php chomik.php -u USER -p PASSWORD --url="http://chomikuj.pl/PATH" [optional options] destination

    Required Options:
      --user=USER          Uses specified user name for authentication.
      --password=PASSWORD  Uses specified user password for authentication.
      --hash=HASH          Uses specified user password hash for authentication.
      --url=URL            Downloads files from the specified URL.

    Optional Options:
      --ext=EXTENSIONS     Downloads only files of the specified extensions, separated by comma.
      -r, --recursive      Downloads also all subdirectories.
      -s, --structure      Creates full folder structure.
      -o, --overwrite      Overwrites existing files.
      -h, --help, /?       Shows this help.

    NOTE:
      - To log in you may use password OR hash.
      - URLs must start with "http://chomikuj.pl/" and must NOT end with a slash.
      - By default the script downloads files into the folder from where it was run.

    Examples:
      php chomik.php --user=chomikoryba --password=ryba123 --url="http://chomikuj.pl/chomikoryba" -r -s downloads
      php chomik.php --user=chomikoryba --hash=233b5774ada09b36458f69a04c9718e9 --url="http://chomikuj.pl/chomikoryba/Myriad+Pro+%28CE%29-TTF" -r --ext="ttf,otf" fonts
