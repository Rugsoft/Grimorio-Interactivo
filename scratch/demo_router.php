<?php

/**
 * demo_router.php — Router del servidor de verificación visual (solo local).
 *
 * Sirve la página de demostración del Tomo Arcano desde scratch/ y enruta
 * `/api/*` al front controller real (public/index.php), de forma que la demo
 * consume la API del santuario EN EL MISMO ORIGEN (cookies y JSON reales).
 *
 * Uso: php -S 127.0.0.1:8092 scratch/demo_router.php
 * (el docroot por defecto pasa a ser el directorio de trabajo: la raíz del
 * proyecto, de donde se sirven public/assets/** y scratch/**).
 *
 * NO es un artefacto de producción: jamás se despliega con el santuario.
 */

declare(strict_types=1);

$requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// 1. La API del santuario: se cede al front controller real.
if (str_starts_with($requestPath, '/api/')) {
    require __DIR__ . '/../public/index.php';
    return true;
}

// 2. Recursos estáticos de la raíz del proyecto (public/**, scratch/**).
$staticFile = dirname(__DIR__) . $requestPath;
if ($requestPath !== '/' && is_file($staticFile)) {
    return false; // el servidor nativo lo sirve con su Content-Type
}

// 3. La raíz y cualquier otro sendero entregan la demo del Tomo.
readfile(__DIR__ . '/demo_grimoire_tome.html');
return true;
