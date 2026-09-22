# Publish to Packagist

The Composer package name is `waix/waix-php`; `waix-php` alone is not a valid vendor/package name. Sign in to Packagist and submit this public GitHub repository. Packagist reads composer.json and Git tags such as v0.1.0; do not add a hardcoded version field. Connect the repository webhook using the supported Packagist/GitHub integration so future tags refresh automatically. Confirm the package ownership and maintainer account before submitting.

The GitHub VCS installation command in README already works without Packagist. After submission, users can install with `composer require waix/waix-php:^0.1` without a custom repository entry.

Documentation: https://packagist.org/about.
