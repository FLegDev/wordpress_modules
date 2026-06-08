document.addEventListener('click', function(e){
  const btn = e.target.closest('.pa-mobile-toggle');
  if(!btn) return;
  const panel = btn.nextElementSibling;
  const expanded = btn.getAttribute('aria-expanded') === 'true';
  btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
  if(panel){ panel.hidden = expanded; }
});
