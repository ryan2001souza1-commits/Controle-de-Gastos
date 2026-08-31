(() => {
  const form    = document.getElementById('aiForm');
  const input   = document.getElementById('aiInput');
  const sendBtn = document.getElementById('aiSend');
  const msgs    = document.getElementById('aiMessages');
  const empty   = document.getElementById('aiEmpty');
  const typing  = document.getElementById('aiTyping');
  const counter = document.getElementById('aiCount');
  const remain  = document.getElementById('aiRemaining');
  const status  = document.getElementById('aiStatus');
  const clearBtn = document.getElementById('aiClear');
  if (!form || !input) return;

  let history = [];
  let busy = false;
  let lastError = false;

  // ── Helpers ──────────────────────────────────────────────────────
  const scrollToBottom = (force = false) => {
    if (!force && msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight > 120) return;
    msgs.scrollTo({ top: msgs.scrollHeight, behavior: 'smooth' });
  };

  const escapeHtml = (s) =>
    s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  const formatReply = (text) => {
    let h = escapeHtml(text);
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/^[\-\•]\s(.+)$/gm, '<li>$1</li>');
    h = h.replace(/(<li>.*<\/li>)/gs, (m) => `<ul>${m}</ul>`);
    h = h.replace(/\n/g, '<br>');
    return h;
  };

  const userIcon = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`;
  const sendIcon = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2 15 22 11 13 2 9l20-7z"/></svg>`;
  const retryIcon = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>`;
  const spinnerIcon = `<svg class="ai-spin-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>`;

  // ── Message rendering ─────────────────────────────────────────
  const addMessage = (role, text, meta = '') => {
    if (empty) empty.style.display = 'none';
    const row = document.createElement('div');
    row.className = `ai-msg ai-msg--${role}`;

    const avatar = document.createElement('div');
    avatar.className = 'ai-msg-avatar';
    avatar.innerHTML = role === 'user' ? userIcon : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>`;

    const bubble = document.createElement('div');
    bubble.className = 'ai-msg-bubble';
    bubble.innerHTML = formatReply(text);

    const foot = document.createElement('div');
    foot.className = 'ai-msg-meta';
    foot.textContent = meta || (role === 'user' ? 'Você' : 'Assistente');

    const wrap = document.createElement('div');
    wrap.className = 'ai-msg-content';
    wrap.appendChild(bubble);
    wrap.appendChild(foot);
    row.appendChild(avatar);
    row.appendChild(wrap);
    msgs.appendChild(row);
    scrollToBottom(true);
  };

  const showError = (msg) => {
    const el = document.createElement('div');
    el.className = 'ai-error';
    el.setAttribute('role', 'alert');
    el.innerHTML = `<span class="ai-error-icon">${retryIcon}</span>${escapeHtml(msg)}`;
    msgs.appendChild(el);
    scrollToBottom(true);
  };

  // ── Typing indicator ────────────────────────────────────────────
  const typingMessages = [
    'Analisando suas finanças...',
    'Processando seus dados...',
    'Calculando...',
  ];
  let typingIdx = 0;
  let typingTimer = null;

  const showTyping = () => {
    typing.hidden = false;
    const dot = typing.querySelector('.ai-typing-text');
    if (dot) {
      dot.textContent = typingMessages[typingIdx % typingMessages.length];
    }
    typingIdx++;
    scrollToBottom(true);
  };

  const hideTyping = () => {
    typing.hidden = true;
    if (typingTimer) { clearInterval(typingTimer); typingTimer = null; }
    typingIdx = 0;
  };

  // Cycle through typing messages every 2.5s
  const startTypingCycle = () => {
    hideTyping();
    showTyping();
    typingTimer = setInterval(() => {
      const dot = typing.querySelector('.ai-typing-text');
      if (dot) dot.textContent = typingMessages[typingIdx % typingMessages.length];
      typingIdx++;
    }, 2500);
  };

  // ── Button state machine ────────────────────────────────────────
  // States: 'idle' | 'busy' | 'error'
  const setButtonState = (state) => {
    switch (state) {
      case 'busy':
        sendBtn.disabled = true;
        sendBtn.className = 'ai-send ai-send--busy';
        sendBtn.innerHTML = `${spinnerIcon}<span>Enviando...</span>`;
        sendBtn.setAttribute('aria-label', 'Enviando mensagem, aguarde');
        input.disabled = true;
        break;
      case 'error':
        sendBtn.disabled = false;
        sendBtn.className = 'ai-send ai-send--retry';
        sendBtn.innerHTML = `${retryIcon}<span>Tentar novamente</span>`;
        sendBtn.setAttribute('aria-label', 'Tentar novamente');
        input.disabled = false;
        input.focus();
        break;
      case 'idle':
      default:
        sendBtn.disabled = false;
        sendBtn.className = 'ai-send';
        sendBtn.innerHTML = `${sendIcon}<span>Enviar</span>`;
        sendBtn.setAttribute('aria-label', 'Enviar mensagem');
        input.disabled = false;
        input.focus();
        break;
    }
  };

  // ── Character counter ──────────────────────────────────────────
  const updateCount = () => {
    const len = input.value.length;
    counter.textContent = `${len.toLocaleString('pt-BR')} / 2000`;
    const pct = len / 2000;
    if (pct >= 0.95) {
      counter.style.color = 'var(--color-danger)';
    } else if (pct >= 0.75) {
      counter.style.color = 'var(--color-warning)';
    } else {
      counter.style.color = 'var(--color-text-3)';
    }
    // auto-resize textarea
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  };

  input.addEventListener('input', updateCount);
  input.addEventListener('keydown', (e) => {
    // Enter without Shift → submit
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (!busy && input.value.trim()) form.requestSubmit();
    }
  });

  // ── Suggestions ─────────────────────────────────────────────────
  document.querySelectorAll('.ai-chip').forEach(btn => {
    btn.addEventListener('click', () => {
      input.value = btn.dataset.q || btn.textContent.trim();
      updateCount();
      input.focus();
      if (!busy) form.requestSubmit();
    });
  });

  // ── Clear ───────────────────────────────────────────────────────
  clearBtn?.addEventListener('click', () => {
    history = [];
    lastError = false;
    msgs.querySelectorAll('.ai-msg, .ai-error').forEach(el => el.remove());
    if (empty) empty.style.display = '';
    status.textContent = '';
    remain.textContent = `${parseInt(remain.textContent) || 0} mensagens restantes hoje`;
    input.value = '';
    updateCount();
    setButtonState('idle');
    hideTyping();
    input.focus();
  });

  // ── Form submit ─────────────────────────────────────────────────
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return;
    const text = input.value.trim();
    if (!text) return;
    if (text.length > 2000) {
      showError('Mensagem muito longa. O máximo é 2.000 caracteres.');
      return;
    }

    busy = true;
    lastError = false;
    const originalText = text;

    // Show user message immediately
    addMessage('user', text);
    history.push({ role: 'user', content: text });
    input.value = '';
    updateCount();
    setButtonState('busy');
    startTypingCycle();
    status.textContent = '';

    try {
      const res = await fetch('/index.php?action=ai_chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, history: history.slice(-8) }),
        credentials: 'same-origin',
      });

      const raw = await res.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch {
        console.error('[ai] raw:', raw.slice(0, 400));
        throw new Error('Resposta inválida do servidor. Tente novamente.');
      }

      if (!data || data.success === false) {
        throw new Error(data?.error || `Erro (${res.status}). Tente novamente.`);
      }

      const reply = data.reply || data.response;
      if (!reply || typeof reply !== 'string' || !reply.trim()) {
        throw new Error('Resposta vazia da IA. Tente novamente.');
      }

      // Success
      hideTyping();
      addMessage('assistant', reply, data.source === 'deterministic' ? 'Resposta instantânea' : 'IA');
      history.push({ role: 'assistant', content: reply });

      if (typeof data.remaining === 'number') {
        remain.textContent = `${data.remaining} mensagens restantes hoje`;
        if (data.remaining === 0) {
          status.textContent = 'Limite diário atingido — volte amanhã!';
        }
      }

      setButtonState('idle');

    } catch (err) {
      hideTyping();
      busy = false;
      lastError = true;
      const msg = err?.message || 'Não foi possível obter resposta. Tente novamente.';
      showError(msg);
      status.textContent = 'Não foi possível responder.';
      setButtonState('error');
    }

    // restore busy flag (setButtonState already handles it)
    busy = false;
  });

  updateCount();
})();
