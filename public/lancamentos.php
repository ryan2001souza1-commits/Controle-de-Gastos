<?php
$pageTitle = 'Lançamentos';
$pageSubtitle = 'Gerencie todas as suas receitas e despesas.';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'lancamentos';
$periodPickerAction = 'lancamentos';

$msgs = [
    '1' => 'Lançamento adicionado com sucesso!',
    'updated' => 'Lançamento atualizado!',
    'deleted' => 'Lançamento excluído!',
];
$errs = [
    'invalid_data'    => 'Dados inválidos. Preencha corretamente.',
    'not_found'       => 'Lançamento não encontrado.',
    'update_failed'   => 'Erro ao atualizar.',
    'duplicate_category' => 'Já existe uma categoria com esse nome.',
    'invalid_category'   => 'Categoria inválida.',
];

$totalIncomes  = $totalIncomes  ?? 0;
$totalExpenses = $totalExpenses ?? 0;
$balance       = $balance       ?? 0;
$rows          = $rows          ?? [];
$economyPct    = $totalIncomes > 0 ? round((($totalIncomes - $totalExpenses) / $totalIncomes) * 100, 1) : 0.0;
$txCount       = count($rows);

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');
$pagePeriodFrom = date('d/m/Y', strtotime($startDate));
$pagePeriodTo   = date('d/m/Y', strtotime($endDate));
$filterType    = $_GET['type'] ?? '';
$categoryId    = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$search        = $_GET['search'] ?? '';
$pageNum       = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 10;

$palette = ['#10b981','#3b82f6','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#ef4444','#22c55e','#0ea5e9','#a855f7'];
$catLookup = [];
foreach (($expenseCategories ?? []) as $i => $c) {
    $catLookup[(int)$c['id']] = ['color' => $c['cor'] ?? $palette[$i % count($palette)], 'icon' => $c['icone'] ?? 'tag', 'name' => $c['name']];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançamentos - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success" role="status"><?= render_icon('check',13) ?><span><?= htmlspecialchars($msgs[$_GET['success']] ?? 'Operação realizada com sucesso!') ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])):
        $errKey = $_GET['error'];
        $errMsg = $errs[$errKey] ?? null;
        $isLimit = is_string($errKey) && str_starts_with($errKey, 'limit:');
        if ($isLimit) {
            $errMsg = urldecode(substr($errKey, 6));
        }
    ?>
        <div class="alert alert-error" role="alert"><?= render_icon('info',13) ?><span><?= htmlspecialchars($errMsg ?? 'Ocorreu um erro.') ?></span></div>
    <?php endif; ?>

    <!-- 4 metric cards — fiel à ref -->
    <section class="metric-strip">
        <article class="metric-card">
            <div class="metric-card-icon" style="background:#ecfdf5;color:#10b981"><?= render_icon('arrow-up',18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Receitas</div>
                <div class="metric-card-value" style="color:#059669">R$ <?= number_format($totalIncomes,2,',','.') ?></div>
                <div class="text-xs" style="color:#64748b;margin-top:2px">Total no período</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon" style="background:#fef2f2;color:#ef4444"><?= render_icon('arrow-down',18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Despesas</div>
                <div class="metric-card-value" style="color:#dc2626">R$ <?= number_format($totalExpenses,2,',','.') ?></div>
                <div class="text-xs" style="color:#64748b;margin-top:2px">Total no período</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon" style="background:#eff6ff;color:#3b82f6"><?= render_icon('wallet',18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Saldo</div>
                <div class="metric-card-value" style="color:#059669">R$ <?= number_format($balance,2,',','.') ?></div>
                <div class="text-xs" style="color:#64748b;margin-top:2px">Total no período</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon" style="background:#f5f3ff;color:#7c3aed"><?= render_icon('pie',18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Transações</div>
                <div class="metric-card-value"><?= $txCount ?></div>
                <div class="text-xs" style="color:#64748b;margin-top:2px">No período</div>
            </div>
        </article>
    </section>

    <!-- Novo lançamento + filtros -->
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px">
        <button type="button" class="btn btn-primary" style="background:#059669;border-color:#059669" onclick="openTxModal()"><?php echo render_icon('plus',14) ?> Novo lançamento <span style="opacity:.8;margin-left:4px">▾</span></button>
        <form method="GET" action="/index.php" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1">
            <input type="hidden" name="action" value="lancamentos">
            <div class="select-wrap" style="min-width:140px"><select name="type" onchange="this.form.submit()"><option value="">Todos os tipos</option><option value="receita" <?= $filterType==='receita'?'selected':'' ?>>Receitas</option><option value="despesa" <?= $filterType==='despesa'?'selected':'' ?>>Despesas</option></select></div>
            <div class="select-wrap" style="min-width:160px"><select name="category_id" onchange="this.form.submit()"><option value="0">Todas as categorias</option><?php foreach(($expenseCategories??[]) as $cat): ?><option value="<?= (int)$cat['id'] ?>" <?= $categoryId===(int)$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?></select></div>
            <div class="select-wrap" style="min-width:160px"><select><option>Forma de pagamento</option></select></div>
            <div class="search-input" style="flex:1;min-width:180px"><?= render_icon('search',14) ?><input type="text" name="search" placeholder="Buscar lançamento..." value="<?= htmlspecialchars($search) ?>"></div>
            <button type="button" class="btn btn-ghost btn-sm"><?= render_icon('filter',12) ?> Filtros</button>
        </form>
    </div>

    <!-- Tabela -->
    <section class="panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Data <span style="opacity:.5">↕</span></th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Tipo</th>
                        <th class="th-numeric">Valor</th>
                        <th>Forma de pagamento</th>
                        <th class="th-actions">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($rows)): ?>
                        <tr><td colspan="7" class="empty-cell">Nenhum lançamento encontrado.</td></tr>
                    <?php else: foreach(array_slice($rows, ($pageNum-1)*$perPage, $perPage) as $t):
                        $tp=$t['type']??''; $isDesp=$tp==='despesa'; $cid=(int)($t['category_id']??0); $ci=$catLookup[$cid]??null; $cc=$ci['color']??'#e2e8f0'; $cn=$t['category_name']??'—'; $cicon=$ci['icon']??'tag';
                    ?>
                    <tr>
                        <td class="td-mono" style="white-space:nowrap;color:#334155;font-size:13px"><?= isset($t['date'])?date('d/m/Y',strtotime($t['date'])):'—' ?></td>
                        <td>
                            <div style="display:flex;gap:10px;align-items:center">
                                <div class="cat-icon" style="background:<?= htmlspecialchars($cc) ?>;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:#fff"><?= category_icon_svg($cicon,14) ?></div>
                                <div>
                                    <div style="font-weight:600;color:#0f172a;font-size:13px"><?= htmlspecialchars($t['description']??'') ?></div>
                                    <div style="font-size:11px;color:#94a3b8">Sem descrição</div>
                                </div>
                            </div>
                        </td>
                        <td><span style="background:<?= $isDesp?'#fef3f7':'#f0fdf4' ?>;color:<?= $isDesp?'#9d174d':'#065f46' ?>;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:600"><?= htmlspecialchars($cn) ?></span></td>
                        <td><span class="badge <?= $isDesp?'badge-danger':'badge-success' ?>" style="font-size:11px"><?= $isDesp?render_icon('arrow-down',10).' Despesa':render_icon('arrow-up',10).' Receita' ?></span></td>
                        <td class="td-numeric" style="font-weight:700;color:<?= $isDesp?'#dc2626':'#059669' ?>"><?= $isDesp?'- ':'' ?>R$ <?= number_format((float)($t['amount']??0),2,',','.') ?></td>
                        <td style="font-size:12px;color:#334155;display:flex;align-items:center;gap:6px"><?= render_icon('credit-card',14) ?> PIX</td>
                        <td><div class="row-actions"><a href="/index.php?action=edit&id=<?= (int)($t['id']??0) ?>&type=<?= htmlspecialchars($tp) ?>" class="row-action-btn is-edit"><?= render_icon('edit',13) ?></a><form action="/index.php?action=delete" method="POST" style="display:inline"><input type="hidden" name="id" value="<?= (int)($t['id']??0) ?>"><input type="hidden" name="type" value="<?= htmlspecialchars($tp) ?>"><button class="row-action-btn is-danger"><?= render_icon('trash',13) ?></button></form></div></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <div class="pagination-info">Mostrando <?= min($txCount, $perPage) ?> de <?= $txCount ?> lançamentos</div>
            <div class="pagination-controls"><button class="pagination-btn" disabled>‹</button><button class="pagination-btn is-active">1</button><button class="pagination-btn">2</button><button class="pagination-btn">3</button><button class="pagination-btn">›</button></div>
            <div class="pagination-select"><select><option>10 por página</option></select></div>
        </div>
    </section>

    <!-- Modal Novo Lançamento -->
    <div class="modal-overlay" id="txModal" style="display:none">
        <div class="modal" style="max-width:480px">
            <header class="modal-header"><div class="modal-title">Novo lançamento</div><button type="button" class="modal-close" onclick="closeTxModal()"><?= render_icon('x',16) ?></button></header>
            <div class="modal-body">
                <form id="txForm" action="/index.php?action=store" method="POST">
                    <div class="form-stack">
                        <div class="form-group"><label>Descrição</label><input type="text" name="description" required placeholder="Ex: Salário, Aluguel"></div>
                        <div class="form-row"><div class="form-group"><label>Valor (R$)</label><input type="number" name="amount" step="0.01" min="0.01" required placeholder="0,00"></div><div class="form-group"><label>Data</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div></div>
                        <div class="form-group"><label>Tipo</label><div class="select-wrap"><select name="type" id="txType" required><option value="despesa">Despesa</option><option value="receita">Receita</option></select></div></div>
                        <div class="form-group" id="txCatWrap"><label>Categoria</label><div class="select-wrap"><select name="category_id" id="txCategory"><option value="">Sem categoria</option><?php foreach(($expenseCategories??[]) as $c): ?><option value="<?= (int)$c['id'] ?>" data-type="despesa"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?><?php foreach(($incomeCategories??[]) as $c): ?><option value="<?= (int)$c['id'] ?>" data-type="receita"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></div></div>
                    </div>
                </form>
            </div>
            <footer class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeTxModal()">Cancelar</button><button type="submit" form="txForm" class="btn btn-primary" style="background:#059669">Salvar lançamento</button></footer>
        </div>
    </div>
    <script>
    function openTxModal(){document.getElementById('txModal').style.display='flex';document.body.style.overflow='hidden'}
    function closeTxModal(){document.getElementById('txModal').style.display='none';document.body.style.overflow=''}
    document.getElementById('txModal').addEventListener('click',e=>{if(e.target===e.currentTarget)closeTxModal()});
    document.addEventListener('keydown',e=>{if(e.key==='Escape')closeTxModal()});
    (function(){
        const typeSel=document.getElementById('txType'), catSel=document.getElementById('txCategory');
        if(!typeSel||!catSel) return;
        const allOpts=Array.from(catSel.options);
        function filterCats(){
            const t=typeSel.value; catSel.innerHTML='';
            const empty=document.createElement('option'); empty.value=''; empty.textContent='Sem categoria'; catSel.appendChild(empty);
            allOpts.forEach(o=>{if(o.value!==''&&o.dataset.type===t) catSel.appendChild(o.cloneNode(true))});
        }
        typeSel.addEventListener('change',filterCats); filterCats();
    })();
    </script>

<?php include __DIR__ . '/partials/layout_end.php'; ?>