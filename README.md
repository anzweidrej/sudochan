# Sudochan

Sudochan is a free, lightweight, fast, highly configurable, and user-friendly imageboard software package. It is written in PHP and has few dependencies.

### Requirements
1. PHP >= 8.3
2. MySQL/MariaDB server
3. ext-mbstring 
4. ext-gd
5. ext-pdo

Sudochan does not include an Apache .htaccess file nor does it need one.

### Recommended
1. ImageMagick (command-line ImageMagick or GraphicsMagick preferred)
2. OpenSSL
3. APCu, XCache, Redis or Memcached

### Contributing
You can contribute to Sudochan by:
* Developing patches/improvements/translations and using GitHub to submit pull requests.
* Providing feedback and suggestions.
* Writing/editing documentation.

### Installation
1. Download and extract Sudochan to your web directory or get the latest development version with:

	git clone git://github.com/anzweidrej/sudochan.git

2. Change directory and install composer packages with:

        cd sudochan

        composer install

3. Create basic instance-config.php in /inc folder.
4. Navigate to /install.php in your web browser and follow the prompts.
5. Sudochan should now be installed. Log in to /mod.php with the default username and password combination: admin / password.
6. For running development environment, use following Docker command:

        docker compose up -d --build

NOTE: Please remember to change the administrator account password!

### Support
Sudochan is still beta software, and there are bound to be bugs. If you find a
bug, please report it.

If you need assistance with installing, configuring, or using Sudochan, you may
find support from a variety of sources:

* If you're unsure about how to enable or configure certain features, make sure you have read the comments in `inc/config.php`
* Create Github issue

### License
This work is dual-licensed under MIT AND LicenseRef-Tinyboard (or any later version).
You can choose between one of them if you use this work.

`SPDX-License-Identifier: MIT AND LicenseRef-Tinyboard`

Copyright (c) 2010-2014 Tinyboard Development Group (tinyboard.org)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

No portion of the Software shall be used to form a work licensed under any
version of the GNU General Public License, as published by the Free Software
Foundation.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

See [LICENSE](http://github.com/anzweidrej/sudochan/blob/master/LICENSE)
