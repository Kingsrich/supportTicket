<header class="topbar">
    <!-- MOBILE MENU BUTTON -->
    <div id="menuToggle">☰</div>

    <div class="logo">Venditium</div>

    <style>
        .global-search-wrap {
            position: relative;
        }

        #globalSearchInput {
            width: 260px;
            padding: 9px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            outline: none;
        }

        .global-search-results {
            display: none;
            position: absolute;
            top: 42px;
            right: 0;
            width: 380px;
            max-height: 420px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .14);
            z-index: 9999;
        }

        .global-search-item {
            display: block;
            padding: 11px 14px;
            text-decoration: none;
            color: #111827;
            border-bottom: 1px solid #f1f5f9;
        }

        .global-search-item:hover {
            background: #f8fafc;
        }

        .global-search-title {
            font-size: 13px;
            font-weight: 700;
        }

        .global-search-meta {
            margin-top: 3px;
            font-size: 11px;
            color: #64748b;
        }

        .global-search-empty {
            padding: 14px;
            font-size: 13px;
            color: #64748b;
        }
    </style>

    <div class="topbar-right">

        <div class="global-search-wrap">
            <input type="text" id="globalSearchInput" placeholder="Global Search..." autocomplete="off" />
            <div id="globalSearchResults" class="global-search-results"></div>
        </div>

        <span class="user">
            <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
        </span>

        <div class="nav-links">
            <a class="nav-login" href="logout.php">Logout</a>
        </div>

    </div>

</header>

<script>
    const globalInput = document.getElementById('globalSearchInput');
    const globalResults = document.getElementById('globalSearchResults');

    let globalSearchTimer = null;
    let globalSearchRequestId = 0;

    globalInput.addEventListener('input', function () {
        const q = this.value.trim();

        clearTimeout(globalSearchTimer);

        if (q.length < 2) {
            globalResults.style.display = 'none';
            globalResults.innerHTML = '';
            return;
        }

        const requestId = ++globalSearchRequestId;

        globalSearchTimer = setTimeout(() => {
            fetch('./ajax_global_search.php?q=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(data => {
                    if (requestId !== globalSearchRequestId) return;

                    globalResults.innerHTML = '';

                    if (!data.success || !data.results.length) {
                        globalResults.innerHTML =
                            '<div class="global-search-empty">No results found</div>';
                        globalResults.style.display = 'block';
                        return;
                    }

                    data.results.forEach(item => {
                        const a = document.createElement('a');
                        a.href = item.url;
                        a.className = 'global-search-item';

                        a.innerHTML = `
                        <div class="global-search-title">${item.title}</div>
                        <div class="global-search-meta">${item.type} • ${item.subtitle || ''}</div>
                    `;

                        globalResults.appendChild(a);
                    });

                    globalResults.style.display = 'block';
                })
                .catch(err => {
                    console.error('GLOBAL SEARCH ERROR:', err);
                    globalResults.innerHTML = '<div class="global-search-empty">Search error</div>';
                    globalResults.style.display = 'block';
                });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.global-search-wrap')) {
            globalResults.style.display = 'none';
        }
    });
</script>