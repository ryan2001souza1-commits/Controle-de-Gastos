<?php
class AiService
{
    private PDO $db;

    // Limites centralizados
    private const LIMITS = [
        'gratuito' => 5,
        'gratis'   => 5,
        'free'     => 5,
        'pro'      => 20,
        'premium'  => 50,
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private static function env(string $key): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return '';
    }

    private static function envClean(string $v): string
    {
        $v = trim($v);
        // remove aspas envolventes se existirem (ex: "sk-..." ou 'sk-...')
        if (strlen($v) >= 2 && (($v[0]==='"' && substr($v,-1)==='"') || ($v[0]==="'" && substr($v,-1)==="'"))) {
            $v = substr($v, 1, -1);
        }
        return trim($v);
    }

    public static function getConfig(): array
    {
        $key = self::env('AI_API_KEY') ?: self::env('OPENROUTER_API_KEY') ?: self::env('OPENAI_API_KEY');
        $url = self::env('AI_API_URL') ?: self::env('OPENROUTER_API_URL') ?: 'https://openrouter.ai/api/v1/chat/completions';
        $model = self::env('AI_MODEL') ?: self::env('OPENROUTER_MODEL') ?: 'openrouter/free';
        $maxTokens = (int)(self::env('AI_MAX_TOKENS') ?: 700);
        $temperature = (float)(self::env('AI_TEMPERATURE') ?: 0.35);
        return [
            'key' => self::envClean($key),
            'url' => self::envClean($url),
            'model' => self::envClean($model),
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];
    }

    public static function isConfigured(): bool
    {
        $c = self::getConfig();
        return $c['key'] !== '';
    }

    public static function isFreeModel(string $model): bool
    {
        $m = strtolower(trim($model));
        if ($m === 'openrouter/free') return true;
        // qualquer modelo com sufixo :free é considerado gratuito (ex: qwen/...:free)
        if (str_ends_with($m, ':free')) return true;
        return false;
    }

    public function getUserLimit(string $plano): int
    {
        $k = strtolower(trim($plano ?: 'gratuito'));
        return self::LIMITS[$k] ?? self::LIMITS['gratuito'];
    }

    public function checkRateLimit(int $userId, string $plano): array
    {
        $limit = $this->getUserLimit($plano);
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("SELECT requests FROM ai_usage WHERE user_id=? AND date=?");
        $stmt->execute([$userId, $today]);
        $used = (int)($stmt->fetchColumn() ?: 0);
        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'canProceed' => $used < $limit,
        ];
    }

    public function incrementUsage(int $userId, int $tokensApprox = 0): void
    {
        $today = date('Y-m-d');
        $this->db->prepare("
            INSERT INTO ai_usage (user_id, date, requests, tokens_used)
            VALUES (?, ?, 1, ?)
            ON CONFLICT (user_id, date) DO UPDATE SET requests = ai_usage.requests + 1, tokens_used = ai_usage.tokens_used + EXCLUDED.tokens_used, updated_at = NOW()
        ")->execute([$userId, $today, $tokensApprox]);
    }

    public function getSystemPrompt(): string
    {
        return <<<PROMPT
Você é o Assistente Financeiro pessoal do sistema "Controle de Gastos" — útil, objetivo e acolhedor. Responda sempre em português do Brasil.

ANTES DE RESPONDER, analise os dados financeiros do contexto JSON. Nunca invente valores, categorias, lançamentos, metas ou orçamentos. Se não houver dados suficientes para a pergunta, diga claramente "não há dados suficientes para esta análise" e sugira registrar lançamentos/categorias/metas.

REGRAS DE DADOS:
- Para perguntas sobre "este mês"/mês atual, use receitas/despesas/saldo/categorias/orçamento do período atual do contexto.
- Quando fizer sentido (comparação, evolução), use comparativo_anterior, historico_mensal e categorias_anterior para comparar com meses anteriores (ex: variação %).
- Faça cálculos simples quando útil (percentuais, saldo, % do orçamento, % das receitas gastas).
- Para análise de CATEGORIAS: identifique categoria com maior gasto e menor gasto, calcule percentual de cada categoria sobre o total de despesas, destaque categorias com >=30% (merecem atenção) usando resumo_categorias.destaques, e aponte mudanças relevantes (>=15% vs mês anterior) usando resumo_categorias.variacoes. Use categorias e resumo_categorias do contexto.
- Para análise de ORÇAMENTO: use orcamento.limite, orcamento.utilizado, orcamento.disponivel, orcamento.percentual_usado e orcamento.status. Diferencie valor utilizado (quanto já gastou) de disponível (limite - utilizado). Nunca use saldo bancário como orçamento. Para detalhes por categoria use orcamento.categorias[], orcamento.ultrapassaram[] e orcamento.em_risco[]. Calcule projeção se perguntarem "vou conseguir terminar o mês?": gasto médio diário = utilizado / dias_passados; projeção = média * dias_no_mês; compare com limite.
- Para análise de METAS: use metas[].nome, metas[].alvo, metas[].atual, metas[].restante, metas[].percentual, metas[].status, metas[].meses_restantes e metas[].sugestao_mensal. Calcule quanto falta: restante = alvo - atual. Para "estou no caminho certo?", avalie status (concluida/boa_progresso/iniciante/sem_prazo) e compare % com o tempo decorrido do prazo. Para "quanto preciso guardar por mês?", use metas[].sugestao_mensal quando disponível (restante / meses_restantes). NUNCA invente prazos — se metas[].prazo for null, não mencione meses nem faça sugestão mensal, apenas calcule quanto falta no total. Use status e percentual para advice concreto.
- Para análise de COMPARAÇÃO HISTÓRICA: use comparativo.receitas (anterior/atual/delta/percentual/sinal), comparativo.despesas, comparativo.saldo, comparativo.categorias[], comparativo.maiores_aumentos[], comparativo.maiores_reducoes[] e historico_mensal[]. Para variação %, use: (atual - anterior) / anterior * 100. Mostre sinal +/−. Para "gastei mais este mês?", compare despesa atual vs anterior usando comparativo.despesas. Para "como estou vs mês passado", mostre receitas/despesas/saldo com variação %. Para "como estão meus últimos meses", use historico_mensal e resuma tendência. Destaque mudanças relevantes: categorias com variação >=15% ou que apareceram/desapareceram. NUNCA invente dados — se historico_mensal estiver vazio ou só tiver 1 mês, diga que não há histórico suficiente.
- Identifique padrões: maior categoria, aumento vs mês anterior, orçamento >80-100%, metas com baixo %.

ESTILO:
- Seja claro, prático e direto. Evite respostas excessivamente longas. Priorize informação que o usuário pode agir hoje.
- Use R$ com separador brasileiro (ex: R$ 1.250,00).
- Não forneça aconselhamento financeiro profissional certificado. Use "uma sugestão é..." e deixe claro que é educação financeira geral.
- Nunca peça senha, token ou dados sensíveis. Nunca diga que alterou o banco.

FORMATO PREFERENCIAL (use apenas as seções necessárias):
📊 Resumo
Breve resposta direta à pergunta com números principais.

💡 Análise
Explique o que os dados mostram (ex: despesas representam X% das receitas, categoria dominante, comparação com mês anterior).

⚠️ Atenção
Mostre APENAS se houver ponto que realmente merece atenção (ex: categoria estourada, saldo negativo, orçamento >85%). Se não houver, omita esta seção.

✅ Recomendações
Dê 2 a 4 sugestões práticas e personalizadas com base nos dados (ex: defina limite para Alimentação, acompanhe semanalmente, reserve parte do saldo para meta X). Se não houver base para recomendar, omita.

Exemplo para "Como estão minhas finanças?":
📊 Resumo
Suas finanças estão equilibradas neste mês. Você recebeu R$ 3.000,00 e gastou R$ 1.800,00, ficando com saldo de R$ 1.200,00.

💡 Análise
Suas despesas representam 60% das receitas. A maior categoria foi Alimentação.

⚠️ Atenção
Sua maior categoria de gastos foi Alimentação (40% do total).

✅ Recomendações
• Defina um limite para Alimentação.
• Acompanhe seus gastos ao longo do mês.
• Reserve parte do saldo para suas metas.

Se a pergunta for fora de finanças pessoais, responda educadamente que seu foco é finanças.
PROMPT;
    }

    /**
     * Tenta responder deterministicamente sem chamar IA para economizar tokens.
     * Retorna string|null. Se null, deve chamar IA.
     */
    private function strLower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); }
    private function strLen(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }
    private function subStr(string $s,int $a,?int $b=null): string { if (function_exists('mb_substr')) return $b===null? mb_substr($s,$a): mb_substr($s,$a,$b); return $b===null? substr($s,$a): substr($s,$a,$b); }

    public function tryDeterministicAnswer(string $question, array $ctx): ?string
    {
        $q = $this->strLower(trim($question));
        // normaliza acentos básico
        $norm = function_exists('iconv') ? (iconv('UTF-8','ASCII//TRANSLIT',$q) ?: $q) : $q;

        $fmt = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');

        // Resumo geral finanças (economia de tokens)
        if (preg_match('/como est.*financ|resumo.*financ|situac.*financeira/', $norm)) {
            $catLine = '';
            if (!empty($ctx['categorias'])) {
                $top = $ctx['categorias'][0];
                $catLine = " Maior gasto: **{$top['nome']}** com {$fmt($top['valor'])} ({$top['percentual']}%).";
            }
            $orc = $ctx['orcamento'];
            $orcLine = ($orc['limite']>0 ? " Orçamento: {$orc['percentual_usado']}% usado, {$fmt($orc['disponivel'])} livres." : " Sem orçamento definido.");
            return "Em {$ctx['periodo']} suas finanças estão assim: receitas **{$fmt($ctx['receitas'])}**, despesas **{$fmt($ctx['despesas'])}**, saldo **{$fmt($ctx['saldo'])}**.{$catLine}{$orcLine} Quer uma análise mais detalhada de alguma parte?";
        }
        // === ANÁLISE INTELIGENTE DE COMPARAÇÃO HISTÓRICA (deve vir antes de orcamento/categoria para pegar "comparando com o mês passado" etc.)
        $isComparacao = preg_match('/\b(comparar|comparando|comparacao|comparação|em relacao|em relação|ultimos meses|últimos meses|meses anteriores|ultimo mes|último mes|ultimo mês|ultimo mes|mes passado|mês passado|ultimos 3|ultimos 6|evolucao|evolução|tendencia|tendência|ano a ano|aumento.*despesas|despesas.*aumentar|despesas.*aumentaram|gastei mais|receitas.*diminuir|receitas.*cair)\b/', $norm);
        if ($isComparacao) {
            $comp = $ctx['comparativo'] ?? null;
            $hist = $ctx['historico_mensal'] ?? [];
            // Verifica se há dados históricos
            $temAnterior = $comp && ($comp['receitas']['anterior'] > 0 || $comp['despesas']['anterior'] > 0);
            $temHistorico = count($hist) >= 2;
            if (!$temAnterior && !$temHistorico) {
                return "Não há dados suficientes para comparar com meses anteriores. Registre mais lançamentos para eu gerar comparações.";
            }
            // Helper para linha de variação
            $linhaVar = function(array $v, string $nome) use ($fmt) {
                $sinal = $v['sinal'] === 'aumento' ? '↗️ aumento' : ($v['sinal'] === 'reducao' ? '↘️ redução' : '➖ estável');
                $sinalDelta = $v['delta'] > 0 ? '+' : '';
                return "**$nome**\nMês anterior: {$fmt($v['anterior'])}\nMês atual: {$fmt($v['atual'])}\nVariação: {$sinalDelta}{$v['percentual']}% ({$sinal})";
            };

            // "Como estão minhas finanças nos últimos meses?" — usa histórico
            if (preg_match('/ultimos meses|últimos meses|ultimos 3|ultimos 6|historico|histórico|evolucao|evolução|tendencia|tendência/', $norm)) {
                if (empty($hist)) return "Ainda não há histórico suficiente para mostrar evolução dos últimos meses. Continue registrando seus lançamentos.";
                $linhas = [];
                foreach ($hist as $h) {
                    $linhas[] = "- {$h['mes']}: receitas {$fmt($h['receitas'])}, despesas {$fmt($h['despesas'])}, saldo {$fmt($h['saldo'])}";
                }
                $txt = "Evolução dos últimos " . count($hist) . " meses:\n" . implode("\n", $linhas);
                // tendência simples: compara médias
                $n = count($hist);
                if ($n >= 2) {
                    $metade = (int)floor($n/2);
                    $recRec = array_sum(array_column(array_slice($hist, -$metade), 'receitas')) / max(1, $metade);
                    $recAnt = array_sum(array_column(array_slice($hist, 0, $n-$metade), 'receitas')) / max(1, $n-$metade);
                    $despRec = array_sum(array_column(array_slice($hist, -$metade), 'despesas')) / max(1, $metade);
                    $despAnt = array_sum(array_column(array_slice($hist, 0, $n-$metade), 'despesas')) / max(1, $n-$metade);
                    $txt .= "\n\nTendência (comparando metade mais recente vs metade mais antiga):";
                    $txt .= "\n• Receitas: ".($recRec > $recAnt ? 'tendência de alta' : ($recRec < $recAnt ? 'tendência de queda' : 'estável'))." (média ".($n-$metade>0?$fmt($recAnt).' → ':'').$fmt($recRec).").";
                    $txt .= "\n• Despesas: ".($despRec > $despAnt ? 'tendência de alta' : ($despRec < $despAnt ? 'tendência de queda' : 'estável'))." (média ".($n-$metade>0?$fmt($despAnt).' → ':'').$fmt($despRec).").";
                }
                return $txt;
            }
            // "Gastei mais este mês?" / "Minhas despesas aumentaram?" — variação de despesa
            if (preg_match('/gastei mais|despesas.*aument|despesa.*aumento|minhas despesas|subiu.*despesa|aumentou.*despesa/', $norm)) {
                $d = $comp['despesas'];
                $linha = $linhaVar($d, 'Despesas');
                if ($d['sinal'] === 'aumento' && $d['percentual'] >= 5) {
                    $resp = $linha."\n\n⚠️ Sim, as despesas **aumentaram ".abs($d['percentual'])."%** (R$ ".number_format(abs($d['delta']), 2, ',', '.')." a mais).";
                    if (!empty($comp['maiores_aumentos'])) {
                        $resp .= " Principais responsáveis: ".implode(', ', array_map(fn($c)=>"{$c['nome']} (+{$c['percentual']}%, {$fmt($c['delta'])})", array_slice($comp['maiores_aumentos'],0,2))).".";
                    }
                } elseif ($d['sinal'] === 'reducao' && abs($d['percentual']) >= 5) {
                    $resp = $linha."\n\n✅ Não — as despesas **diminuíram ".abs($d['percentual'])."%** (R$ ".number_format(abs($d['delta']), 2, ',', '.')." a menos).";
                } else {
                    $resp = $linha."\n\nAs despesas estão estáveis (variação de {$d['percentual']}%) em relação ao mês anterior.";
                }
                return $resp;
            }
            // "Como estou em relação ao mês passado?" — geral com receita/despesa/saldo
            if (preg_match('/em relacao|em relação|comparar|comparando|comparacao|comparação|mes passado|mês passado|ultimo mes|último mes|ultimo mês/', $norm) || $isComparacao) {
                $r = $comp['receitas'];
                $d = $comp['despesas'];
                $s = $comp['saldo'];
                $linhas = [];
                $linhas[] = $linhaVar($r, 'Receitas');
                $linhas[] = $linhaVar($d, 'Despesas');
                $linhas[] = $linhaVar($s, 'Saldo');
                $temPrev = ($ctx['comparativo_anterior']['receitas'] > 0 || $ctx['comparativo_anterior']['despesas'] > 0);
                $txt = "Comparação com o mês anterior (" . ($temPrev ? 'com dados' : 'sem dados completos') . "):\n\n" . implode("\n\n", $linhas);
                // Destacar mudanças relevantes
                $dest = [];
                if (abs($d['percentual']) >= 15) $dest[] = "Despesas " . ($d['sinal']==='aumento'?'subiram':'caíram') . " ".abs($d['percentual'])."%";
                if (abs($r['percentual']) >= 15) $dest[] = "Receitas " . ($r['sinal']==='aumento'?'subiram':'caíram') . " ".abs($r['percentual'])."%";
                if (!empty($comp['maiores_aumentos'])) {
                    $top = $comp['maiores_aumentos'][0];
                    if (abs($top['percentual']) >= 15) $dest[] = "{$top['nome']} {$top['sinal']} ".abs($top['percentual'])."% ({$fmt($top['delta'])})";
                }
                if (!empty($comp['maiores_reducoes'])) {
                    $top = $comp['maiores_reducoes'][0];
                    if (abs($top['percentual']) >= 15) $dest[] = "{$top['nome']} {$top['sinal']} ".abs($top['percentual'])."% ({$fmt($top['delta'])})";
                }
                if (!empty($dest)) $txt .= "\n\n📌 Destaques: " . implode('; ', $dest) . ".";
                return $txt;
            }
        }
        // === ANÁLISE INTELIGENTE DE ORÇAMENTO (deve vir antes de categoria/despesas para pegar "gastando demais", etc.)
        if (preg_match('/\b(orcamento|orçamento|posso gastar|ainda posso|disponivel|disponível|gastando demais|dentro do orcamento|terminar o mes)\b/', $norm)) {
            $o = $ctx['orcamento'] ?? [];
            if (($o['limite'] ?? 0) <= 0) return "Você ainda não definiu um orçamento para {$ctx['periodo']}. Defina um limite mensal em **Orçamentos** para eu acompanhar quanto ainda pode gastar.";
            $base = "Orçamento: **{$fmt($o['limite'])}**\nUtilizado: **{$fmt($o['utilizado'])}**\nDisponível: **{$fmt($o['disponivel'])}**\nUtilização: **{$o['percentual_usado']}%**";
            // "Quanto ainda posso gastar?" — foco no disponível
            if (preg_match('/quanto.*posso gastar|ainda posso gastar|disponivel/', $norm)) {
                $txt = $base;
                if (!empty($o['categorias'])) {
                    $cats = array_map(fn($c)=>"{$c['categoria']}: {$fmt($c['disponivel'])} livres ({$c['percentual']}% usado)", array_slice($o['categorias'],0,3));
                    $txt .= "\n\nPor categoria:\n- ".implode("\n- ", $cats);
                }
                if ($o['disponivel'] < 0) $txt .= "\n\n⚠️ Você já ultrapassou o orçamento em ". $fmt(abs($o['disponivel'])) .".";
                elseif ($o['percentual_usado'] >= 90) $txt .= "\n\n⚠️ Atenção: só restam {$fmt($o['disponivel'])} — risco alto de estourar.";
                return $txt;
            }
            // "Estou gastando demais?" — avalia
            if (preg_match('/gastando demais|estou.*gastando/', $norm)) {
                if ($o['status'] === 'ultrapassado') return $base."\n\n⚠️ Sim — você já ultrapassou o orçamento total. Categorias estouradas: ".implode(', ', array_map(fn($c)=>$c['categoria']." ({$c['percentual']}%)", $o['ultrapassaram'])).". Sugestão: pause gastos não essenciais e revise ".($o['ultrapassaram'][0]['categoria'] ?? 'a maior categoria').".";
                if ($o['status'] === 'risco') return $base."\n\n⚠️ Está no limite de risco ({$o['percentual_usado']}% usado). Categorias em risco: ".implode(', ', array_map(fn($c)=>$c['categoria']." ({$c['percentual']}%)", $o['em_risco'])).".";
                if ($o['percentual_usado'] >= 60) return $base."\n\nVocê está gastando em ritmo moderado-alto, mas ainda dentro do orçamento.";
                return $base."\n\nNão — seu gasto está controlado ({$o['percentual_usado']}% do orçamento).";
            }
            // "Vou conseguir terminar o mês dentro do orçamento?" — projeção
            if (preg_match('/vou.*conseguir|terminar.*mes.*orçamento|dentro do orcamento.*mes/', $norm)) {
                $diasNoMes = (int)date('t');
                $diasRest = $o['dias_restantes'] ?? max(0, $diasNoMes - (int)date('j'));
                $diasPass = max(1, $diasNoMes - $diasRest);
                $mediaDia = $diasPass > 0 ? $o['utilizado'] / $diasPass : 0;
                $proj = round($mediaDia * $diasNoMes, 2);
                $txt = $base."\n\nProjeção: média de {$fmt($mediaDia)}/dia → estimado {$fmt($proj)} no fim do mês.";
                if ($o['limite'] > 0) {
                    if ($proj > $o['limite']) $txt .= " ⚠️ Projeção ultrapassa o limite em ". $fmt($proj - $o['limite']) .". Sugestão: reduza para ~". $fmt(max(0, ($o['limite'] - $o['utilizado']) / max(1,$diasRest)) ) ."/dia nos próximos {$diasRest} dias.";
                    else $txt .= " ✅ Dentro do orçamento, sobrariam ". $fmt($o['limite'] - $proj) .".";
                }
                return $txt;
            }
            // "Como está meu orçamento?" — geral com detalhes
            $txt = $base;
            if (!empty($o['ultrapassaram'])) {
                $txt .= "\n\n⚠️ Ultrapassaram o limite: ".implode(', ', array_map(fn($c)=>"**{$c['categoria']}** ({$fmt($c['gasto'])}/{$fmt($c['limite'])} = {$c['percentual']}%)", $o['ultrapassaram'])).".";
            }
            if (!empty($o['em_risco'])) {
                $txt .= "\n\n⚠️ Em risco (≥80%): ".implode(', ', array_map(fn($c)=>"{$c['categoria']} ({$c['percentual']}%)", $o['em_risco'])).".";
            }
            if ($o['status'] === 'ok') $txt .= "\n\n✅ Orçamento saudável.";
            elseif ($o['status'] === 'atencao') $txt .= "\n\nAtenção: uso acima de 60%.";
            // dica prática
            if ($o['disponivel'] > 0 && $o['percentual_usado'] < 80) $txt .= " Você ainda pode gastar {$fmt($o['disponivel'])} até o fim do mês.";
            return $txt;
        }
        // saldo
        if (preg_match('/\b(saldo|quanto tenho|saldo atual)\b/', $norm)) {
            return "Seu saldo deste mês ({$ctx['periodo']}) é de **{$fmt($ctx['saldo'])}** (receitas {$fmt($ctx['receitas'])} − despesas {$fmt($ctx['despesas'])}). " .
                   ($ctx['saldo'] >= 0 ? "Você está no positivo — bom sinal! Se quiser, posso detalhar onde está gastando mais." : "Atenção: saldo negativo este mês. Quer ver em quais categorias está pesando mais?");
        }
        // receitas
        if (preg_match('/\b(receita|receitas|ganho|ganhos|renda|quanto recebi)\b/', $norm) && !preg_match('/despesa|gasto/', $norm)) {
            return "Em {$ctx['periodo']} você teve **{$fmt($ctx['receitas'])}** em receitas. Mês anterior foi {$fmt($ctx['comparativo_anterior']['receitas'])}. " .
                   ($ctx['receitas'] > $ctx['comparativo_anterior']['receitas'] ? "Houve aumento em relação ao mês anterior." : "Ficou estável ou abaixo do mês anterior.");
        }
        // === ANÁLISE INTELIGENTE DE CATEGORIAS ===
        $isCategoriaPergunta = preg_match('/categoria|percentual|atencao|destaque|pesando|maior.*(gasto|despesa)|menor.*(gasto|despesa)|onde.*gast|qual.*maior|qual.*menor|como.*gastos.*categoria|quanto.*gasto.*com/i', $q) || preg_match('/\b(categoria|gastando|gastos por categoria)\b/i', $norm);
        // "Quanto gasto com X?" — busca por nome de categoria
        if (preg_match('/quanto.*gasto.*com\s+([a-zçãôáéíóúâê\s]+)/i', $q, $mCat)) {
            $buscaRaw = trim($mCat[1] ?? '');
            $buscaNorm = $norm; // usa norm para busca sem acento
            // extrai apenas o nome após "com"
            if (preg_match('/quanto.*gasto.*com\s+([a-z\s]+)/', $norm, $m2)) $buscaNorm = trim($m2[1]);
            if (empty($ctx['categorias'])) return "Você ainda não registrou despesas por categoria em {$ctx['periodo']}. Registre lançamentos para eu detalhar seus gastos.";
            $encontrada = null;
            foreach ($ctx['categorias'] as $c) {
                $nomeNorm = $this->strLower(function_exists('iconv') ? (iconv('UTF-8','ASCII//TRANSLIT',$c['nome']) ?: $c['nome']) : $c['nome']);
                if (str_contains($nomeNorm, $buscaNorm) || str_contains($buscaNorm, $nomeNorm) || str_contains($this->strLower($q), $this->strLower($c['nome']))) { $encontrada = $c; break; }
            }
            if ($encontrada) {
                $rec = $encontrada['percentual'] >= 30 ? " Ela representa {$encontrada['percentual']}% das suas despesas e merece atenção." : "";
                // variação vs anterior se houver
                $varTxt = '';
                if (!empty($ctx['resumo_categorias']['variacoes'])) {
                    foreach ($ctx['resumo_categorias']['variacoes'] as $v) {
                        if (strtolower($v['nome']) === strtolower($encontrada['nome'])) {
                            $sinal = $v['variacao_percentual']>0?'aumento':'redução';
                            $varTxt = " Variação vs mês anterior: {$sinal} de ".abs($v['variacao_percentual'])."%.";
                            break;
                        }
                    }
                }
                return "Em {$ctx['periodo']} você gastou **{$fmt($encontrada['valor'])}** com **{$encontrada['nome']}** ({$encontrada['percentual']}% do total de {$fmt($ctx['despesas'])}).{$rec}{$varTxt}";
            }
            return "Não encontrei gastos com **\"".trim($buscaRaw)."\"** em {$ctx['periodo']}. Suas categorias com gasto são: ".implode(', ', array_map(fn($c)=>$c['nome']." ({$fmt($c['valor'])})", $ctx['categorias'])).".";
        }
        if ($isCategoriaPergunta) {
            if (empty($ctx['categorias'])) return "Você ainda não registrou despesas por categoria em {$ctx['periodo']}. Registre lançamentos para eu detalhar onde está gastando mais.";
            $resumo = $ctx['resumo_categorias'] ?? null;
            $maior = $resumo['maior'] ?? $ctx['categorias'][0];
            $menor = $resumo['menor'] ?? end($ctx['categorias']);
            // "menor gasto"
            if (preg_match('/menor.*(gasto|despesa)/', $norm)) {
                return "Sua menor despesa em {$ctx['periodo']} foi **{$menor['nome']}** com **{$fmt($menor['valor'])}** ({$menor['percentual']}% do total).";
            }
            // "como estão meus gastos por categoria" / percentual de cada
            if (preg_match('/como.*gastos.*categoria|percentual.*categoria|gastos por categoria/i', $q)) {
                $linhas = [];
                foreach ($ctx['categorias'] as $c) $linhas[] = "- **{$c['nome']}**: {$fmt($c['valor'])} ({$c['percentual']}%)";
                $txt = "Seus gastos por categoria em {$ctx['periodo']} (total {$fmt($ctx['despesas'])}):\n".implode("\n",$linhas);
                if (!empty($resumo['destaques'])) {
                    $txt .= "\n\n⚠️ Atenção: ".implode(', ', array_map(fn($d)=>"**{$d['nome']}** ({$d['percentual']}%)", $resumo['destaques']))." concentram boa parte dos gastos.";
                }
                // recomendação responsável: não cortar essencial de forma absurda
                $txt .= "\n\n✅ Recomendações:\n• Revise primeiro as categorias com maior % antes de cortar.\n• Defina um limite realista para {$maior['nome']} e acompanhe semanalmente.";
                return $txt;
            }
            // "Onde estou gastando mais?" / "maior" / "pesando"
            $list = implode(', ', array_map(fn($c)=>"{$c['nome']} ({$fmt($c['valor'])} - {$c['percentual']}%)", array_slice($ctx['categorias'],0,3)));
            $resp = "Em {$ctx['periodo']} suas maiores despesas foram: **{$list}**. A principal é **{$maior['nome']}** com {$fmt($maior['valor'])} ({$maior['percentual']}% do total de {$fmt($ctx['despesas'])}).";
            if (!empty($resumo['destaques'])) {
                $resp .= " Categorias que merecem atenção (>=30%): ".implode(', ', array_map(fn($d)=>$d['nome']." ({$d['percentual']}%)", $resumo['destaques'])).".";
            }
            if (!empty($resumo['variacoes'])) {
                $v = $resumo['variacoes'][0];
                $resp .= " Maior variação vs mês anterior: **{$v['nome']}** ".($v['variacao_percentual']>0?'aumento':'queda')." de ".abs($v['variacao_percentual'])."%.";
            }
            // recomendação com base real, sem cortes absurdos
            $recVal = round($maior['valor'] * 0.1, 2);
            $resp .= " Uma sugestão responsável é tentar reduzir cerca de 10% em {$maior['nome']} (cerca de {$fmt($recVal)}), ajustando sem comprometer o essencial. Quer um plano semanal para isso?";
            return $resp;
        }
        // despesas gerais (quando não é pergunta específica de categoria)
        if (preg_match('/\b(despesa|despesas|gasto|gastos|gastei|gastando)\b/', $norm) && !str_contains($norm,'receita') && !str_contains($norm,'categoria') && !str_contains($norm,'onde') && !str_contains($norm,'maior') && !str_contains($norm,'menor') && !str_contains($norm,'percentual')) {
            $delta = $ctx['despesas'] - $ctx['comparativo_anterior']['despesas'];
            $sig = $delta>0? 'aumento':'redução';
            return "Em {$ctx['periodo']} suas despesas foram **{$fmt($ctx['despesas'])}** (mês anterior {$fmt($ctx['comparativo_anterior']['despesas'])} — {$sig} de {$fmt(abs($delta))}). Quer que eu analise por categoria?";
        }
        // === ANÁLISE INTELIGENTE DE METAS ===
        if (preg_match('/\b(meta|metas|objetivo|caminho certo)\b/', $norm)) {
            if (empty($ctx['metas'])) return "Você ainda não cadastrou metas. Crie uma meta em **Metas** para eu acompanhar seu progresso.";
            $metas = $ctx['metas'];
            // "Quanto falta para minha meta?" — quanto preciso guardar/saldo da meta
            if (preg_match('/quanto.*falta|quanto.*guardar|quanto.*sobrou|quanto.*preciso/', $norm)) {
                $lines = [];
                foreach ($metas as $m) {
                    $linha = "**{$m['nome']}**: {$m['percentual']}% concluída — faltam **{$fmt($m['restante'])}**";
                    if ($m['sugestao_mensal'] !== null) $linha .= " → sugestão de **{$fmt($m['sugestao_mensal'])}**/mês ({$m['meses_restantes']} meses)";
                    $lines[] = $linha;
                }
                $concluidas = array_filter($metas, fn($m)=>$m['status']==='concluida');
                $txt = "Situação das metas:\n" . implode("\n", $lines);
                if (!empty($concluidas)) $txt .= "\n\n✅ ".count($concluidas)." meta(s) já concluída(s)!";
                return $txt;
            }
            // "Estou no caminho certo?" / "como estão minhas metas?" — avaliação de progresso
            if (preg_match('/caminho certo|como.*meta|status.*meta|progresso.*meta/', $norm) || preg_match('/\b(meta|metas)\b/', $norm)) {
                $concluidas = array_filter($metas, fn($m)=>$m['status']==='concluida');
                $emRisco = array_filter($metas, fn($m)=>$m['status']==='iniciante' && $m['meses_restantes'] !== null && $m['percentual'] < 20);
                $txt = "Suas metas:\n";
                $linhas = [];
                foreach ($metas as $m) {
                    $badge = match($m['status']) {
                        'concluida' => '✅ Concluída',
                        'boa_progresso' => '🟡 Boa',
                        'iniciante' => '🔴 Inicial',
                        'sem_prazo' => '⚪ Sem prazo',
                        default => '⚪',
                    };
                    $prazotxt = $m['prazo'] ? " — prazo {$m['prazo']}" : " — sem prazo definido";
                    $sobra = $m['restante'] > 0 ? ", faltam {$fmt($m['restante'])}" : "";
                    $sugestao = $m['sugestao_mensal'] !== null ? ", guardar {$fmt($m['sugestao_mensal'])}/mês" : "";
                    $linhas[] = "- {$badge} **{$m['nome']}**: {$m['percentual']}% ({$fmt($m['atual'])}/{$fmt($m['alvo'])}){$prazotxt}{$sobra}{$sugestao}";
                }
                $txt .= implode("\n", $linhas);
                if (!empty($concluidas)) $txt .= "\n\n✅ ".count($concluidas)." meta(s) concluída(s)!";
                if (!empty($emRisco)) {
                    $nomes = implode(', ', array_map(fn($m)=>$m['nome'], $emRisco));
                    $txt .= "\n\n⚠️ {$nomes} — progresso baixo e prazo se aproximando. Acelere os aportes!";
                }
                $txt .= "\n\nQuer planos para acelerar alguma meta?";
                return $txt;
            }
            // "Quanto preciso guardar por mês?"
            if (preg_match('/guardar.*mes|apartar.*mes|montante.*mes|quanto.*mes/', $norm)) {
                $comPrazo = array_filter($metas, fn($m)=>$m['sugestao_mensal'] !== null);
                $semPrazo = array_filter($metas, fn($m)=>$m['sugestao_mensal'] === null && $m['restante'] > 0);
                $txt = "Para suas metas:\n";
                foreach ($comPrazo as $m) {
                    $txt .= "- **{$m['nome']}**: {$fmt($m['sugestao_mensal'])}/mês pelos próximos {$m['meses_restantes']} meses ({$fmt($m['restante'])} restantes).\n";
                }
                if (!empty($semPrazo)) {
                    $txt .= "\nMetas sem prazo definido — só calcular quanto pretende guardar:\n";
                    foreach ($semPrazo as $m) $txt .= "- {$m['nome']}: {$fmt($m['restante'])} restantes.\n";
                    $txt .= "\nDefina um prazo em Metas para eu calcular a sugestão mensal.";
                }
                $concluidas = array_filter($metas, fn($m)=>$m['status']==='concluida');
                if (!empty($concluidas)) $txt .= "\n\n✅ ".count($concluidas)." meta(s) já concluída(s)!";
                return $txt;
            }
            // fallback: listagem simples
            $lines = [];
            foreach ($metas as $m) {
                $restanteTxt = $m['restante'] > 0 ? ", faltam {$fmt($m['restante'])}" : "";
                $prazoTxt = $m['prazo'] ? " — prazo {$m['prazo']}" : "";
                $lines[] = "- **{$m['nome']}**: {$fmt($m['atual'])} de {$fmt($m['alvo'])} ({$m['percentual']}%){$restanteTxt}{$prazoTxt}";
            }
            return "Suas metas:\n" . implode("\n", $lines) . "\n\nQuer uma análise mais detalhada de alguma meta específica?";
        }
        return null;
    }

    public function callAi(string $userMessage, array $context, array $history = []): string
    {
        $cfg = self::getConfig();
        if ($cfg['key'] === '') {
            throw new RuntimeException('IA não configurada: defina AI_API_KEY no .env ou na Vercel.');
        }
        // Proteção custo zero: só permite modelos gratuitos
        if (!self::isFreeModel($cfg['model'])) {
            throw new RuntimeException('Modelo configurado não é gratuito. Configure AI_MODEL=openrouter/free ou um modelo com sufixo :free.');
        }

        $system = $this->getSystemPrompt();
        // Monta mensagens: system + contexto + histórico + nova pergunta
        $messages = [
            ['role'=>'system','content'=>$system],
            ['role'=>'system','content'=>"Contexto financeiro do usuário (JSON resumido, período {$context['periodo']}):\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];
        // adiciona histórico (limita a últimas 6 trocas para economizar tokens)
        $history = array_slice($history, -6);
        foreach ($history as $h) {
            if (!isset($h['role'],$h['content'])) continue;
            $role = $h['role']==='user'?'user':'assistant';
            $messages[] = ['role'=>$role,'content'=>$this->subStr((string)$h['content'],0,2000)];
        }
        $messages[] = ['role'=>'user','content'=>$this->subStr($userMessage,0,2000)];

        $payload = json_encode([
            'model' => $cfg['model'],
            'messages' => $messages,
            'max_tokens' => $cfg['max_tokens'],
            'temperature' => $cfg['temperature'],
        ], JSON_UNESCAPED_UNICODE);

        if (!function_exists('curl_init')) {
            // fallback via file_get_contents se curl não disponível
            $headers = [];
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Authorization: Bearer ' . $cfg['key'];
            if (str_contains($cfg['url'], 'openrouter')) {
                $ref = getenv('APP_URL') ?: 'https://controle-de-gastos.vercel.app';
                $headers[] = 'HTTP-Referer: ' . $ref;
                $headers[] = 'X-Title: Controle de Gastos - Assistente IA';
            }
            $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers)."\r\n",'content'=>$payload,'timeout'=>60,'ignore_errors'=>true]]);
            $resp = @file_get_contents($cfg['url'], false, $ctx);
            $err = $resp===false ? (error_get_last()['message'] ?? 'conexão falhou') : '';
            $code = 0;
            $hdr = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
            foreach ($hdr as $h) if (preg_match('#HTTP/\d\.\d\s+(\d+)#',$h,$m)) { $code=(int)$m[1]; break; }
        } else {
            $ch = curl_init($cfg['url']);
            $headersCurl = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['key'],
            ];
            if (str_contains($cfg['url'], 'openrouter')) {
                $ref = getenv('APP_URL') ?: 'https://controle-de-gastos.vercel.app';
                $headersCurl[] = 'HTTP-Referer: ' . $ref;
                $headersCurl[] = 'X-Title: Controle de Gastos - Assistente IA';
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headersCurl,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }

        // Log seguro apenas em caso de erro ou para debug mínimo
        if ($err || $code >= 400) {
            error_log('[ai] url='.$cfg['url'].' model='.$cfg['model'].' http='.$code.' err='.($err?substr($err,0,200):'none').' resp_len='.strlen($resp??''));
        }

        if ($err) {
            $isTimeout = stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false || stripos($err, 'timedout') !== false;
            if ($isTimeout) {
                error_log('[ai] timeout model='.$cfg['model'].' err='.substr($err,0,200).' time=60s');
                throw new RuntimeException('A IA gratuita demorou mais que o esperado. Tente novamente em alguns instantes.');
            }
            throw new RuntimeException('Falha de conexão com a IA: ' . $err);
        }
        if ($resp === false || $resp === '') throw new RuntimeException('Resposta vazia da IA.');
        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[ai] json_error='.json_last_error_msg().' resp_snip='.substr($resp,0,600));
            throw new RuntimeException('Resposta inválida da IA (JSON).');
        }
        if ($code < 200 || $code >= 300) {
            $msg = $data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null) ?? substr($resp,0,600);
            if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            $msgLower = strtolower((string)$msg);
            error_log('[ai] http_error code='.$code.' msg='.substr((string)$msg,0,400));
            // Proteção custo zero: nunca cair para modelo pago se free falhar
            $isFree = self::isFreeModel($cfg['model']);
            $noFreeAvailable = $isFree && ($code === 404 || $code === 429 || $code === 503 || str_contains($msgLower,'no free') || str_contains($msgLower,'no model') || str_contains($msgLower,'unavailable') || str_contains($msgLower,'not found'));
            if ($noFreeAvailable) {
                throw new RuntimeException('Os modelos gratuitos estão temporariamente indisponíveis. Tente novamente em alguns instantes.');
            }
            // Mensagens amigáveis por código
            if ($code === 401) throw new RuntimeException('API Key inválida ou não autorizada.');
            if ($code === 402) throw new RuntimeException('Créditos insuficientes no OpenRouter.');
            if ($code === 429) throw new RuntimeException('Muitas requisições — aguarde alguns segundos.');
            if ($code === 404) throw new RuntimeException('Modelo não encontrado: '.$cfg['model']);
            throw new RuntimeException('Erro da IA ('.$code.'): ' . substr((string)$msg,0,300));
        }
        // Diagnóstico: verifica estrutura real do OpenRouter
        $choice = $data['choices'][0] ?? null;
        // Log seguro da estrutura (sem segredos) quando necessário
        $hasChoices = isset($data['choices']) && is_array($data['choices']);
        $hasMessage = isset($choice['message']) && is_array($choice['message']);
        $contentType = isset($choice['message']['content']) ? (is_array($choice['message']['content']) ? 'array('.count($choice['message']['content']).')' : gettype($choice['message']['content']).':'.strlen((string)$choice['message']['content'])) : 'ausente';
        error_log('[ai] diag choices='.($hasChoices?'sim('.count($data['choices']).')':'nao').' message='.($hasMessage?'sim':'nao').' content='.$contentType.' http='.$code);

        // Extração robusta: tenta todas as formas conhecidas do OpenRouter/Qwen
        $content = null;
        if (isset($choice['message']['content'])) {
            $c = $choice['message']['content'];
            if (is_string($c) && trim($c) !== '') {
                $content = $c;
            } elseif (is_array($c)) {
                // Qwen pode retornar content como array de parts [{type:text,text:...}]
                $parts = [];
                foreach ($c as $part) {
                    if (is_string($part) && trim($part) !== '') $parts[] = $part;
                    elseif (is_array($part) && isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') $parts[] = $part['text'];
                    elseif (is_array($part) && isset($part['content']) && is_string($part['content']) && trim($part['content']) !== '') $parts[] = $part['content'];
                }
                $joined = implode("\n", array_filter($parts));
                if (trim($joined) !== '') $content = $joined;
            }
        }
        // Fallbacks para modelos de raciocínio Qwen3
        if (($content === null || trim((string)$content) === '') && isset($choice['message']['reasoning']) && is_string($choice['message']['reasoning']) && trim($choice['message']['reasoning']) !== '') {
            $content = $choice['message']['reasoning'];
            error_log('[ai] fallback reasoning len='.strlen($content));
        }
        if (($content === null || trim((string)$content) === '') && isset($choice['message']['reasoning_details']) && is_array($choice['message']['reasoning_details'])) {
            $rtexts = [];
            foreach ($choice['message']['reasoning_details'] as $rd) {
                if (is_array($rd) && isset($rd['text']) && is_string($rd['text'])) $rtexts[] = $rd['text'];
                elseif (is_string($rd)) $rtexts[] = $rd;
            }
            $joined = implode("\n", array_filter($rtexts, fn($v) => trim($v) !== ''));
            if (trim($joined) !== '') { $content = $joined; error_log('[ai] fallback reasoning_details len='.strlen($content)); }
        }
        if (($content === null || trim((string)$content) === '') && isset($choice['message']['reasoning_content']) && is_string($choice['message']['reasoning_content']) && trim($choice['message']['reasoning_content']) !== '') {
            $content = $choice['message']['reasoning_content'];
        }
        if (($content === null || trim((string)$content) === '') && isset($choice['text']) && is_string($choice['text']) && trim($choice['text']) !== '') {
            $content = $choice['text'];
        }
        if (($content === null || trim((string)$content) === '') && isset($choice['delta']['content']) && is_string($choice['delta']['content']) && trim($choice['delta']['content']) !== '') {
            $content = $choice['delta']['content'];
        }
        // Diagnóstico específico para cada tipo de falha (sem expor segredos)
        if ($content === null || trim((string)$content) === '') {
            $snip = substr($resp, 0, 2000);
            $hasChoices = isset($data['choices']) && is_array($data['choices']) && count($data['choices'])>0;
            $hasMsg = $hasChoices && isset($data['choices'][0]['message']);
            $hasContent = $hasMsg && array_key_exists('content', $data['choices'][0]['message']);
            $contentVal = $hasContent ? $data['choices'][0]['message']['content'] : null;
            $contentDesc = $contentVal === null ? 'null' : (is_string($contentVal) ? (trim($contentVal)===''? 'vazio':'string('.strlen($contentVal).')') : gettype($contentVal));
            error_log('[ai] ERRO content vazio — hasChoices='.($hasChoices?'sim':'nao').' hasMessage='.($hasMsg?'sim':'nao').' content='.$contentDesc.' raw_snip='.substr($snip,0,1200).' data_keys='.implode(',',array_keys($data)).' choice_keys='.($choice?implode(',',array_keys($choice)):'none'));
            // Se for roteador gratuito e veio vazio, trata como indisponibilidade temporária (custo zero)
            if (self::isFreeModel($cfg['model'])) {
                throw new RuntimeException('Os modelos gratuitos estão temporariamente indisponíveis. Tente novamente em alguns instantes.');
            }
            if (!$hasChoices) throw new RuntimeException('Resposta inesperada: estrutura diferente da esperada (sem choices).');
            if (!$hasMsg) throw new RuntimeException('Resposta inesperada: estrutura diferente da esperada (sem message).');
            if ($contentVal === null) throw new RuntimeException('Resposta vazia: content null');
            if (is_string($contentVal) && trim($contentVal)==='') throw new RuntimeException('Resposta vazia: content vazio');
            throw new RuntimeException('Resposta vazia da IA.');
        }
        return trim((string)$content);
    }
}
