# 1BRC: One Billion Row Challenge in PHP

PHP implementation of Gunnar's 1 billion row challenge:

- https://www.morling.dev/blog/one-billion-row-challenge
- https://github.com/gunnarmorling/1brc

I wrote a blog post about the story that led to this version: [Processing One Billion Rows in PHP!](https://dev.to/realflowcontrol/processing-one-billion-rows-in-php-3eg0)

# Usage

```sh
make average
# or
php createMeasurements.php 1000000000
php -d extension=parallel calculateAverage.php > average.txt
```

# Requirements

This solutions requires a ZTS build of PHP and
[`ext-parallel`](https://github.com/krakjoe/parallel) to be installed for that
PHP version.
