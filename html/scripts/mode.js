function setModePreference() {
    const userPreference = localStorage.getItem('mode');
    if (userPreference === 'light-mode') {
      document.body.classList.remove('accent-warning');
      document.body.classList.remove('dark-mode');
      document.body.classList.add('light-mode');
      document.querySelector('nav').classList.remove('navbar-dark');
      document.querySelector('nav').classList.add('navbar-light');
    } else {
      document.body.classList.remove('light-mode');
      document.body.classList.add('dark-mode');
      document.body.classList.add('accent-warning');
      document.querySelector('nav').classList.remove('navbar-light');
      document.querySelector('nav').classList.add('navbar-dark');
      localStorage.setItem('mode', 'dark-mode');
    }
  }
  
  function toggleMode() {
    const body = document.body;
    const nav = document.querySelector('nav');
  
    if (body.classList.contains('dark-mode')) {
      body.classList.remove('accent-warning');
      body.classList.remove('dark-mode');
      body.classList.add('light-mode');
      nav.classList.remove('navbar-dark');
      nav.classList.add('navbar-light');
  
      localStorage.setItem('mode', 'light-mode');
    } else {
      body.classList.remove('light-mode');
      body.classList.add('dark-mode');
      body.classList.add('accent-warning');  
      nav.classList.remove('navbar-light');
      nav.classList.add('navbar-dark');
  
      localStorage.setItem('mode', 'dark-mode');
    }
  }
  
  document.addEventListener('DOMContentLoaded', function() {
    const toggleButton = document.getElementById('toggle-mode');
    
    toggleButton.addEventListener('click', function() {
      toggleMode();
    });
  
    setModePreference();
  });

  function getCurrentPageLanguage() {
    return document.body.getAttribute('data-current-lang') || 'en';
  }

  function setCurrentPageLanguage(lang) {
    document.body.setAttribute('data-current-lang', lang);
    const marquee = document.getElementById('my_marquee');
    if (marquee) {
      marquee.setAttribute('data-current-lang', lang);
    }
  }

  function updateMarqueeContent(lines, footer, lang) {
    const marquee = document.getElementById('my_marquee');
    if (!marquee || !Array.isArray(lines)) {
      return;
    }

    let html = '';
    lines.forEach(function(line) {
      html += '<div>' + line + '</div>';
    });
    html += '<div>' + footer + '</div>';
    marquee.innerHTML = html;

    if (lang) {
      setCurrentPageLanguage(lang);
    }
  }

  function syncSessionLanguage(selectedLanguage) {
    return fetch('include/set-session-lang.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'lang=' + encodeURIComponent(selectedLanguage)
    })
      .then(function(response) {
        return response.json();
      })
      .then(function(data) {
        if (data.status === 'success') {
          updateMarqueeContent(data.lines, data.footer, data.lang);
        }
        return data;
      })
      .catch(function(error) {
        // Session touch is best-effort; ignore failures silently.
      });
  }

  function setLanguagePreference() {
    const languageSelect = document.getElementById('languageSelect');
    if (!languageSelect) {
      return;
    }
    const storedLanguage = localStorage.getItem('language');
    const pageLanguage = getCurrentPageLanguage();

    if (storedLanguage) {
      languageSelect.value = storedLanguage;
      if (storedLanguage !== pageLanguage) {
        syncSessionLanguage(storedLanguage);
      }
    } else {
      localStorage.setItem('language', pageLanguage);
      languageSelect.value = pageLanguage;
    }
  }
  
  function handleLanguageChange() {
    const languageSelect = document.getElementById('languageSelect');
    const selectedLanguage = languageSelect.value;
  
    localStorage.setItem('language', selectedLanguage);
    syncSessionLanguage(selectedLanguage);
    if (typeof applyPageTranslations === 'function') {
      applyPageTranslations(selectedLanguage);
    }
  }
  
  document.addEventListener('DOMContentLoaded', function() {
    const languageSelect = document.getElementById('languageSelect');
    if (!languageSelect) {
      return;
    }

    languageSelect.addEventListener('change', function() {
      handleLanguageChange();
    });
  
    setLanguagePreference();
  });
