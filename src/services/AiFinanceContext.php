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

        // Despesas por categoria (top 5) no mês
        $categorias = $this->categoriasTop($userId, $start, $end, 5);

        // Orçamento do mês
        $orcamento = $this->orcamentoResumo($userId, $year, $month);

        // Metas (máx 3)
        $metas = $this->metasResumo($userId, 3);

        // Histórico mensal (últimos 6 meses)
        $historico = $this->historicoMensal($userId, 6);

        return [
            'periodo' => $label,
            'periodo_extenso' => $now->format('F \d\e Y'),
            'receitas' => round($receitas, 2),
            'despesas' => round($despesas, 2),
            'saldo' => $saldo,
            'orcamento' => $orcamento,
            'categorias' => $categorias,
            'metas' => $metas,
            'historico_mensal' => $historico,
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
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(valor_limite),0) as limite
            FROM orcamentos WHERE usuario_id=? AND ano=? AND mes=?
        ");
        $stmt->execute([$userId, $year, $month]);
        $limite = (float)$stmt->fetchColumn();

        // gasto real nas categorias orçadas ou total despesa do mês se sem orçamento categorizado
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        $gasto = $this->sumTransacoes($userId, 'despesa', $start, $end);

        $disponivel = round($limite > 0 ? $limite - $gasto : 0, 2);
        $pct = $limite > 0 ? round(min(100, ($gasto / $limite) * 100), 1) : 0;

        return [
            'limite' => round($limite, 2),
            'utilizado' => round($gasto, 2),
            'disponivel' => $disponivel,
            'percentual_usado' => $pct,
        ];
    }

    private function metasResumo(int $userId, int $limit): array
    {
        $stmt = $this->db->prepare("SELECT nome, valor_objetivo as alvo, valor_acumulado as atual, data_limite as prazo FROM metas WHERE usuario_id=? ORDER BY data_limite ASC NULLS LAST, nome ASC LIMIT $limit");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $alvo = (float)$r['alvo'];
            $atual = (float)$r['atual'];
            $pct = $alvo > 0 ? round(min(100, ($atual / $alvo) * 100), 1) : 0;
            $out[] = [
                'nome' => $r['nome'],
                'alvo' => round($alvo, 2),
                'atual' => round($atual, 2),
                'percentual' => $pct,
                'prazo' => $r['prazo'] ? date('d/m/Y', strtotime($r['prazo'])) : null,
            ];
        }
        return $out;
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
