<?php $pageTitle = 'Cadastro - Controle de Gastos'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <h1>Controle de Gastos</h1>
            <h2>Cadastro</h2>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form action="/index.php?action=register" method="POST">
                <div class="form-group">
                    <label for="name">Nome</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Senha (mín. 8 caracteres)</label>
                    <input type="password" id="password" name="password" required minlength="8">
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirmar senha</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary">Cadastrar</button>
            </form>

            <p class="auth-link">
                Já tem conta? <a href="/login.php">Faça login</a>
            </p>
        </div>
    </div>
</body>
</html>
