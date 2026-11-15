<?php $formCurrency = strtoupper(trim((string)($form['invoice_currency'] ?? 'EUR'))); ?>
<label>Arve valuuta
    <select name="invoice_currency" required>
        <?php foreach (invoiceFxCurrencies() as $code): ?>
            <option value="<?= h($code) ?>" <?= $formCurrency === $code ? 'selected' : '' ?>><?= h($code) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<fieldset id="invoice-fx-settings" <?= $formCurrency === 'EUR' ? 'hidden' : '' ?>>
    <legend>Valuutakurss</legend>
    <p>Ühikuhinnad sisesta arve valuutas. Vali tehingu kuupäev, käibemaksuga arvel käibe tekkimise kuupäev.</p>
    <label>Kursi aluseks olev kuupäev
        <input name="fx_event_date" type="date" value="<?= h((string)($form['fx_event_date'] ?? '')) ?>">
    </label>
    <label>Kursi allikas
        <select name="fx_source"><option value="ecb">EKP</option><option value="manual" <?= ($form['fx_source'] ?? '') === 'manual' ? 'selected' : '' ?>>Käsitsi</option></select>
    </label>
    <label>1 EUR = X arve valuuta ühikut
        <input name="fx_rate" inputmode="decimal" value="<?= h((string)($form['fx_rate'] ?? '')) ?>">
    </label>
    <label>Käsikursi alus
        <input name="fx_note" maxlength="250" value="<?= h((string)($form['fx_note'] ?? '')) ?>">
    </label>
    <button type="button" id="invoice-fx-quote">Too EKP kurss</button>
    <small id="invoice-fx-status" aria-live="polite"></small>
</fieldset>
<script>
(() => {
    const box = document.getElementById('invoice-fx-settings');
    const fields = box.form.elements;
    const button = document.getElementById('invoice-fx-quote');
    const status = document.getElementById('invoice-fx-status');
    const currency = () => fields.invoice_currency.value.trim().toUpperCase();
    const quoteKey = () => currency() + '|' + fields.fx_event_date.value;
    let currentKey = quoteKey();
    let loadedKey = '';
    let request = null;
    const sync = () => {
        box.hidden = currency() === 'EUR';
        fields.fx_event_date.required = !box.hidden;
        fields.fx_rate.readOnly = fields.fx_source.value === 'ecb';
        fields.fx_rate.required = !box.hidden && !fields.fx_rate.readOnly;
        fields.fx_note.required = fields.fx_rate.required;
    };
    async function loadQuote() {
        const code = currency(), date = fields.fx_event_date.value;
        if (!date || code === 'EUR' || request || loadedKey === quoteKey()) return;
        const key = quoteKey();
        const controller = new AbortController();
        request = controller;
        button.disabled = true;
        status.textContent = 'Laen kurssi…';
        try {
            const body = new URLSearchParams({action:'quote',csrf_token:fields.csrf_token.value,currency:code,date});
            const reply = await fetch('security_fx.php',{method:'POST',body,signal:controller.signal});
            const quote = await reply.json();
            if (!reply.ok) throw new Error(quote.error);
            if (request !== controller || key !== quoteKey()) return;
            loadedKey = key;
            fields.fx_source.value = 'ecb';
            fields.fx_rate.value = quote.rate;
            status.textContent = 'EKP kursi kuupäev '+quote.date;
            sync();
        } catch (error) {
            if (request === controller) status.textContent = error.message || 'Kurssi ei õnnestunud laadida.';
        } finally {
            if (request === controller) {
                request = null;
                button.disabled = false;
            }
        }
    }
    function invalidateQuote() {
        request?.abort();
        request = null;
        loadedKey = '';
        button.disabled = false;
        status.textContent = '';
        if (fields.fx_source.value === 'ecb') fields.fx_rate.value = '';
    }
    function syncQuoteInputs() {
        if (currentKey !== quoteKey()) {
            currentKey = quoteKey();
            invalidateQuote();
        }
        sync();
    }
    for (const name of ['invoice_currency', 'fx_event_date']) {
        fields[name].addEventListener('input', syncQuoteInputs);
        fields[name].addEventListener('change', () => {
            syncQuoteInputs();
            if (fields.fx_source.value === 'ecb') loadQuote();
        });
    }
    fields.fx_source.addEventListener('change', () => {
        invalidateQuote();
        sync();
        if (fields.fx_source.value === 'ecb') loadQuote();
    });
    button.addEventListener('click', () => {
        syncQuoteInputs();
        if (!fields.fx_event_date.value) {
            status.textContent = 'Vali kursi aluseks olev kuupäev. Seejärel laetakse kurss automaatselt.';
            fields.fx_event_date.reportValidity();
            return;
        }
        loadQuote();
    });
    sync();
})();
</script>
