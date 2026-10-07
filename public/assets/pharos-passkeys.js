(() => {
  const decode = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
  const encode = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  const prepare = options => {
    options.publicKey.challenge = decode(options.publicKey.challenge);
    if (options.publicKey.user) options.publicKey.user.id = decode(options.publicKey.user.id);
    for (const kind of ['excludeCredentials', 'allowCredentials']) for (const credential of options.publicKey[kind] || []) credential.id = decode(credential.id);
    return options;
  };
  const post = async (url, data, token) => {
    const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token}, body: JSON.stringify(data)});
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || '');
    return result;
  };
  const run = async (root, register) => {
    const form = register ? root.querySelector('form[data-passkey-form]') : null;
    const button = register ? form.querySelector('button') : root.querySelector('button');
    const status = root.querySelector('[data-passkey-status]');
    const token = register ? form.elements._token.value : root.dataset.csrf;
    button.disabled = true;
    try {
      if (!window.isSecureContext || !navigator.credentials) throw new Error(root.dataset.error);
      const options = await post(root.dataset.optionsUrl, register ? {current_password: form.elements.current_password.value} : {}, token);
      const credential = await navigator.credentials[register ? 'create' : 'get'](prepare(options));
      const response = credential.response;
      const data = {id: encode(credential.rawId), clientDataJSON: encode(response.clientDataJSON)};
      if (register) {data.name = form.elements.name.value; data.attestationObject = encode(response.attestationObject);}
      else {data.authenticatorData = encode(response.authenticatorData); data.signature = encode(response.signature); data.userHandle = encode(response.userHandle);}
      const result = await post(root.dataset.submitUrl, data, token);
      if (register) location.reload(); else location.assign(result.redirect);
    } catch (error) {status.textContent = root.dataset.error;}
    finally {button.disabled = false; if (form) form.elements.current_password.value = '';}
  };
  const available = !!(window.isSecureContext && navigator.credentials && window.PublicKeyCredential);
  const setup = (root, register) => {
    const form = register ? root.querySelector('form[data-passkey-form]') : null;
    const button = register ? form.querySelector('button') : root.querySelector('button');
    button.disabled = !available;
    if (!available) {
      root.querySelector('[data-passkey-status]').textContent = root.dataset.unavailable;
    }
    // Prevent native form submission even when the browser cannot use WebAuthn.
    if (form) form.addEventListener('submit', event => {event.preventDefault(); if (available) run(root, true);});
    else button.addEventListener('click', () => {if (available) run(root, false);});
  };
  for (const root of document.querySelectorAll('[data-passkey-register]')) setup(root, true);
  for (const root of document.querySelectorAll('[data-passkey-login]')) setup(root, false);
})();
