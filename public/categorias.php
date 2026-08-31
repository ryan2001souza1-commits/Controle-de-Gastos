<?php
$pageTitle = 'Categorias';
$pageSubtitle = 'Organize suas receitas e despesas por categorias.';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));
$activeMenu = 'categorias';
$msgs = ['1'=>'Categoria adicionada!','created'=>'Categoria criada!','updated'=>'Categoria atualizada!','deleted'=>'Categoria excluída!'];
$errs = ['invalid_category'=>'Dados inválidos.','duplicate_category'=>'Já existe categoria com esse nome.','not_found'=>'Categoria não encontrada.','category_in_use'=>'Existem lançamentos vinculados.'];
$palette = ['#22c55e','#8b5cf6','#f59e0b','#ec4899','#3b82f6','#14b8a6','#ef4444','#a16207','#6366f1','#06b6d4'];
$activeTab = $_GET['tab'] ?? 'despesa';
$categories = $activeTab==='despesa'?($expenseCats??[]):($incomeCats??[]);
$allCount = count($expenseCats??[])+count($incomeCats??[]);
$activeCount = count(array_filter($categories, fn($c)=>($c['active']??1)==1));
$inactiveCount = count($categories)-$activeCount;
$totalSpent = array_sum(array_map(fn($c)=>(float)($c['tx_total']??0), $categories));
$maxCat = null; $maxVal=0; foreach($categories as $c){ $v=(float)($c['tx_total']??0); if($v>$maxVal){$maxVal=$v;$maxCat=$c;}}
$searchFilter = trim($_GET['search'] ?? '');
if($searchFilter!=='') $categories=array_values(array_filter($categories, fn($c)=>stripos($c['name'],$searchFilter)!==false));
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Categorias - Controle de Gastos</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>
<?php if(isset($_GET['success'])): ?><div class="alert alert-success" role="status"><?= render_icon('check',13) ?><span><?= htmlspecialchars($msgs[$_GET['success']]??'Operação realizada!') ?></span></div><?php endif; ?>
<?php if(isset($_GET['error'])):
    $errKey = $_GET['error'];
    $errMsg = $errs[$errKey] ?? null;
    $isLimit = is_string($errKey) && function_exists('str_starts_with') && str_starts_with($errKey, 'limit:');
    if ($isLimit) {
        $errMsg = urldecode(substr($errKey, 6));
    }
?><div class="alert alert-error" role="alert"><?= render_icon('info',13) ?><span><?= htmlspecialchars($errMsg ?? 'Erro.') ?></span></div><?php endif; ?>

<section class="metric-strip">
    <article class="metric-card"><div class="metric-card-icon" style="background:#ecfdf5;color:#059669"><?= render_icon('folder',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Total de categorias</div><div class="metric-card-value"><?= $allCount ?></div><div class="text-xs" style="color:#64748b">Despesas</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#f5f3ff;color:#7c3aed"><?= render_icon('check',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Categorias ativas</div><div class="metric-card-value"><?= $activeCount ?></div><div class="text-xs" style="color:#64748b">Ativas</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#fef2f2;color:#e11d48"><?= render_icon('heart',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Categorias inativas</div><div class="metric-card-value"><?= $inactiveCount ?></div><div class="text-xs" style="color:#64748b">Inativas</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#eff6ff;color:#2563eb"><?= render_icon('pie',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Maior gasto no mês</div><div class="metric-card-value" style="color:#059669">R$ <?= number_format($maxVal,2,',','.') ?></div><div class="text-xs" style="color:#64748b"><?= htmlspecialchars($maxCat['name']??'—') ?></div></div></article>
</section>

<div style="display:flex;gap:8px;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:0">
    <a href="?action=categorias&tab=despesa" style="padding:10px 18px;font-size:13px;font-weight:600;border-radius:8px 8px 0 0;border:1px solid <?= $activeTab==='despesa'?'#e2e8f0':'transparent' ?>;border-bottom:<?= $activeTab==='despesa'?'2px solid #10b981':'1px solid transparent' ?>;background:<?= $activeTab==='despesa'?'#fff':'transparent' ?>;color:<?= $activeTab==='despesa'?'#059669':'#64748b' ?>">Despesas</a>
    <a href="?action=categorias&tab=receita" style="padding:10px 18px;font-size:13px;font-weight:600;border-radius:8px 8px 0 0;border:1px solid <?= $activeTab==='receita'?'#e2e8f0':'transparent' ?>;border-bottom:<?= $activeTab==='receita'?'2px solid #10b981':'1px solid transparent' ?>;background:<?= $activeTab==='receita'?'#fff':'transparent' ?>;color:<?= $activeTab==='receita'?'#059669':'#64748b' ?>">Receitas</a>
</div>

<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px">
    <form method="GET" class="search-input" style="max-width:280px;flex:1"><input type="hidden" name="action" value="categorias"><input type="hidden" name="tab" value="<?= $activeTab ?>"><?= render_icon('search',14) ?><input type="text" name="search" placeholder="Buscar categoria..." value="<?= htmlspecialchars($searchFilter) ?>"></form>
    <button type="button" class="btn btn-primary" style="background:#059669;border-color:#059669" onclick="openNewCategory()"><?= render_icon('plus',13) ?> Nova categoria</button>
</div>

<section class="panel">
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Categoria ↕</th><th>Tipo</th><th>Cor</th><th class="th-numeric">Gasto no mês ↕</th><th>% do total ↕</th><th>Status</th><th class="th-actions">Ações</th></tr></thead>
        <tbody>
        <?php if(empty($categories)): ?><tr><td colspan="7" class="empty-cell">Nenhuma categoria.</td></tr>
        <?php else: foreach($categories as $c): $cid=(int)($c['id']??0); $nm=$c['name']??''; $tp=$c['type']??'despesa'; $tot=(float)($c['tx_total']??0); $pct=$totalSpent>0?round($tot/$totalSpent*100,1):0; $col=$c['color']??$palette[$cid%count($palette)]; $act=(int)($c['active']??1); $ico=$c['icon']??'tag'; $desc=$c['name']??''; ?>
            <tr>
                <td><div style="display:flex;gap:10px;align-items:center"><div class="cat-icon" style="background:<?= htmlspecialchars($col) ?>;width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:#fff"><?= category_icon_svg($ico,16) ?></div><div><div style="font-weight:600;color:#0f172a;font-size:13px"><?= htmlspecialchars($nm) ?></div><div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($desc) ?>...</div></div></div></td>
                <td><span style="background:#fef2f2;color:#dc2626;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600">Despesa</span></td>
                <td><span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#334155"><span style="width:12px;height:12px;border-radius:50%;background:<?= htmlspecialchars($col) ?>"></span> <?= strtoupper($col) ?></span></td>
                <td class="td-numeric" style="font-weight:600">R$ <?= number_format($tot,2,',','.') ?></td>
                <td><div style="display:flex;align-items:center;gap:8px"><span style="font-size:12px;font-weight:600;min-width:32px"><?= number_format($pct,1,',','.') ?>%</span><div class="progress-bar" style="width:100px;height:4px"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= htmlspecialchars($col) ?>"></div></div></div></td>
                <td><span class="badge <?= $act?'badge-success':'badge-neutral' ?>" style="font-size:11px"><?= $act?'Ativa':'Inativa' ?></span></td>
                <td><div class="row-actions"><button class="row-action-btn is-edit" onclick='openEditCategory(<?= json_encode(["id"=>$cid,"name"=>$nm,"type"=>$tp,"color"=>$col,"icon"=>$ico], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'><?= render_icon('edit',13) ?></button><form action="/index.php?action=delete_category" method="POST" style="display:inline" onsubmit="return confirm('Excluir?')"><input type="hidden" name="id" value="<?= $cid ?>"><button class="row-action-btn is-danger"><?= render_icon('trash',13) ?></button></form></div></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
    <div class="pagination"><div class="pagination-info">Mostrando 1 a <?= count($categories) ?> de <?= count($categories) ?> categorias</div><div class="pagination-controls"><button class="pagination-btn" disabled>‹</button><button class="pagination-btn is-active">1</button><button class="pagination-btn">›</button></div><div class="pagination-select"><select><option>10 por página</option></select></div></div>
</section>

<div class="modal-overlay" id="categoryModal" style="display:none"><div class="modal"><header class="modal-header"><div class="modal-title" id="modal-title">Nova categoria</div><button class="modal-close" onclick="closeCategoryModal()"><?= render_icon('x',16) ?></button></header><div class="modal-body"><form action="/index.php?action=store_category" method="POST" id="categoryForm"><input type="hidden" name="id" id="modalCatId"><div class="form-stack"><div class="form-group"><label>Nome</label><input type="text" name="name" id="modalCatName" required></div><div class="form-group"><label>Tipo</label><div class="select-wrap"><select name="type" id="modalCatType"><option value="despesa">Despesa</option><option value="receita">Receita</option></select></div></div><div class="form-row"><div class="form-group"><label>Cor</label><input type="color" name="cor" id="modalCatColor" value="#22c55e" style="width:44px;height:36px;border-radius:8px"></div><div class="form-group"><label>Ícone</label><div class="select-wrap"><select name="icone" id="modalCatIcon"><option value="tag">tag</option><option value="home">home</option><option value="car">car</option><option value="heart">heart</option><option value="shopping-bag">shopping</option><option value="book">book</option></select></div></div></div></div></form></div><footer class="modal-footer"><button class="btn btn-ghost" onclick="closeCategoryModal()">Cancelar</button><button type="submit" form="categoryForm" class="btn btn-primary" style="background:#059669">Salvar</button></footer></div></div>
<?php $extraScripts='<script>
function openNewCategory(){document.getElementById("modal-title").textContent="Nova categoria";document.getElementById("categoryForm").action="/index.php?action=store_category";document.getElementById("modalCatId").value="";document.getElementById("modalCatName").value="";document.getElementById("categoryModal").style.display="flex";document.body.style.overflow="hidden"}
function openEditCategory(d){document.getElementById("modal-title").textContent="Editar categoria";document.getElementById("categoryForm").action="/index.php?action=update_category";document.getElementById("modalCatId").value=d.id;document.getElementById("modalCatName").value=d.name;document.getElementById("modalCatType").value=d.type;document.getElementById("modalCatColor").value=d.color||"#22c55e";document.getElementById("modalCatIcon").value=d.icon||"tag";document.getElementById("categoryModal").style.display="flex";document.body.style.overflow="hidden"}
function closeCategoryModal(){document.getElementById("categoryModal").style.display="none";document.body.style.overflow=""}
document.getElementById("categoryModal").addEventListener("click",e=>{if(e.target===e.currentTarget)closeCategoryModal()});
document.addEventListener("keydown",e=>{if(e.key==="Escape")closeCategoryModal()});
</script>'; include __DIR__ . '/partials/layout_end.php'; ?>