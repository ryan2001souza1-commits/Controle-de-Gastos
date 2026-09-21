<?php
/**
 * Helper de versionamento automático de assets locais.
 * Usa filemtime() para gerar um hash/versionável na URL.
 * Quando o arquivo mudar, a URL muda, invalidando o cache immutable.
 */
function asset_url(string $localPath): string
{
    $abs = dirname(__DIR__, 1) . '/' . ltrim(str_replace('..', '', $localPath), '/');
    if (!is_file($abs)) {
        return '/' . ltrim($localPath, '/');
    }
    $ver = dechex(filemtime($abs));
    $clean = '/' . ltrim(str_replace('..', '', $localPath), '/');
    return $clean . '?v=' . $ver;
}
