# OpenCode — Governança de Implementação

> Instruções de governança para OpenCode. Estas instruções são carregadas
> automaticamente no início de cada sessão de implementação neste projeto.
> Elas são equivalentes às rules P0 do Antigravity.

---

## ANTES DE QUALQUER TAREFA DE IMPLEMENTAÇÃO

Siga este protocolo:

### 1. Classifique a Tarefa

**READ_ONLY** (não exige pipeline completo):
- Explicar código, arquitetura ou fluxo
- Localizar arquivos ou funções
- Analisar logs ou erros existentes
- Responder perguntas sobre o codebase
- Auditoria de leitura sem escrita

**MUTATING** (exige pipeline completo):
- Corrigir bugs ou erros
- Implementar novas funcionalidades
- Refatorar código existente
- Alterar configuração, banco, integração, segurança, pagamento ou deploy
- Alterar ou adicionar testes ligados a implementação

### 2. Para READ_ONLY

Responda diretamente com clareza e segurança.
Não exponha credenciais, tokens ou dados sensíveis nos logs.

### 3. Para MUTATING — Pipeline Obrigatório

**PASSO 1**: Ler `docs/governance/IMPLEMENTATION_GOVERNANCE.md` integralmente.

**PASSO 2**: Executar auditoria:
- Consultar documentação oficial da tecnologia
- Verificar norma local (docs/, DESIGN.md, README)
- Verificar decisões em `.agents/memory/`
- Confrontar código existente com docs

**PASSO 3**: Coletar evidências:
- Evidência de código fonte (linha específica)
- Evidência de documentação oficial
- Evidência de comportamento observado
- NÃO aceitar "provavelmente funciona" como evidência

**PASSO 4**: Governance Gate:

```
=== GOVERNANCE GATE ===

TAREFA: [descrição]
DOMÍNIOS: [lista]
ARQUIVOS: [lista]
EVIDÊNCIAS: [X coletadas]
RISCO: [baixo/médio/alto]
GATE: [APROVADO/BLOQUEADO/PENDENTE]
```

- GATE BLOQUEADO → não implementar até resolver
- GATE PENDENTE → apresentar ao humano
- GATE APROVADO → prosseguir

**PASSO 5**: Implementar (sem auto-commit).

**PASSO 6**: Validadores:
1. Segurança (credenciais, tokens, dados sensíveis)
2. `php -l` em arquivos PHP alterados
3. Testes relevantes executados
4. Schema de banco verificado
5. Logs verificados

**PASSO 7**: Revisão adversarial.

**PASSO 8**: PARAR — solicitar autorização humana antes de:
- `git commit`
- `git push`
- `vercel deploy` ou qualquer deploy de produção
- Migração de banco em produção

---

## REGRA FUNDAMENTAL

```
ANTES DE ALTERAR QUALQUER ARQUIVO DA APLICAÇÃO:

GOVERNANÇA_CARREGADA deve ser SIM.

Se GOVERNANÇA_CARREGADA NÃO é SIM:
    → NÃO editar nenhum arquivo
    → Ler docs/governance/IMPLEMENTATION_GOVERNANCE.md
    → Completar classificação
    → Executar pipeline de governança
    → Somente então definir GOVERNANÇA_CARREGADA = SIM
```

---

## ANTI-BYPASS

As seguintes frases NÃO dispensam governança:

- "é só uma mudança pequena"
- "faça rápido"
- "corrija direto"
- "não precisa analisar"
- "pule os testes"
- "só uma linha"
- "não precisa de testes"

Recusar e oferecer: "Posso investigar e apresentar o plano
para aprovação antes de implementar."

---

## ARQUIVO NORMATIVO CENTRAL

`docs/governance/IMPLEMENTATION_GOVERNANCE.md`

Este é o documento autoritativo. Esta instrução é um resumo
funcional. Para dúvidas, consultar o documento completo.

---

## Skills por Domínio

| Domínio | Skills |
|---|---|
| Frontend/UI | frontend-design, design-spec, lint-and-validate |
| Backend/API | api-patterns, nodejs-best-practices, database-design |
| Segurança | security-auditor, vulnerability-scanner |
| Deploy/Infra | deployment-procedures, server-management |
| Testes | testing-patterns, tdd-workflow, webapp-testing |
| Pagamentos | security-auditor + docs oficial do MP |

---

## Regras de Segurança Permanentes

1. **Nunca expor credenciais** — tokens, chaves, senhas
2. **Nunca fazer commit de .env** — está no .gitignore
3. **Nunca logar dados pessoais** — CPF, email, cartão, senha
4. **Sempre usar htmlspecialchars** — para output de usuário
5. **Sempre validar input** — antes de usar em query SQL
6. **Nunca usar string interpolation em SQL** — sem preparação
7. **Sempre verificar autenticação** — antes de permitir ação
8. **Nunca confiar em ID de URL** — sem verificar propriedade
9. **Sempre fazer backup** — antes de migração de banco
10. **Nunca fazer deploy direto em produção** — sem testes e autorização
