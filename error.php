<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error — Image Resizer</title>
    <script>
    (function(){
        var t=localStorage.getItem('imgr_theme'),sys=window.matchMedia('(prefers-color-scheme: dark)').matches;
        if(t==='light'||(!t&&!sys)){document.documentElement.classList.remove('dark');}
        else{document.documentElement.classList.add('dark');}
    })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
        }
    </script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }

        /* Dark background */
        .bg-dark {
            background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(99,102,241,0.18) 0%, transparent 65%), #020617;
        }
        /* Light background */
        .bg-light {
            background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(99,102,241,0.12) 0%, transparent 65%), #f1f5f9;
        }

        /* Theme icon visibility */
        .icon-sun { display: block; }
        .icon-moon { display: none; }
        html.dark .icon-sun { display: none; }
        html.dark .icon-moon { display: block; }

        /* Smooth theme transitions */
        body.transitioning * {
            transition: background-color 0.25s ease, border-color 0.25s ease, color 0.2s ease, box-shadow 0.25s ease !important;
        }
    </style>
</head>
<body class="bg-light dark:bg-dark min-h-screen flex items-center justify-center px-4">

    <!-- Theme Toggle -->
    <button id="themeToggle" onclick="toggleTheme()"
            title="Toggle light/dark mode"
            class="fixed top-4 right-4 z-50 w-10 h-10 rounded-2xl flex items-center justify-center
                   bg-white/80 dark:bg-slate-800/80 backdrop-blur
                   border border-slate-200 dark:border-slate-700/60
                   text-slate-500 dark:text-slate-400
                   hover:text-slate-700 dark:hover:text-slate-200
                   hover:bg-white dark:hover:bg-slate-700/80
                   shadow-sm transition-all duration-200 hover:scale-110 active:scale-95">
        <!-- Sun icon (shown in dark mode) -->
        <svg class="icon-moon w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/>
        </svg>
        <!-- Moon icon (shown in light mode) -->
        <svg class="icon-sun w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/>
        </svg>
    </button>

    <div class="w-full max-w-md text-center">
        <div class="bg-white/90 dark:bg-slate-900/60 backdrop-blur-2xl border border-slate-200 dark:border-slate-700/40 rounded-3xl shadow-2xl shadow-black/10 dark:shadow-black/60 p-8 sm:p-10">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-red-500/10 border border-red-500/20 mb-6">
                <svg class="w-8 h-8 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-800 dark:text-slate-100 mb-3">Something went wrong</h1>
            <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-8">
                <?php echo htmlspecialchars($_GET['error'] ?? 'An unknown error occurred.', ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <button onclick="window.location.href = '/';"
                    class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl font-semibold text-sm text-white
                           bg-gradient-to-r from-indigo-600 to-violet-600
                           hover:from-indigo-500 hover:to-violet-500
                           shadow-lg shadow-indigo-950/60
                           transition-all duration-200 hover:scale-[1.03] active:scale-100">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                </svg>
                Back to Resizer
            </button>
        </div>
    </div>

    <script>
    function toggleTheme() {
        var isDark = document.documentElement.classList.contains('dark');
        document.body.classList.add('transitioning');
        if (isDark) {
            document.documentElement.classList.remove('dark');
            localStorage.setItem('imgr_theme', 'light');
        } else {
            document.documentElement.classList.add('dark');
            localStorage.setItem('imgr_theme', 'dark');
        }
        setTimeout(function(){ document.body.classList.remove('transitioning'); }, 300);
    }
    </script>
</body>
</html>
