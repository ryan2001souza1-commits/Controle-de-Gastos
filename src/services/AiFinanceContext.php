<?php
class AiFinanceContext
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Monta contexto financeiro resumido do usuário para envio à IA.
     * Limita tamanho e expõe apenas dados agregados, nunca sensíveis.
     */
    public function build(int $userId): array
    {
        $now = new DateTimeImmutable('now');
        $start = $now->modify('first day of this month')->format('Y-m-d');
        $end   = $now->modify('last day of this month')->format('Y-m-d');
        $year  = (int)$now->format('Y');
        $month = (int)$now->format('n');
        $label = $now->format('m/Y');

        // Receitas / despesas do mês
        $receitas = $this->sumTransacoes($userId, 'receita', $start, $end);
        $despesas = $this->sumTransacoes($userId, 'despesa', $start, $end);
        $saldo = round($receitas - $despesas, 2);

        // Mês anterior para comparação
        $prevStart = $now->modify('first day of last month')->format('Y-m-d');
        $prevEnd   = $now->modify('last day of last month')->format('Y-m-d');
        $prevReceitas = $this->sumTransacoes($userId, 'receita', $prevStart, $prevEnd);
        $prevDespesas = $this->sumTransacoes($userId, 'despesa', $prevStart, $prevEnd);

        // Despesas por categoria (top 10) no mês — para análise completa
        $categorias = $this->categoriasTop($userId, $start, $end, 10);
        $categoriasAnterior = $this->categoriasTop($userId, $prevStart, $prevEnd, 10);

        // Orçamento do mês
        $orcamento = $this->orcamentoResumo($userId, $year, $month);

        // Metas (máx 3)
        $metas = $this->metasResumo($userId, 3);

        // Histórico mensal (últimos 6 meses)
        $historico = $this->historicoMensal($userId, 6);

        // Resumo de categorias para análise inteligente
        $resumoCategorias = $this->resumoCategorias($categorias, $categoriasAnterior);

        // Comparativo completo entre mês atual e anterior (variação % + top mudanças)
        $comparativo = $this->comparativoCompleto($receitas, $despesas, $prevReceitas, $prevDespesas, $categorias, $categoriasAnterior, $orcamento);

        return [
            'periodo' => $label,
            'periodo_extenso' => $now->format('F \d\e Y'),
            'receitas' => round($receitas, 2),
            'despesas' => round($despesas, 2),
            'saldo' => $saldo,
            'orcamento' => $orcamento,
            'categorias' => $categorias,
            'categorias_anterior' => $categoriasAnterior,
            'resumo_categorias' => $resumoCategorias,
            'metas' => $metas,
            'historico_mensal' => $historico,
            'comparativo' => $comparativo,
            'comparativo_anterior' => [
                'receitas' => round($prevReceitas, 2),
                'despesas' => round($prevDespesas, 2),
                'saldo' => round($prevReceitas - $prevDespesas, 2),
            ],
        ];
    }

    private function sumTransacoes(int $userId, string $tipo, string $start, string $end): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(valor),0) FROM transacoes WHERE usuario_id=? AND tipo=? AND data BETWEEN ? AND ?");
        $stmt->execute([$userId, $tipo, $start, $end]);
        return (float)$stmt->fetchColumn();
    }

    private function categoriasTop(int $userId, string $start, string $end, int $limit): array
    {
        $sql = "SELECT c.nome, COALESCE(SUM(t.valor),0) as total
                FROM categorias c
                LEFT JOIN transacoes t ON t.categoria_id=c.id AND t.usuario_id=c.usuario_id AND t.tipo='despesa' AND t.data BETWEEN ? AND ?
                WHERE c.usuario_id=? AND c.tipo='despesa'
                GROUP BY c.id, c.nome
                HAVING COALESCE(SUM(t.valor),0) > 0
                ORDER BY total DESC
                LIMIT $limit";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$start, $end, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = array_sum(array_column($rows, 'total'));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'nome' => $r['nome'],
                'valor' => round((float)$r['total'], 2),
                'percentual' => $total > 0 ? round(((float)$r['total'] / $total) * 100, 1) : 0,
            ];
        }
        return $out;
    }

    private function orcamentoResumo(int $userId, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        // Detalhe por categoria (se houver orçamentos)
        $stmt = $this->db->prepare("
            SELECT o.categoria_id, c.nome as categoria_nome, o.valor_limite as limite,
                   COALESCE(SUM(t.valor),0) as gasto
            FROM orcamentos o
            JOIN categorias c ON c.id = o.categoria_id AND c.usuario_id = o.usuario_id
            LEFT JOIN transacoes t ON t.categoria_id = o.categoria_id AND t.usuario_id = o.usuario_id AND t.tipo='despesa' AND t.data BETWEEN ? AND ?
            WHERE o.usuario_id=? AND o.ano=? AND o.mes=?
            GROUP BY o.categoria_id, c.nome, o.valor_limite
            ORDER BY o.valor_limite DESC
        ");
        $stmt->execute([$start, $end, $userId, $year, $month]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $limiteTotal = 0; $gastoTotal = 0;
        $detalhes = [];
        $ultrapassaram = [];
        $emRisco = [];
        foreach ($rows as $r) {
            $lim = (float)$r['limite'];
            $gasto = (float)$r['gasto'];
            $disp = round($lim - $gasto, 2);
            $pct = $lim > 0 ? round(min(200, ($gasto / $lim) * 100), 1) : 0;
            $status = $pct >= 100 ? 'ultrapassado' : ($pct >= 80 ? 'risco' : 'ok');
            $item = [
                'categoria' => $r['categoria_nome'],
                'limite' => round($lim,2),
                'gasto' => round($gasto,2),
                'disponivel' => $disp,
                'percentual' => $pct,
                'status' => $status,
            ];
            $detalhes[] = $item;
            $limiteTotal += $lim;
            // para total consideramos apenas gasto das categorias orçadas se houver orçamento, senão total despesas
            $gastoTotal += $gasto;
            if ($status === 'ultrapassado') $ultrapassaram[] = $item;
            elseif ($status === 'risco') $emRisco[] = $item;
        }

        if (empty($rows)) {
            // sem orçamento por categoria: usa total despesas do mês
            $gastoTotal = $this->sumTransacoes($userId, 'despesa', $start, $end);
            $limiteTotal = 0;
        } else {
            // se houver orçamento, gastoTotal já é soma dos gastos orçados; para limite total vs gasto total real,
            // usa gasto real total para disponível geral (mais útil para "quanto posso gastar")
            $gastoReal = $this->sumTransacoes($userId, 'despesa', $start, $end);
            // mantém gastoTotal como soma orçada para detalhe, mas para disponível geral usa gastoReal
            $gastoTotal = $gastoReal;
        }

        $disponivel = $limiteTotal > 0 ? round($limiteTotal - $gastoTotal, 2) : 0;
        $pctTotal = $limiteTotal > 0 ? round(min(200, ($gastoTotal / $limiteTotal) * 100), 1) : 0;
        $statusGeral = $limiteTotal==0 ? 'sem_orcamento' : ($pctTotal >= 100 ? 'ultrapassado' : ($pctTotal >= 80 ? 'risco' : ($pctTotal >= 60 ? 'atencao' : 'ok')));

        return [
            'limite' => round($limiteTotal, 2),
            'utilizado' => round($gastoTotal, 2),
            'disponivel' => $disponivel,
            'percentual_usado' => $pctTotal,
            'status' => $statusGeral,
            'categorias' => $detalhes,
            'ultrapassaram' => $ultrapassaram,
            'em_risco' => $emRisco,
            'dias_restantes' => (int)date('t', strtotime($start)) - (int)date('j'),
        ];
    }

    private function metasResumo(int $userId, int $limit): array
    {
        $stmt = $this->db->prepare("SELECT nome, valor_objetivo as alvo, valor_acumulado as atual, data_limite as prazo FROM metas WHERE usuario_id=? ORDER BY data_limite ASC NULLS LAST, nome ASC LIMIT $limit");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hoje = new DateTimeImmutable('first day of this month');
        $out = [];
        foreach ($rows as $r) {
            $alvo = (float)$r['alvo'];
            $atual = (float)$r['atual'];
            $restante = round(max(0, $alvo - $atual), 2);
            $pct = $alvo > 0 ? round(min(100, ($atual / $alvo) * 100), 1) : 0;
            // meses restantes: pelo menos 1 se ainda há valor a acumular e há prazo
            $meses = null;
            $sugestaoMensal = null;
            $status = 'em_andamento';
            if ($alvo > 0 && $atual >= $alvo) {
                $status = 'concluida';
            }
            if (!empty($r['prazo'])) {
                try {
                    $prazo = new DateTimeImmutable($r['prazo']);
                    $diff = (int)$hoje->diff($prazo)->format('%r%m') + (int)$hoje->diff($prazo)->format('%r%y') * 12;
                    $meses = max(1, $diff);
                    if ($status !== 'concluida' && $meses > 0) {
                        $sugestaoMensal = round($restante / $meses, 2);
                    }
                    if ($status !== 'concluida') {
                        $status = $pct >= 100 ? 'concluida' : ($pct >= 50 ? 'boa_progresso' : 'iniciante');
                    }
                } catch (Exception $e) {
                    // data inválida: mantém sem meses
                }
            } else {
                $status = $pct >= 100 ? 'concluida' : 'sem_prazo';
            }
            $out[] = [
                'nome' => $r['nome'],
                'alvo' => round($alvo, 2),
                'atual' => round($atual, 2),
                'restante' => $restante,
                'percentual' => $pct,
                'prazo' => $r['prazo'] ? date('d/m/Y', strtotime($r['prazo'])) : null,
                'meses_restantes' => $meses,
                'sugestao_mensal' => $sugestaoMensal,
                'status' => $status,
            ];
        }
        return $out;
    }

    private function resumoCategorias(array $atuais, array $anterior): array
    {
        if (empty($atuais)) {
            return ['total_categorias'=>0, 'maior'=>null, 'menor'=>null, 'destaques'=>[], 'variacoes'=>[]];
        }
        $maior = $atuais[0];
        $menor = $atuais[count($atuais)-1];
        // destaques: categorias >30% merecem atenção
        $destaques = array_values(array_filter($atuais, fn($c)=>$c['percentual']>=30));
        // variações vs mês anterior
        $mapAnt = [];
        foreach ($anterior as $a) $mapAnt[strtolower($a['nome'])] = (float)$a['valor'];
        $variacoes = [];
        foreach ($atuais as $c) {
            $ant = $mapAnt[strtolower($c['nome'])] ?? 0;
            if ($ant > 0) {
                $diff = round((($c['valor'] - $ant) / $ant) * 100, 1);
                if (abs($diff) >= 15) $variacoes[] = ['nome'=>$c['nome'], 'atual'=>$c['valor'], 'anterior'=>$ant, 'variacao_percentual'=>$diff];
            } elseif ($ant == 0 && $c['valor'] > 0) {
                $variacoes[] = ['nome'=>$c['nome'], 'atual'=>$c['valor'], 'anterior'=>0, 'variacao_percentual'=>100];
            }
        }
        usort($variacoes, fn($a,$b)=>abs($b['variacao_percentual']) <=> abs($a['variacao_percentual']));
        return [
            'total_categorias'=>count($atuais),
            'maior'=>$maior,
            'menor'=>$menor,
            'destaques'=>$destaques,
            'variacoes'=>array_slice($variacoes,0,3),
        ];
    }

    private function variacao(float $atual, float $anterior): array
    {
        $delta = round($atual - $anterior, 2);
        if ($anterior == 0 && $atual == 0) {
            $pct = 0; $sinal = 'estavel';
        } elseif ($anterior == 0) {
            $pct = 100; $sinal = 'aumento';
        } else {
            $pct = round(($delta / abs($anterior)) * 100, 1);
            $sinal = $delta > 0 ? 'aumento' : ($delta < 0 ? 'reducao' : 'estavel');
        }
        return ['anterior'=>round($anterior,2), 'atual'=>round($atual,2), 'delta'=>$delta, 'percentual'=>$pct, 'sinal'=>$sinal];
    }

    private function comparativoCompleto(float $receitas, float $despesas, float $prevReceitas, float $prevDespesas, array $categorias, array $categoriasAnterior, array $orcamento): array
    {
        // Variações principais
        $varReceitas = $this->variacao($receitas, $prevReceitas);
        $varDespesas = $this->variacao($despesas, $prevDespesas);
        $varSaldo = $this->variacao($receitas - $despesas, $prevReceitas - $prevDespesas);

        // Variação por categoria
        $mapAnt = [];
        foreach ($categoriasAnterior as $c) $mapAnt[strtolower($c['nome'])] = (float)$c['valor'];
        $varCat = [];
        foreach ($categorias as $c) {
            $ant = $mapAnt[strtolower($c['nome'])] ?? 0;
            $v = $this->variacao((float)$c['valor'], $ant);
            $v['nome'] = $c['nome'];
            $varCat[] = $v;
        }
        // Ordena por maior variação absoluta em %
        usort($varCat, fn($a,$b)=>abs($b['percentual']) <=> abs($a['percentual']));

        // Maior aumento / maior redução
        $aumentos = array_values(array_filter($varCat, fn($v)=>$v['sinal']==='aumento' && $v['anterior']>0));
        $reducoes = array_values(array_filter($varCat, fn($v)=>$v['sinal']==='reducao'));
        usort($aumentos, fn($a,$b)=>abs($b['percentual']) <=> abs($a['percentual']));
        usort($reducoes, fn($a,$b)=>abs($b['percentual']) <=> abs($a['percentual']));

        // Variação do orçamento
        $orc = ['tem_anterior' => false];
        if (!empty($orcamento['limite'])) {
            $orc = [
                'utilizado' => $orcamento['utilizado'],
                'limite' => $orcamento['limite'],
                'percentual' => $orcamento['percentual_usado'],
                'status' => $orcamento['status'] ?? null,
                'observacao' => 'compare com limite orçado do mês anterior para avaliar evolução',
            ];
        }

        return [
            'receitas' => $varReceitas,
            'despesas' => $varDespesas,
            'saldo' => $varSaldo,
            'categorias' => $varCat,
            'maiores_aumentos' => array_slice($aumentos, 0, 3),
            'maiores_reducoes' => array_slice($reducoes, 0, 3),
            'orcamento' => $orc,
        ];
    }

    private function historicoMensal(int $userId, int $months): array
    {
        $db = $this->db;
        $raw = $db->prepare("
            SELECT EXTRACT(YEAR FROM data) as y, EXTRACT(MONTH FROM data) as m, tipo, SUM(valor) as total
            FROM transacoes WHERE usuario_id=? GROUP BY y,m,tipo ORDER BY y DESC, m DESC LIMIT ".((int)$months*2));
        $raw->execute([$userId]);
        $rows = $raw->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $k = sprintf('%04d-%02d', $r['y'], $r['m']);
            if (!isset($map[$k])) $map[$k] = ['mes'=>$k, 'receitas'=>0,'despesas'=>0];
            if ($r['tipo']==='receita') $map[$k]['receitas'] = round((float)$r['total'],2);
            else $map[$k]['despesas'] = round((float)$r['total'],2);
        }
        ksort($map);
        $out = array_values($map);
        foreach ($out as &$o) $o['saldo']=round($o['receitas']-$o['despesas'],2);
        return array_slice($out, -$months);
    }

    public function formatForPrompt(array $ctx): string
    {
        return json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
