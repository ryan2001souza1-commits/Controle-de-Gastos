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

    public static function getConfig(): array
    {
        $key = getenv('AI_API_KEY') ?: getenv('OPENROUTER_API_KEY') ?: getenv('OPENAI_API_KEY') ?: '';
        $url = getenv('AI_API_URL') ?: getenv('OPENROUTER_API_URL') ?: 'https://openrouter.ai/api/v1/chat/completions';
        $model = getenv('AI_MODEL') ?: getenv('OPENROUTER_MODEL') ?: 'qwen/qwen3-30b-a3b:free';
        $maxTokens = (int)(getenv('AI_MAX_TOKENS') ?: 700);
        $temperature = (float)(getenv('AI_TEMPERATURE') ?: 0.35);
        return [
            'key' => trim($key),
            'url' => trim($url),
            'model' => trim($model),
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
    public function tryDeterministicAnswer(string $question, array $ctx): ?string
    {
        $q = mb_strtolower(trim($question));
        // normaliza acentos básico
        $norm = iconv('UTF-8','ASCII//TRANSLIT',$q) ?: $q;

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
        // despesas / onde gasto mais
        if (preg_match('/\b(despesa|despesas|gasto|gastos|gastei|onde.*gasto|mais gasto)\b/', $norm) && !str_contains($norm,'receita')) {
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
            $messages[] = ['role'=>$role,'content'=>mb_substr((string)$h['content'],0,2000)];
        }
        $messages[] = ['role'=>'user','content'=>mb_substr($userMessage,0,2000)];

        $payload = json_encode([
            'model' => $cfg['model'],
            'messages' => $messages,
            'max_tokens' => $cfg['max_tokens'],
            'temperature' => $cfg['temperature'],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($cfg['url']);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['key'],
        ];
        // OpenRouter requer headers adicionais
        if (str_contains($cfg['url'], 'openrouter')) {
            $ref = getenv('APP_URL') ?: 'https://controle-de-gastos.vercel.app';
            $headers[] = 'HTTP-Referer: ' . $ref;
            $headers[] = 'X-Title: Controle de Gastos - Assistente IA';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) throw new RuntimeException('Falha de conexão com a IA: ' . $err);
        if ($resp === false) throw new RuntimeException('Resposta vazia da IA.');
        $data = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            $msg = $data['error']['message'] ?? $data['error'] ?? substr($resp,0,400);
            throw new RuntimeException('Erro da IA ('.$code.'): ' . $msg);
        }
        $content = $data['choices'][0]['message']['content'] ?? $data['choices'][0]['text'] ?? null;
        if (!$content) throw new RuntimeException('Resposta da IA vazia.');
        return trim((string)$content);
    }
}
