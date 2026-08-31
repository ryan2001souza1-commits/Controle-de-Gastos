(() => {
  const form = document.getElementById('aiForm');
  const input = document.getElementById('aiInput');
  const send = document.getElementById('aiSend');
  const messages = document.getElementById('aiMessages');
  const empty = document.getElementById('aiEmpty');
  const typing = document.getElementById('aiTyping');
  const count = document.getElementById('aiCount');
  const remainingEl = document.getElementById('aiRemaining');
  const status = document.getElementById('aiStatus');
  const clearBtn = document.getElementById('aiClear');
  if (!form || !input) return;

  let history = [];
  let busy = false;

  const scrollToBottom = () => { messages.scrollTop = messages.scrollHeight; };
  const setBusy = (b) => { busy = b; send.disabled = b; input.disabled = b; typing.hidden = !b; if (b) send.classList.add('is-loading'); else send.classList.remove('is-loading'); };
  const updateCount = () => { count.textContent = `${input.value.length} / 2000`; count.style.color = input.value.length > 1800 ? 'var(--color-danger)' : 'var(--color-text-3)'; input.style.height='auto'; input.style.height = Math.min(input.scrollHeight, 120)+'px'; };
  input.addEventListener('input', updateCount);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });

  const escapeHtml = (s) => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const formatReply = (text) => {
    // simple markdown: **bold**, bullet -, line breaks
    let h = escapeHtml(text);
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // bullet lines
    h = h.replace(/^[\-\•]\s(.+)$/gm, '<li>$1</li>');
    h = h.replace(/(<li>.*<\/li>)/gs, (m) => `<ul>${m}</ul>`);
    h = h.replace(/\n/g, '<br>');
    return h;
  };

  const addMessage = (role, text, meta='') => {
    if (empty) empty.style.display='none';
    const row = document.createElement('div');
    row.className = `ai-msg ai-msg--${role}`;
    const avatar = document.createElement('div');
    avatar.className = 'ai-msg-avatar';
    avatar.innerHTML = role==='user' ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
    const bubble = document.createElement('div');
    bubble.className = 'ai-msg-bubble';
    bubble.innerHTML = formatReply(text);
    const foot = document.createElement('div');
    foot.className = 'ai-msg-meta';
    foot.textContent = meta || (role==='user' ? 'Você' : 'Assistente');
    const wrap = document.createElement('div');
    wrap.className = 'ai-msg-content';
    wrap.appendChild(bubble);
    wrap.appendChild(foot);
    row.appendChild(avatar);
    row.appendChild(wrap);
    messages.appendChild(row);
    scrollToBottom();
  };

  const addError = (msg) => {
    const el = document.createElement('div');
    el.className = 'ai-error';
    el.textContent = msg;
    messages.appendChild(el);
    scrollToBottom();
  };

  // suggestions
  document.querySelectorAll('.ai-chip').forEach(btn => {
    btn.addEventListener('click', () => {
      input.value = btn.dataset.q || btn.textContent.trim();
      updateCount();
      input.focus();
      form.requestSubmit();
    });
  });

  clearBtn?.addEventListener('click', () => {
    history = [];
    messages.querySelectorAll('.ai-msg, .ai-error').forEach(el=>el.remove());
    if (empty) empty.style.display='';
    status.textContent='';
    input.value=''; updateCount(); input.focus();
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return;
    const text = input.value.trim();
    if (!text) return;
    if (text.length > 2000) { addError('Mensagem muito longa. Máx. 2000 caracteres.'); return; }

    addMessage('user', text);
    history.push({role:'user', content:text});
    input.value=''; updateCount();
    setBusy(true);
    status.textContent='';

    try {
      const res = await fetch('/index.php?action=ai_chat', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({message:text, history: history.slice(-8)}),
        credentials: 'same-origin'
      });
      // Diagnóstico ponta-a-ponta: lê raw para detectar HTML/warnings antes do JSON
      const raw = await res.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (e) {
        console.error('[ai] raw response:', raw.slice(0,800));
        throw new Error(raw.slice(0,300) || `Erro ${res.status}: resposta não-JSON`);
      }
      if (!data) throw new Error('Resposta vazia da IA.');
      if (!res.ok || data.success === false) throw new Error(data.error || `Erro ${res.status}`);
      const replyRaw = data.reply || data.response;
      if (!replyRaw || typeof replyRaw !== 'string' || !replyRaw.trim()) {
        throw new Error(data.error || 'Sem resposta da IA. Tente novamente em instantes.');
      }
      const reply = replyRaw;
      addMessage('assistant', reply, data.source==='deterministic' ? 'Resposta rápida' : 'IA');
      history.push({role:'assistant', content: reply});
      if (typeof data.remaining === 'number') {
        remainingEl.textContent = `${data.remaining} mensagens restantes hoje`;
        if (data.remaining === 0) status.textContent='Limite diário atingido';
      }
      status.textContent='';
    } catch (err) {
      console.error('[ai]', err);
      addError(err.message || 'Não foi possível obter uma resposta da IA. Tente novamente.');
      status.textContent='Erro';
    } finally {
      setBusy(false);
      input.focus();
    }
  });

  updateCount();
})();
