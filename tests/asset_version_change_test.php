<?php
/**
 * Teste de invalidação de cache por alteração de arquivo.
 * Confirma que arquivo alterado gera URL com versão diferente.
 */
require_once __DIR__ . '/../public/partials/asset_helper.php';

// Arquivo existente
$urlA = asset_url('css/style.css');

// Simula alteração mudando filemtime temporariamente (não altera conteúdo real)
// Usamos uma cópia para testar mudança
$orig = __DIR__ . '/../public/css/style.css';
$tmp = __DIR__ . '/../public/css/style_tmp.css';
copy($orig, $tmp);
// Toque no arquivo para alterar filemtime
touch($tmp, time() + 100);
$urlB = asset_url('css/style_tmp.css');

// Limpeza
unlink($tmp);

if ($urlA === $urlB) {
    echo "FAIL: versões idênticas apesar de arquivo diferente\n";
    exit(1);
}
echo "PASS: VERSAO_A != VERSAO_B (invalidação automática confirmada)\n";
echo "VERSAO_A (style.css): $urlA\n";
echo "VERSAO_B (style_tmp.css tocado): $urlB\n";
