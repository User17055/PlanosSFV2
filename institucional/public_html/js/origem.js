// Guarda por 30 dias de onde o visitante veio (anúncio, Google, Instagram...) e preenche o cadastro.
// Só guarda a origem e o nome da campanha, nenhum dado pessoal.
(function () {
  var COOKIE = 'sf_origem';

  function read() {
    var m = document.cookie.match(/(?:^|; )sf_origem=([^;]*)/);
    if (!m) return null;
    try { var v = decodeURIComponent(m[1]).split('|'); return { source: v[0], campaign: v[1] || '' }; } catch (e) { return null; }
  }

  function write(source, campaign, paid) {
    document.cookie = COOKIE + '=' + encodeURIComponent(source + '|' + campaign.replace(/\|/g, ' ') + '|' + (paid ? '1' : '0')) + ';max-age=' + 30 * 86400 + ';path=/;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
  }

  // Valores aceitos pelo sistema: google, instagram, facebook ou other.
  function classify(text) {
    text = (text || '').toLowerCase();
    if (/google|adwords/.test(text)) return 'google';
    if (/instagram|^ig$/.test(text)) return 'instagram';
    if (/facebook|^fb$|^meta/.test(text)) return 'facebook';
    return 'other';
  }

  var query = new URLSearchParams(location.search);
  var host = '';
  try { host = new URL(document.referrer).hostname.replace(/^www\./, ''); } catch (e) {}
  var utm = query.get('utm_source');
  // Estes códigos só existem em clique de anúncio do Google. O fbclid não serve para isso: o Facebook põe em qualquer link, até de post comum.
  var googleAd = query.get('gclid') || query.get('gbraid') || query.get('wbraid');
  var paid = googleAd || /^(cpc|ppc|cpm|cpv|display|sem|retargeting)$|paid|(^|[_-])ads?$/i.test(query.get('utm_medium') || '');

  // Um anúncio ou link marcado sempre vale; quem veio de outro site só vale se ainda não houver origem guardada.
  if (utm || googleAd || query.get('fbclid')) {
    write(utm ? classify(utm) : (googleAd ? 'google' : 'facebook'), (query.get('utm_campaign') || '').slice(0, 120), paid);
  } else if (host && host !== location.hostname.replace(/^www\./, '') && !read()) {
    write(classify(host), '', false);
  }

  function fill() {
    var origin = read();
    if (!origin) return;
    var select = document.querySelector('select[name="acquisition_source"]');
    if (select && !select.value) select.value = origin.source;
    var campaign = document.querySelector('input[name="campaign_name"]');
    if (campaign && !campaign.value) campaign.value = origin.campaign;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fill); else fill();
})();
