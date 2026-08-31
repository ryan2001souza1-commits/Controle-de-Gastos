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
        $model = self::env('AI_MODEL') ?: self::env('OPENROUTER_MODEL') ?: 'qwen/qwen3-30b-a3b:free';
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
Você é o Assistente Financeiro do sistema "Controle de Gastos". Responda sempre em português do Brasil, de forma simples, direta e acolhedora.

Regras obrigatórias:
- Use APENAS os dados financeiros fornecidos no contexto JSON. Nunca invente valores, transações, categorias ou metas.
- Se não houver dados suficientes, diga claramente que não há informação suficiente e sugira o que o usuário pode fazer (ex: registrar lançamentos).
- Faça cálculos quando necessário (percentuais, diferenças, projeções simples) usando os números do contexto.
- Identifique padrões: maior categoria de gasto, variação vs mês anterior, orçamento estourado, metas atrasadas.
- Sugira ações práticas para economizar, mas sem prometer rentabilidade ou fazer aconselhamento profissional certificado. Use linguagem: "uma sugestão é..." não "você deve investir em...".
- Nunca peça senha, token, ou dados sensíveis. Nunca diga que executou ações no banco.
- Mantenha respostas concisas (3-6 parágrafos curtos), use bullet points quando listar.
- Quando o usuário perguntar algo que pode ser respondido com cálculo direto (saldo, receitas, despesas, orçamento disponível), responda objetivamente com os números e uma breve interpretação.
- Se o usuário perguntar sobre algo fora de finanças pessoais, responda educadamente que seu foco é finanças.
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

        // resumo geral finanças (economia de tokens)
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
        // despesas / onde gasto mais (inclui gastando/gast* variações)
        if (preg_match('/\b(despesa|despesas|gasto|gastos|gastei|gastando|onde.*gast|mais.*gast)\b/', $norm) && !str_contains($norm,'receita')) {
            if (strpos($norm,'onde')!==false || strpos($norm,'mais')!==false || strpos($norm,'categoria')!==false) {
                if (empty($ctx['categorias'])) return "Você ainda não registrou despesas por categoria em {$ctx['periodo']}. Registre lançamentos para eu detalhar onde está gastando mais.";
                $top = $ctx['categorias'][0];
                $list = implode(', ', array_map(fn($c)=>"{$c['nome']} ({$fmt($c['valor'])} - {$c['percentual']}%)", array_slice($ctx['categorias'],0,3)));
                return "Suas maiores despesas em {$ctx['periodo']} foram: **{$list}**. A principal é **{$top['nome']}** com {$fmt($top['valor'])} ({$top['percentual']}% do total de {$fmt($ctx['despesas'])}). Quer sugestões para otimizar essa categoria?";
            }
            $delta = $ctx['despesas'] - $ctx['comparativo_anterior']['despesas'];
            $sig = $delta>0? 'aumento':'redução';
            return "Em {$ctx['periodo']} suas despesas foram **{$fmt($ctx['despesas'])}** (mês anterior {$fmt($ctx['comparativo_anterior']['despesas'])} — {$sig} de {$fmt(abs($delta))}). Quer que eu analise por categoria?";
        }
        // orçamento
        if (preg_match('/\b(orcamento|orçamento|posso gastar|ainda posso|disponivel|disponível)\b/', $norm)) {
            $o = $ctx['orcamento'];
            if (($o['limite'] ?? 0) <= 0) return "Você ainda não definiu um orçamento para {$ctx['periodo']}. Defina um limite mensal em Orçamentos para eu acompanhar quanto ainda pode gastar.";
            $msg = "Seu orçamento de {$ctx['periodo']} é **{$fmt($o['limite'])}**. Já utilizou **{$fmt($o['utilizado'])}** ({$o['percentual_usado']}%) e ainda tem **{$fmt($o['disponivel'])}** disponíveis.";
            if ($o['percentual_usado'] >= 90) $msg .= " Atenção: quase no limite — evite novos gastos não essenciais.";
            elseif ($o['percentual_usado'] >= 70) $msg .= " Você está com uso moderado-alto, bom momento para revisar categorias.";
            return $msg . " Quer que eu simule um gasto de R\$ 300?";
        }
        // metas
        if (preg_match('/\b(meta|metas|objetivo|caminho certo)\b/', $norm)) {
            if (empty($ctx['metas'])) return "Você ainda não cadastrou metas. Crie uma meta em \"Metas\" para eu acompanhar seu progresso.";
            $lines = [];
            foreach ($ctx['metas'] as $m) $lines[] = "- **{$m['nome']}**: {$fmt($m['atual'])} de {$fmt($m['alvo'])} ({$m['percentual']}%)" . ($m['prazo']?" — prazo {$m['prazo']}":"");
            return "Suas metas:\n" . implode("\n", $lines) . "\n\nVocê está indo bem nas metas com maior percentual. Quer dicas para acelerar a que está mais atrasada?";
        }
        return null;
    }

    public function callAi(string $userMessage, array $context, array $history = []): string
    {
        $cfg = self::getConfig();
        if ($cfg['key'] === '') {
            throw new RuntimeException('IA não configurada: defina AI_API_KEY no .env ou na Vercel.');
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
            $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers)."\r\n",'content'=>$payload,'timeout'=>9,'ignore_errors'=>true]]);
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
                CURLOPT_TIMEOUT => 9,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        // Log seguro apenas em caso de erro ou para debug mínimo
        if ($err || $code >= 400) {
            error_log('[ai] url='.$cfg['url'].' model='.$cfg['model'].' http='.$code.' err='.($err?substr($err,0,200):'none').' resp_len='.strlen($resp??''));
        }

        if ($err) throw new RuntimeException('Falha de conexão com a IA: ' . $err);
        if ($resp === false || $resp === '') throw new RuntimeException('Resposta vazia da IA.');
        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[ai] json_error='.json_last_error_msg().' resp_snip='.substr($resp,0,600));
            throw new RuntimeException('Resposta inválida da IA (JSON).');
        }
        if ($code < 200 || $code >= 300) {
            $msg = $data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null) ?? substr($resp,0,600);
            if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            error_log('[ai] http_error code='.$code.' msg='.substr((string)$msg,0,400));
            // Mensagens amigáveis por código
            if ($code === 401) throw new RuntimeException('API Key inválida ou não autorizada.');
            if ($code === 402) throw new RuntimeException('Créditos insuficientes no OpenRouter.');
            if ($code === 429) throw new RuntimeException('Muitas requisições — aguarde alguns segundos.');
            if ($code === 404) throw new RuntimeException('Modelo não encontrado: '.$cfg['model']);
            throw new RuntimeException('Erro da IA ('.$code.'): ' . substr((string)$msg,0,300));
        }
        // OpenRouter / OpenAI — formatos possíveis (content, reasoning, text)
        $choice = $data['choices'][0] ?? null;
        $content = $choice['message']['content'] ?? null;
        // fallback para reasoning (Qwen3 retorna reasoning separado)
        if (($content === null || trim((string)$content) === '') && isset($choice['message']['reasoning'])) {
            $content = $choice['message']['reasoning'];
        }
        if (($content === null || trim((string)$content) === '') && isset($choice['text'])) {
            $content = $choice['text'];
        }
        if (($content === null || trim((string)$content) === '')) {
            error_log('[ai] content vazio, resp_snip='.substr($resp,0,800));
        }
        if ($content === null || trim((string)$content) === '') {
            throw new RuntimeException('Resposta da IA vazia.');
        }
        return trim((string)$content);
    }
}
