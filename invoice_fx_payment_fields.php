<div class="payment-field">
    <label for="payment-foreign-amount">Tasumine arve valuutas (<?= h($fxCurrency) ?>)</label>
    <input id="payment-foreign-amount" type="number" name="fx_amount" required
           min="<?= invoiceFxPaymentStep($fxCurrency) ?>" step="<?= invoiceFxPaymentStep($fxCurrency) ?>"
           max="<?= h($fxBalance) ?>" value="<?= h($fxBalance) ?>" data-currency="<?= h($fxCurrency) ?>">
</div>
<div class="payment-field">
    <label for="payment-cash-eur">Rahakande summa eurodes</label>
    <input id="payment-cash-eur" type="number" name="fx_cash_eur" min="0.01" step="0.01" required>
    <small>EKP järgi arvutatud summa on ettepanek. Eurokonto puhul sisesta pangas liikunud summa, valuutakonto puhul raamatupidamises kasutatav euroväärtus.</small>
    <p><button type="button" id="payment-fx-quote">Arvuta EKP kursiga</button>
        <small id="payment-fx-status" aria-live="polite"></small></p>
</div>
<script>
(() => {
    const amount = document.getElementById('payment-foreign-amount');
    const fields = amount.form.elements;
    const euro = fields.fx_cash_eur;
    const date = fields.payment_date;
    const button = document.getElementById('payment-fx-quote');
    const status = document.getElementById('payment-fx-status');
    const quoteKey = () => date.value + '|' + amount.value;
    let currentKey = quoteKey();
    let loadedKey = '';
    let manual = euro.value !== '';
    let request = null;

    function cancelQuote() {
        request?.abort();
        request = null;
        button.disabled = false;
    }

    async function suggest() {
        if (manual || request || loadedKey === quoteKey() || !date.value || !amount.value || !amount.validity.valid) return;
        const controller = new AbortController();
        const requestedDate = date.value;
        const requestedAmount = amount.value;
        const currency = amount.dataset.currency;
        request = controller;
        button.disabled = true;
        status.textContent = 'Arvutan EKP kursiga…';
        try {
            const body = new URLSearchParams({
                action: 'quote', csrf_token: fields.csrf_token.value, currency,
                date: requestedDate, amount: requestedAmount
            });
            const response = await fetch('security_fx.php', {method:'POST', body, signal:controller.signal});
            const quote = await response.json();
            if (!response.ok || !quote.euro_amount) throw new Error(quote.error || 'Eurosummat ei õnnestunud arvutada.');
            if (request !== controller || manual || date.value !== requestedDate || amount.value !== requestedAmount) return;
            if (Number(quote.euro_amount) <= 0) throw new Error('Arvutatud eurosumma jääb alla ühe sendi.');
            euro.value = quote.euro_amount;
            loadedKey = quoteKey();
            status.textContent = 'EKP kurss ' + quote.date + ' · 1 EUR = ' + quote.rate + ' ' + currency;
        } catch (error) {
            if (request === controller) status.textContent = error.message || 'Eurosummat ei õnnestunud arvutada.';
        } finally {
            if (request === controller) cancelQuote();
        }
    }

    euro.addEventListener('input', () => {
        manual = true;
        cancelQuote();
        status.textContent = 'Käsitsi sisestatud eurosumma.';
    });
    function syncInputs() {
        if (currentKey === quoteKey()) return;
        currentKey = quoteKey();
        loadedKey = '';
        cancelQuote();
        if (!manual) {
            euro.value = '';
            status.textContent = '';
        }
    }
    for (const field of [amount, date]) {
        field.addEventListener('input', syncInputs);
        field.addEventListener('change', () => {
            syncInputs();
            suggest();
        });
    }
    button.addEventListener('click', () => {
        if (!date.reportValidity() || !amount.reportValidity()) return;
        manual = false;
        loadedKey = '';
        euro.value = '';
        suggest();
    });
    suggest();
})();
</script>
