<?php

// Front controller de production : le code vit dans le sous-dossier app/, protégé par son .htaccess.

use App\Kernel;

require_once __DIR__.'/app/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
