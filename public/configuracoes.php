<?php
$activeMenu = 'configuracoes';
$showPeriodPicker = false;
$topbarActions = '';
if (!isset($user) || !$user) { $user = $userModel->findById($_SESSION['user_id']); }
$initials = strtoupper(substr($user->name ?? $userName ?? 'U', 0, 1) . (strpos($user->name ?? '', ' ') !== false ? substr(explode(' ', $user->name)[1] ?? '', 0, 1) : ''));
$initials = strtoupper(substr($user->name ?? 'U', 0, 1));
if (strpos($user->name ?? '', ' ') !== false) {
    $parts = explode(' ', trim($user->name));
    $initials = strtoupper(substr($parts[0],0,1) . substr(end($parts),0,1));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

<?php
$msgs = [
    'updated' => 'Alterações salvas com sucesso.',
    'password_updated' => 'Senha alterada com sucesso.',
];
$errs = [
    'invalid_data' => 'Preencha nome e e-mail corretamente.',
    'invalid_email' => 'E-mail em formato inválido.',
    'email_taken' => 'Este e-mail já está em uso por outra conta.',
    'invalid_phone' => 'Telefone inválido.',
    'invalid_date' => 'Data de nascimento inválida.',
    'invalid_income' => 'Renda mensal inválida.',
    'invalid_payday' => 'Dia de recebimento deve ser entre 1 e 31.',
    'weak_password' => 'A nova senha deve ter pelo menos 8 caracteres.',
    'password_mismatch' => 'A confirmação da nova senha não confere.',
    'wrong_password' => 'Senha atual incorreta.',
];
if (isset($_GET['success']) && isset($msgs[$_GET['success']])): ?>
    <div class="alert alert-success" role="status"><?= render_icon('check',13) ?><span><?= htmlspecialchars($msgs[$_GET['success']]) ?></span></div>
<?php endif; ?>
<?php if (isset($_GET['error']) && isset($errs[$_GET['error']])): ?>
    <div class="alert alert-error" role="alert"><?= render_icon('info',13) ?><span><?= htmlspecialchars($errs[$_GET['error']]) ?></span></div>
<?php endif; ?>

<!-- Header do perfil -->
<section class="panel" style="margin-bottom:var(--space-5)">
    <div class="panel-body" style="display:flex;gap:18px;align-items:center">
        <div style="width:64px;height:64px;border-radius:14px;background:var(--color-primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;flex-shrink:0"><?= htmlspecialchars($initials) ?></div>
        <div style="flex:1;min-width:0">
            <div style="font-size:18px;font-weight:700;color:var(--color-text-1);letter-spacing:-.02em"><?= htmlspecialchars($user->name) ?></div>
            <div style="font-size:13px;color:var(--color-text-2);margin-top:2px"><?= htmlspecialchars($user->email) ?> · Membro desde <?= $user->created_at ? date('d/m/Y', strtotime($user->created_at)) : '—' ?></div>
            <div style="font-size:12px;color:var(--color-text-3);margin-top:4px">Avatar com iniciais — foto personalizada em breve</div>
        </div>
        <div style="display:none;gap:8px" class="hide-mobile">
            <span class="badge badge-success"><span class="badge-dot"></span>Conta ativa</span>
        </div>
    </div>
</section>

<div style="display:grid;grid-template-columns:1.7fr .9fr;gap:var(--space-5);align-items:start">
    <!-- Coluna principal -->
    <div style="display:flex;flex-direction:column;gap:var(--space-5)">
        <!-- Informações pessoais -->
        <section class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Informações pessoais</div>
                    <div class="panel-subtitle">Dados básicos do seu cadastro</div>
                </div>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=update_profile" method="POST" id="profileForm" novalidate>
                    <div class="form-stack">
                        <div class="form-row">
                            <div class="form-group" style="flex:1.4">
                                <label for="nome">Nome completo *</label>
                                <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($user->name ?? '') ?>" required maxlength="100" placeholder="Seu nome completo">
                            </div>
                            <div class="form-group" style="flex:1">
                                <label for="email">E-mail *</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($user->email ?? '') ?>" required maxlength="100" placeholder="seu@email.com">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="telefone">Telefone</label>
                                <input type="text" id="telefone" name="telefone" value="<?= htmlspecialchars($user->telefone ?? '') ?>" maxlength="20" placeholder="(11) 99999-9999">
                                <span class="form-hint">Opcional — usado para contato e recuperação</span>
                            </div>
                            <div class="form-group">
                                <label for="data_nascimento">Data de nascimento</label>
                                <input type="date" id="data_nascimento" name="data_nascimento" value="<?= htmlspecialchars($user->data_nascimento ?? '') ?>">
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--color-border);margin:4px 0">

                        <div style="font-size:12px;font-weight:700;color:var(--color-text-1);letter-spacing:-.01em;margin-top:4px">Informações financeiras</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="renda_mensal">Renda mensal (R$)</label>
                                <input type="number" id="renda_mensal" name="renda_mensal" step="0.01" min="0" value="<?= htmlspecialchars($user->renda_mensal !== null ? number_format((float)$user->renda_mensal,2,'.','') : '') ?>" placeholder="0,00">
                            </div>
                            <div class="form-group">
                                <label for="dia_recebimento">Dia de recebimento</label>
                                <input type="number" id="dia_recebimento" name="dia_recebimento" min="1" max="31" value="<?= htmlspecialchars($user->dia_recebimento ?? '') ?>" placeholder="5">
                                <span class="form-hint">1 a 31</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="objetivo">Objetivo financeiro principal</label>
                            <div class="select-wrap">
                                <select id="objetivo" name="objetivo">
                                    <option value="" <?= ($user->objetivo ?? '') === '' ? 'selected' : '' ?>>Selecione (opcional)</option>
                                    <option value="economizar" <?= ($user->objetivo ?? '') === 'economizar' ? 'selected' : '' ?>>Economizar</option>
                                    <option value="organizar" <?= ($user->objetivo ?? '') === 'organizar' ? 'selected' : '' ?>>Organizar finanças</option>
                                    <option value="investir" <?= ($user->objetivo ?? '') === 'investir' ? 'selected' : '' ?>>Investir</option>
                                    <option value="quitar_dividas" <?= ($user->objetivo ?? '') === 'quitar_dividas' ? 'selected' : '' ?>>Quitar dívidas</option>
                                </select>
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--color-border);margin:4px 0">

                        <div style="font-size:12px;font-weight:700;color:var(--color-text-1);letter-spacing:-.01em;margin-top:4px">Preferências</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="moeda">Moeda</label>
                                <div class="select-wrap">
                                    <select id="moeda" name="moeda">
                                        <option value="BRL" <?= ($user->moeda ?? 'BRL') === 'BRL' ? 'selected' : '' ?>>BRL — Real (R$)</option>
                                        <option value="USD" <?= ($user->moeda ?? '') === 'USD' ? 'selected' : '' ?>>USD — Dólar ($)</option>
                                        <option value="EUR" <?= ($user->moeda ?? '') === 'EUR' ? 'selected' : '' ?>>EUR — Euro (€)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="notificacoes" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:22px">
                                    <input type="checkbox" id="notificacoes" name="notificacoes" value="1" <?= ($user->notificacoes ?? 1) ? 'checked' : '' ?> style="width:auto">
                                    <span style="font-size:13px;font-weight:500;color:var(--color-text-2)">Receber notificações</span>
                                </label>
                                <span class="form-hint">Resumo e alertas por e-mail</span>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top:8px">
                            <button type="submit" class="btn btn-primary">Salvar alterações</button>
                            <a href="/index.php?action=configuracoes" class="btn btn-ghost">Descartar</a>
                        </div>
                    </div>
                <?= csrf_field() ?>\n                </form>
            </div>
        </section>

        <!-- Segurança -->
        <section class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Segurança</div>
                    <div class="panel-subtitle">Altere sua senha com segurança</div>
                </div>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=update_password" method="POST" id="passwordForm" novalidate>
                    <div class="form-stack">
                        <?php if ($user->password_hash): ?>
                        <div class="form-group">
                            <label for="current_password">Senha atual</label>
                            <input type="password" id="current_password" name="current_password" placeholder="Sua senha atual" autocomplete="current-password">
                        </div>
                        <?php else: ?>
                        <div class="inline-info" style="margin-bottom:0">
                            <?= render_icon('info',14) ?><span>Sua conta foi criada via Google e ainda não possui senha. Defina uma nova senha abaixo.</span>
                        </div>
                        <?php endif; ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="new_password">Nova senha</label>
                                <input type="password" id="new_password" name="new_password" placeholder="Mínimo 8 caracteres" autocomplete="new-password" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirmar nova senha</label>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Repita a nova senha" autocomplete="new-password" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Alterar senha</button>
                        </div>
                    </div>
                <?= csrf_field() ?>\n                </form>
            </div>
        </section>
    </div>

    <!-- Coluna lateral -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4)">
        <section class="panel">
            <header class="panel-header"><div class="panel-title" style="font-size:14px">Resumo rápido</div></header>
            <div class="panel-body" style="display:flex;flex-direction:column;gap:12px">
                <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:13px;color:var(--color-text-2)">Conta</span><span class="badge badge-success" style="font-size:11px">Ativa</span></div>
                <div style="display:flex;justify-content:space-between"><span style="font-size:12px;color:var(--color-text-3)">E-mail</span><span style="font-size:12px;font-weight:600;color:var(--color-text-1);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($user->email) ?></span></div>
                <div style="display:flex;justify-content:space-between"><span style="font-size:12px;color:var(--color-text-3)">Moeda</span><span style="font-size:12px;font-weight:600"><?= htmlspecialchars($user->moeda ?? 'BRL') ?></span></div>
                <div style="display:flex;justify-content:space-between"><span style="font-size:12px;color:var(--color-text-3)">Notificações</span><span style="font-size:12px;font-weight:600"><?= ($user->notificacoes ?? 1) ? 'Ativas' : 'Inativas' ?></span></div>
                <hr style="border:none;border-top:1px solid var(--color-border);margin:4px 0">
                <a href="/index.php?action=logout" class="btn btn-ghost btn-sm" style="width:100%">Sair da conta</a>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body" style="font-size:12px;color:var(--color-text-2);line-height:1.6">
                <div style="font-weight:700;color:var(--color-text-1);margin-bottom:6px">Dica</div>
                Mantenha seu e-mail atualizado para recuperar o acesso. Sua renda e objetivo ajudam a personalizar metas e orçamentos.
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_end.php'; ?>
