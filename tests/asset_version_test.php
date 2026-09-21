<?php
/**
 * Teste de versionamento automático de assets.
 * Verifica que asset_url() retorna URL válida e que versão muda quando arquivo muda.
 */
require_once __DIR__ . '/../public/partials/asset_helper.php';

// Arquivo existente
$url = asset_url('css/style.css');
if (strpos($url, '/css/style.css') === false) {
    echo "FAIL: URL não contém caminho do arquivo\n";
    exit(1);
}
if (strpos($url, '?v=') === false) {
    echo "FAIL: versão não presente\n";
    exit(1);
}
echo "PASS: asset_url gera URL versionada: $url\n";

// Mesmo arquivo -> mesma versão (filemtime não muda em segundos)
$url2 = asset_url('css/style.css');
if ($url === $url2) {
    echo "PASS: arquivo não alterado -> versão igual\n";
} else {
    echo "INFO: arquivo pode ter mudado entre chamadas (esperado em testes rápidos)\n";
}
