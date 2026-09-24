(function(){
  'use strict';

  /*THEME*/

  const html = document.documentElement;
  const themeBtn = document.getElementById('themeToggle');
  html.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');

  themeBtn?.addEventListener('click', () => {
    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
  });

  /*UPLOAD BOX + DRAG & DROP*/

  const box = document.querySelector('.upload-box');
  const input = document.querySelector('.upload-box input[type="file"]');
  const label = document.querySelector('.upload-box__text');
  const hint = document.querySelector('.upload-box__hint');

  const setUI = (name) => {
    if (!box) return;
    if (name) {
      box.classList.add('has-file');
      label.textContent = name;
      hint.textContent = 'Ready to extract';
    } else {
      box.classList.remove('has-file');
      label.textContent = 'Choose a .docx file';
      hint.textContent = 'or drag and drop';
    }
  };

  if (box && input) {
    input.addEventListener('change', e => {
      const f = e.target.files[0];
      setUI(f ? f.name : null);
    });

    ['dragenter','dragover'].forEach(ev =>
      box.addEventListener(ev, e => { e.preventDefault(); box.classList.add('has-file'); })
    );

    ['dragleave','dragend'].forEach(ev =>
      box.addEventListener(ev, e => {
        e.preventDefault();
        if (!input.files.length) box.classList.remove('has-file');
      })
    );

    box.addEventListener('drop', e => {
      e.preventDefault();
      const f = e.dataTransfer.files[0];
      if (!f) return;
      if (!f.name.toLowerCase().endsWith('.docx')) {
        alert('Only .docx files allowed.');
        setUI(null);
        return;
      }
      const dt = new DataTransfer();
      dt.items.add(f);
      input.files = dt.files;
      setUI(f.name);
    });
  }

  /*SMOOTH SCROLL*/

  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      const t = document.querySelector(a.getAttribute('href'));
      if (t) { e.preventDefault(); t.scrollIntoView({behavior:'smooth'}); }
    });
  });

  /*SCROLL REVEAL*/

  const obs = new IntersectionObserver((entries, o) => {
    entries.forEach(en => {
      if (en.isIntersecting) {
        en.target.style.opacity = '1';
        en.target.style.transform = 'translateY(0)';
        o.unobserve(en.target);
      }
    });
  }, {threshold: 0.1});

  document.querySelectorAll('.section, .hero, .folder').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'opacity .8s cubic-bezier(.22,1,.36,1), transform .8s cubic-bezier(.22,1,.36,1)';
    obs.observe(el);
  });

  /*MODAL*/

  const modal = document.getElementById('detailsModal');
  const backdrop = document.getElementById('modalBackdrop');
  const closeBtn = document.getElementById('modalClose');
  const title = document.getElementById('modalTitle');
  const body = document.getElementById('modalBody');
  const HEADERS = ['ID','Document','Section','Content','Keywords','Category','Version','Status'];

  const esc = t => { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; };

  if (modal) {
    document.querySelectorAll('.data-row').forEach(row => {
      row.addEventListener('click', () => {
        const data = JSON.parse(row.getAttribute('data-row'));
        title.textContent = data[2] || 'Untitled';

        body.innerHTML = HEADERS.map((label, i) => {
          const v = data[i] || '';
          if (!v) return '';
          if (i === 3) return `<div class="modal__field"><span class="modal__label">${label}</span><div class="modal__value modal__value--code">${esc(v)}</div></div>`;
          if (i === 4 || i === 5) {
            const tags = v.split(',').map(t => t.trim()).filter(Boolean);
            return `<div class="modal__field"><span class="modal__label">${label}</span><div>${tags.map(t => `<span class="modal__tag">${esc(t)}</span>`).join('')}</div></div>`;
          }
          return `<div class="modal__field"><span class="modal__label">${label}</span><span class="modal__value">${esc(v)}</span></div>`;
        }).join('');

        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
      });
    });

    const close = () => {
      modal.classList.remove('is-open');
      document.body.classList.remove('modal-open');
    };

    closeBtn?.addEventListener('click', close);
    backdrop?.addEventListener('click', close);
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
    });
  }

  /*BACKUP SEARCH*/

  const search = document.getElementById('backupSearch');
  const noResults = document.getElementById('backupNoResults');

  if (search) {
    const items = [...document.querySelectorAll('#backupDocxList .folder__item, #backupXlsxList .folder__item')];
    search.addEventListener('input', function() {
      const q = this.value.trim().toLowerCase();
      let hits = 0;
      items.forEach(it => {
        const match = (it.getAttribute('data-filename') || '').includes(q);
        it.style.display = match ? '' : 'none';
        if (match) hits++;
      });
      if (noResults) noResults.style.display = hits === 0 ? 'block' : 'none';
    });
  }

  /*SELECT ALL*/

  const selectAll = document.getElementById('selectAll');
  if (selectAll) {
    const boxes = () => [...document.querySelectorAll('#bulkDeleteForm input[type="checkbox"]:not(#selectAll)')];

    selectAll.addEventListener('change', function() {
      boxes().forEach(b => {
        const item = b.closest('.folder__item');
        if (item && item.style.display !== 'none') b.checked = this.checked;
      });
    });

    boxes().forEach(b => b.addEventListener('change', () => {
      const visible = boxes().filter(x => x.closest('.folder__item').style.display !== 'none');
      selectAll.checked = visible.length > 0 && visible.every(x => x.checked);
    }));
  }

})();
