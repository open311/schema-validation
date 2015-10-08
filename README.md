Utilities to validate data and APIs using JSON Schema and Swagger

Most of this code is drawn from https://github.com/GSA/project-open-data-dashboard/ 

It uses the [SwaggerAssertions](https://github.com/Maks3w/SwaggerAssertions) and [json-schema](https://github.com/justinrainbow/json-schema) libraries


Installation
------------

Clone into web server directory

`git clone https://github.com/open311/schema-validation.git .`

Copy sample .htaccess and index.php files

`cp sample.htaccess .htaccess`

`cp index.php.sample index.php`

Copy sample config files:

`cd application/config`

`cp config.php.sample config.php`

Edit the path of the downloads directory in the config file

### Get Dependencies with Composer

Install composer if you haven't already

`curl -sS https://getcomposer.org/installer | php`

Install the defined dependencies

`php composer.phar install`

### Create the downloads directory

`mkdir downloads`
