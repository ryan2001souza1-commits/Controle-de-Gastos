<?php $pageTitle = 'Login - Controle de Gastos'; ?>
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
            <h2>Login</h2>

            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">Cadastro realizado! Faça login.</div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form action="/index.php?action=login" method="POST">
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">Entrar</button>
            </form>

            <p class="auth-link">
                Não tem conta? <a href="/index.php?action=register">Cadastre-se</a>
            </p>
        </div>
    </div>
</body>
</html>
