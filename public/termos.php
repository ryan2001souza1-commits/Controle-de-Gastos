<?php $pageTitle = 'Termos de Uso - Controle de Gastos'; ?>
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

                <h1 class="legal-title">Termos de Uso</h1>
                <p class="legal-updated">Última atualização: <?= date('d/m/Y') ?></p>

                <section class="legal-section">
                    <h2>1. Aceitação dos Termos</h2>
                    <p>Ao criar uma conta e utilizar a plataforma <strong>Controle de Gastos</strong>, você concorda com estes Termos de Uso e com a nossa <a href="/index.php?action=privacy">Política de Privacidade</a>. Se você não concordar com qualquer disposição, não utilize o serviço.</p>
                </section>

                <section class="legal-section">
                    <h2>2. Descrição do Serviço</h2>
                    <p>O Controle de Gastos é uma plataforma pessoal de gestão financeira que permite ao usuário registrar receitas, despesas, categorias, orçamentos e metas, além de gerar relatórios e utilizar um assistente com inteligência artificial para análise dos dados.</p>
                </section>

                <section class="legal-section">
                    <h2>3. Cadastro e Responsabilidades</h2>
                    <ul>
                        <li>Você deve fornecer informações verdadeiras no momento do cadastro.</li>
                        <li>Você é responsável por manter a confidencialidade da sua senha.</li>
                        <li>Você é o único responsável por toda atividade realizada na sua conta.</li>
                        <li>Notifique-nos imediatamente em caso de uso não autorizado.</li>
                    </ul>
                </section>

                <section class="legal-section">
                    <h2>4. Uso Adequado</h2>
                    <p>É proibido utilizar a plataforma para fins ilegais, tentar acessar áreas restritas sem autorização, realizar engenharia reversa do código, ou enviar conteúdo malicioso. Reservamo-nos o direito de suspender contas que violem estas regras.</p>
                </section>

                <section class="legal-section">
                    <h2>5. Planos e Pagamentos</h2>
                    <p>O plano gratuito possui limitações de uso claramente informadas na interface. Planos pagos, quando disponíveis, seguem as condições de cobrança exibidas no momento da contratação. Valores podem ser alterados mediante aviso prévio.</p>
                </section>

                <section class="legal-section">
                    <h2>6. Limitação de Responsabilidade</h2>
                    <p>O Controle de Gastos é uma ferramenta de apoio à organização financeira pessoal. <strong>Não somos uma instituição financeira</strong> e não prestamos aconselhamento financeiro profissional. O assistente IA fornece análises automatizadas que devem ser utilizadas como referência, não como decisão final.</p>
                </section>

                <section class="legal-section">
                    <h2>7. Alterações destes Termos</h2>
                    <p>Estes Termos podem ser atualizados periodicamente. Alterações relevantes serão comunicadas por meio da plataforma. O uso continuado após as alterações indica concordância com a nova versão.</p>
                </section>

                <section class="legal-section">
                    <h2>8. Contato</h2>
                    <p>Em caso de dúvidas sobre estes Termos, entre em contato pelo e-mail <a href="mailto:contato@controlegastos.com.br">contato@controlegastos.com.br</a>.</p>
                </section>

                <p class="legal-footer">&copy; <?= date('Y') ?> Controle de Gastos · Desenvolvido por Ryan Souza</p>
            </div>
        </main>
    </div>
</body>
</html>
