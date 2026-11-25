const menuToggle = document.querySelector('.app-menu-toggle');

if (menuToggle) {
    const menu = menuToggle.closest('nav');
    menuToggle.hidden = false;
    menuToggle.addEventListener('click', () => {
        const open = menuToggle.getAttribute('aria-expanded') === 'true';
        menuToggle.setAttribute('aria-expanded', String(!open));
    });
    menu.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || menuToggle.getAttribute('aria-expanded') !== 'true'
            || menuToggle.offsetWidth === 0) return;
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.focus();
    });
}

for (const roster of document.querySelectorAll('[data-roster]')) {
    const feedback = document.querySelector('#feedback');
    const count = roster.closest('.attendance-group').querySelector('[data-attendance-count]');

    roster.addEventListener('click', async (event) => {
        const card = event.target.closest('button[data-id]');
        if (!card || card.getAttribute('aria-busy') === 'true') return;

        const name = card.querySelector('.name').textContent;
        const present = card.getAttribute('aria-pressed') !== 'true';
        card.setAttribute('aria-busy', 'true');
        feedback.classList.remove('error');
        feedback.textContent = `A guardar a presença de ${name}…`;

        try {
            const response = await fetch('?action=attendance', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': roster.dataset.csrf,
                },
                body: JSON.stringify({
                    kind: roster.dataset.roster,
                    id: Number(card.dataset.id),
                    date: roster.dataset.date,
                    present,
                }),
                signal: AbortSignal.timeout(10000),
            });
            const result = await response.json();
            if (!response.ok) {
                feedback.classList.add('error');
                feedback.textContent = result.error || 'Atualize a página e tente novamente.';
                return;
            }

            card.setAttribute('aria-pressed', String(result.present));
            card.querySelector('.state').textContent = result.present ? '✓ Presente' : 'Por marcar';
            const marked = roster.querySelectorAll('[aria-pressed="true"]').length;
            count.textContent = `${marked}/${roster.children.length} presentes`;
            feedback.classList.remove('error');
            const usage = card.querySelector('[data-monthly-usage]');
            const overLimit = result.limit != null && result.monthly > result.limit;
            if (usage) {
                usage.hidden = result.limit == null;
                usage.classList.toggle('is-over-limit', overLimit);
                usage.textContent = result.limit == null ? '' : `${result.monthly}/${result.limit} no mês`;
                if (overLimit) usage.textContent += ' · Limite ultrapassado';
            }
            feedback.textContent = result.present
                ? `Presença de ${name} guardada.`
                : `Presença de ${name} desmarcada.`;
            if (overLimit) feedback.textContent += ` Limite mensal ultrapassado (${result.monthly}/${result.limit}).`;
        } catch {
            feedback.classList.add('error');
            feedback.textContent = `Não foi possível confirmar a presença de ${name}. Atualize a página ou tente novamente.`;
        } finally {
            card.removeAttribute('aria-busy');
        }
    });
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('./sw.js').catch(console.error);
}

const trainingCancellation = document.querySelector('#training-cancellation');
if (trainingCancellation) {
    const checkbox = trainingCancellation.elements.namedItem('cancelled');
    const reason = trainingCancellation.elements.namedItem('reason');
    const reasonField = trainingCancellation.querySelector('#training-reason');

    const updateReasonField = () => {
        reasonField.hidden = !checkbox.checked;
        reason.required = checkbox.checked;
    };

    checkbox.addEventListener('change', updateReasonField);
    updateReasonField();
}
