<?php

/**
 * Router para o servidor embutido do PHP (`php -S ... -t public router.php`) no
 * benchmark de performance. Deixa o servidor embutido servir /assets/ (o único
 * diretório de arquivos do public/ do Flarum) e roteia todo o resto para o front
 * controller public/index.php. A decisão olha só o prefixo da URL, sem montar
 * caminho de arquivo a partir dela. Sem isso o servidor
 * embutido devolve 404 em rotas SPA (/, /all, /u/admin), porque não tem
 * fallback para o index.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_contains($uri, '..') || str_contains($uri, "\0")) {
    http_response_code(400);
    return;
}

if (str_starts_with($uri, '/assets/')) {
    return false; // o servidor embutido serve o arquivo de public/assets, ou 404
}

// O index.php do Flarum faz `require '../site.php'` relativo ao diretório de
// trabalho. Num docroot real o cwd é public/; aqui o router roda a partir da
// raiz, então entramos em public/ antes de incluir o front controller — senão
// o require de site.php falha com 500.
chdir(__DIR__ . '/public');
require __DIR__ . '/public/index.php';
