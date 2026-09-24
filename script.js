(function(){
  'use strict';

  /*LIGHT/DARK TOGGLE*/

  (function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const html = document.documentElement;

    const savedTheme = localStorage.getItem('theme') || 'dark';
    html.setAttribute('data-theme', savedTheme);

    if (!toggle) return;

    toggle.addEventListener('click', function() {
      const current = html.getAttribute('data-theme');
      const next = current === 'dark' ? 'light' : 'dark';

      html.setAttribute('data-theme', next);
      localStorage.setItem('theme', next);
    });
  })();

  /*UPLOAD BOX */

  const fileInput = document.querySelector('.upload-box input[type="file"]');
  const uploadBox = document.querySelector('.upload-box');
  const uploadText = document.querySelector('.upload-box__text');
  const uploadHint = document.querySelector('.upload-box__hint');

  if (fileInput && uploadBox) {
    /* 1. Normal click-to-select */
    fileInput.addEventListener('change', function() {
      if (this.files && this.files[0]) {
        uploadBox.classList.add('has-file');
        uploadText.textContent = this.files[0].name;
        uploadHint.textContent = 'Ready to extract';
      } else {
        uploadBox.classList.remove('has-file');
        uploadText.textContent = 'Choose a .docx file';
        uploadHint.textContent = 'or drag and drop';
      }
    });

    /* 2. Drag hover feedback */
    ['dragenter', 'dragover'].forEach(event => {
      uploadBox.addEventListener(event, (e) => {
        e.preventDefault();
        e.stopPropagation();
        uploadBox.classList.add('has-file');
      });
    });

    /* 3. Reset visual when dragging away */
    ['dragleave', 'dragend'].forEach(event => {
      uploadBox.addEventListener(event, (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!fileInput.files.length) {
          uploadBox.classList.remove('has-file');
        }
      });
    });

    /* 4. Handle the actual DROP — assign dropped file to the input */
    uploadBox.addEventListener('drop', function(e) {
      e.preventDefault();
      e.stopPropagation();

      const files = e.dataTransfer.files;
      if (files && files.length > 0) {
        const file = files[0];

        if (!file.name.toLowerCase().endsWith('.docx')) {
          alert('Please drop a .docx file only.');
          uploadBox.classList.remove('has-file');
          return;
        }

        /* Put the file into the hidden file input */
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        fileInput.files = dataTransfer.files;

        /* Update UI */
        uploadBox.classList.add('has-file');
        uploadText.textContent = file.name;
        uploadHint.textContent = 'Ready to extract';
      }
    });
  }

  /*SMOOTH SCROLL*/

  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', function(e) {
      const target = document.querySelector(this.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  /*SCROLL REVEAL*/

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.style.opacity = '1';
        entry.target.style.transform = 'translateY(0)';
      }
    });
  }, { threshold: 0.1 });

  document.querySelectorAll('.section, .hero, .folder').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'opacity 0.8s cubic-bezier(.22,1,.36,1), transform 0.8s cubic-bezier(.22,1,.36,1)';
    observer.observe(el);
  });

  /*TABLE ROW DETAILS MODAL*/

  const modal = document.getElementById('detailsModal');
  const modalBackdrop = document.getElementById('modalBackdrop');
  const modalClose = document.getElementById('modalClose');
  const modalTitle = document.getElementById('modalTitle');
  const modalBody = document.getElementById('modalBody');

  const COLUMNS = ['ID', 'Document', 'Section', 'Content', 'Keywords', 'Category', 'Version', 'Status'];

  if (modal) {
    document.querySelectorAll('.data-row').forEach(row => {
      row.addEventListener('click', function() {
        const data = JSON.parse(this.getAttribute('data-row'));

        modalTitle.textContent = data[2] || 'Untitled Section';

        let html = '';
        COLUMNS.forEach((label, i) => {
          const value = data[i] || '';
          if (!value) return;

          if (i === 3) {
            html += `<div class="modal__field">
              <span class="modal__label">${label}</span>
              <div class="modal__value modal__value--code">${escapeHtml(value)}</div>
            </div>`;
          } else if (i === 4 || i === 5) {
            const tags = value.split(',').map(t => t.trim()).filter(Boolean);
            html += `<div class="modal__field">
              <span class="modal__label">${label}</span>
              <div>${tags.map(t => `<span class="modal__tag">${escapeHtml(t)}</span>`).join('')}</div>
            </div>`;
          } else {
            html += `<div class="modal__field">
              <span class="modal__label">${label}</span>
              <span class="modal__value">${escapeHtml(value)}</span>
            </div>`;
          }
        });

        modalBody.innerHTML = html;
        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
      });
    });

    function closeModal() {
      modal.classList.remove('is-open');
      document.body.classList.remove('modal-open');
    }

    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modalBackdrop) modalBackdrop.addEventListener('click', closeModal);

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) {
        closeModal();
      }
    });
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /*BACKUP FOLDER SEARCH*/

  const backupSearch = document.getElementById('backupSearch');
  const backupNoResults = document.getElementById('backupNoResults');

  if (backupSearch) {
    const docxItems = Array.from(document.querySelectorAll('#backupDocxList .folder__item'));
    const xlsxItems = Array.from(document.querySelectorAll('#backupXlsxList .folder__item'));
    const allItems = [...docxItems, ...xlsxItems];

    backupSearch.addEventListener('input', function() {
      const query = this.value.trim().toLowerCase();
      let visibleCount = 0;

      allItems.forEach(item => {
        const filename = item.getAttribute('data-filename') || '';
        const match = filename.includes(query);

        if (match) {
          item.style.display = '';
          visibleCount++;
        } else {
          item.style.display = 'none';
        }
      });

      if (backupNoResults) {
        backupNoResults.style.display = visibleCount === 0 ? 'block' : 'none';
      }
    });
  }

  /*SELECT ALL CHECKBOX*/

  const selectAll = document.getElementById('selectAll');
  if (selectAll) {
    selectAll.addEventListener('change', function() {
      const boxes = document.querySelectorAll('#bulkDeleteForm input[type="checkbox"]:not(#selectAll)');
      boxes.forEach(box => {
        const item = box.closest('.folder__item');
        if (item && item.style.display !== 'none') {
          box.checked = this.checked;
        }
      });
    });

    document.querySelectorAll('#bulkDeleteForm input[type="checkbox"]:not(#selectAll)').forEach(box => {
      box.addEventListener('change', function() {
        const visibleBoxes = Array.from(document.querySelectorAll('#bulkDeleteForm input[type="checkbox"]:not(#selectAll)'))
          .filter(b => b.closest('.folder__item').style.display !== 'none');
        const visibleChecked = visibleBoxes.filter(b => b.checked);
        selectAll.checked = visibleBoxes.length > 0 && visibleBoxes.length === visibleChecked.length;
      });
    });
  }

})();
