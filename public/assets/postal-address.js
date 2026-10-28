(() => {
  document.querySelectorAll('[data-postal-lookup]').forEach(input => {
    let version = 0, timer, controller, lastZip = '';
    const status = input.closest('label').querySelector('[data-postal-status]');
    const fields = input.form?.elements;
    const setStatus = message => { if (status) status.textContent = message; };
    async function lookup() {
      clearTimeout(timer);
      const zip = input.value.replace(/\D/g, '');
      if (zip.length !== 8 || zip === lastZip) return;
      const current = ++version;
      controller?.abort(); controller = new AbortController();
      const before = Object.fromEntries(['street', 'neighborhood', 'city', 'state'].map(name => [name, fields?.namedItem(name)?.value || '']));
      setStatus('Buscando rua, bairro e cidade pelo CEP…');
      input.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch(`${input.dataset.postalLookup}/${zip}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
        if (!response.ok) throw new Error();
        const address = await response.json();
        if (version !== current || input.value.replace(/\D/g, '') !== zip) return;
        ['street', 'neighborhood', 'city', 'state'].forEach(name => {
          const field = fields?.namedItem(name);
          if (!field || field.value !== before[name]) return;
          field.value = address[name] || '';
          field.dispatchEvent(new Event('input', { bubbles: true }));
        });
        lastZip = zip;
        setStatus(address.street ? 'Rua preenchida. Agora informe o número e confira o endereço.' : 'Este CEP não informa a rua. Preencha a rua e o número manualmente.');
        const number = fields?.namedItem('number');
        if(number && !number.value.trim())number.closest('.field')?.classList.add('is-next-field');
        input.form?.dispatchEvent(new CustomEvent('address:filled', { bubbles: true }));
      } catch (error) {
        if (version === current && error.name !== 'AbortError') setStatus('CEP não localizado ou consulta indisponível. Confira o CEP e preencha o endereço manualmente.');
      } finally { if (current === version) input.removeAttribute('aria-busy'); }
    }
    input.addEventListener('input', () => {
      version++; controller?.abort(); clearTimeout(timer); input.removeAttribute('aria-busy');
      const zip = input.value.replace(/\D/g, '').slice(0, 8);
      input.value = zip.length > 5 ? `${zip.slice(0, 5)}-${zip.slice(5)}` : zip;
      if (zip.length === 8) timer = setTimeout(lookup, 350);
      else setStatus('Digite os 8 números do CEP para buscar o endereço.');
    });
    input.addEventListener('blur', lookup);
    fields?.namedItem('number')?.addEventListener('input', event => { if(event.target.value.trim())event.target.closest('.field')?.classList.remove('is-next-field'); });
  });
})();
