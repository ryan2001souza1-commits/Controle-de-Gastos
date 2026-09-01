<?php $pageTitle = 'Política de Privacidade - Controle de Gastos'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/auth.css">
    <link rel="stylesheet" href="/css/legal.css">
</head>
<body class="auth-body">
    <div class="legal-wrapper">
        <main class="legal-main">
            <div class="legal-card">
                <a href="/index.php?action=register" class="legal-back">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    <span>Voltar para o cadastro</span>
                </a>

                <h1 class="legal-title">Política de Privacidade</h1>
                <p class="legal-updated">Última atualização: <?= date('d/m/Y') ?></p>

                <section class="legal-section">
                    <h2>1. Dados que Coletamos</h2>
                    <p>Para fornecer o serviço, coletamos:</p>
                    <ul>
                        <li><strong>Dados de cadastro:</strong> nome, e-mail, senha (armazenada com hash bcrypt).</li>
                        <li><strong>Dados financeiros:</strong> lançamentos, categorias, orçamentos e metas que você registra.</li>
                        <li><strong>Dados técnicos:</strong> endereço IP, agente do navegador, timestamps de acesso para segurança e prevenção de abuso.</li>
                        <li><strong>Cookies essenciais:</strong> necessários para manter sua sessão autenticada de forma segura.</li>
                    </ul>
                </section>

                <section class="legal-section">
                    <h2>2. Como Usamos seus Dados</h2>
                    <ul>
                        <li>Operar e melhorar a plataforma.</li>
                        <li>Autenticar acessos e prevenir fraudes.</li>
                        <li>Enviar e-mails transacionais (recuperação de senha, notificações da conta).</li>
                        <li>Personalizar o assistente IA com o contexto financeiro do seu próprio usuário.</li>
                    </ul>
                    <p><strong>Não vendemos seus dados</strong> e não compartilhamos com terceiros para fins de marketing.</p>
                </section>

                <section class="legal-section">
                    <h2>3. Armazenamento e Segurança</h2>
                    <p>Os dados são armazenados em banco PostgreSQL gerenciado (Neon) com conexão criptografada (SSL). Senhas são protegidas com <code>password_hash</code> (bcrypt) e nunca são expostas em logs ou respostas de API. Aplicamos cabeçalhos de segurança HTTP, CSRF, rate limiting e Content Security Policy.</p>
                </section>

                <section class="legal-section">
                    <h2>4. Assistente de IA</h2>
                    <p>Quando você faz uma pergunta ao assistente, enviamos ao provedor de IA (OpenRouter) um <strong>resumo agregado</strong> dos seus dados financeiros (saldos, totais por categoria, metas) e a sua pergunta. <strong>Não enviamos</strong> sua senha, e-mail ou identificadores pessoais. O histórico fica apenas na sua sessão.</p>
                </section>

                <section class="legal-section">
                    <h2>5. Seus Direitos (LGPD)</h2>
                    <p>Você pode a qualquer momento:</p>
                    <ul>
                        <li>Solicitar acesso aos seus dados.</li>
                        <li>Corrigir dados incorretos.</li>
                        <li>Exportar seus dados.</li>
                        <li>Solicitar exclusão da conta e dos dados associados.</li>
                    </ul>
                </section>

                <section class="legal-section">
                    <h2>6. Retenção</h2>
                    <p>Mantemos os dados enquanto sua conta estiver ativa. Ao excluir a conta, os dados são removidos em até 90 dias, exceto quando legislação exigir prazo maior de retenção.</p>
                </section>

                <section class="legal-section">
                    <h2>7. Contato</h2>
                    <p>Para exercer seus direitos ou esclarecer dúvidas sobre privacidade, escreva para <a href="mailto:contato@controlegastos.com.br">contato@controlegastos.com.br</a>.</p>
                </section>

                <p class="legal-footer">&copy; <?= date('Y') ?> Controle de Gastos · Desenvolvido por Ryan Souza</p>
            </div>
        </main>
    </div>
</body>
</html>
