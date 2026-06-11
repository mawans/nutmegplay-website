<!doctype html>
<html class="scroll-smooth" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>NutmegPlay | Elevate Your Game with AI</title>
    <meta
      content="NutmegPlay - The ultimate AI-powered football platform for player tracking, matchmaking, and performance analysis."
      name="description"
    />
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect" />
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect" />
    <link
      href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap"
      rel="stylesheet"
    />
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              primary: "#1DB954",
              dark: "#0A0A0A",
              card: "#121212",
              muted: "#B3B3B3",
            },
            fontFamily: {
              sans: ["Lexend", "sans-serif"],
            },
          },
        },
      };
    </script>
    <style data-purpose="custom-animations">
      @keyframes fadeInUp {
        from {
          opacity: 0;
          transform: translateY(30px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }
      .animate-fade-in-up {
        animation: fadeInUp 0.8s ease-out forwards;
      }
      .reveal {
        opacity: 0;
        transform: translateY(30px);
        transition: all 0.8s ease-out;
      }
      .reveal.active {
        opacity: 1;
        transform: translateY(0);
      }
      .glass {
        background: rgba(255, 255, 255, 0.03);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.05);
      }
      .hero-gradient {
        background: radial-gradient(
          circle at center,
          rgba(29, 185, 84, 0.15) 0%,
          rgba(10, 10, 10, 1) 70%
        );
      }
      /* Mobile menu overlay */
      .mobile-menu {
        transform: translateX(100%);
        transition: transform 0.3s ease-in-out;
      }
      .mobile-menu.open {
        transform: translateX(0);
      }
    </style>
  </head>
  <body
    class="bg-dark text-white font-sans selection:bg-primary selection:text-dark overflow-x-hidden"
  >
    <!-- BEGIN: Navigation -->
    <nav class="fixed top-0 w-full z-50 glass py-4">
      <div class="container mx-auto px-6 flex justify-between items-center">
        <a href="/" class="flex items-center gap-2" data-purpose="logo">
          <div
            class="w-8 h-8 bg-primary rounded-full flex items-center justify-center"
          >
            <svg
              class="w-5 h-5 text-dark"
              fill="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"
              ></path>
            </svg>
          </div>
          <span class="text-xl font-extrabold tracking-tight"
            >Nutmeg<span class="text-primary">Play</span></span
          >
        </a>
        <div class="hidden md:flex items-center gap-8 text-sm font-medium">
          <a class="hover:text-primary transition-colors" href="#features">Features</a>
          <a class="hover:text-primary transition-colors" href="#teams">Teams</a>
          <a class="hover:text-primary transition-colors" href="#players">Players</a>
          <?php if (!empty($isLoggedIn)): ?>
            <a
              class="px-6 py-2 bg-primary text-dark rounded-full font-bold hover:scale-105 transition-transform"
              href="/dashboard"
            >Dashboard</a>
          <?php else: ?>
            <a
              class="px-5 py-2 border border-white/20 rounded-full font-bold hover:bg-white/5 transition-all"
              href="/login"
            >Log In</a>
            <a
              class="px-6 py-2 bg-primary text-dark rounded-full font-bold hover:scale-105 transition-transform"
              href="/register"
            >Sign Up</a>
          <?php endif; ?>
        </div>
        <button id="mobileMenuBtn" class="md:hidden text-white" aria-label="Toggle menu">
          <svg
            class="w-6 h-6"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              d="M4 6h16M4 12h16m-7 6h7"
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
            ></path>
          </svg>
        </button>
      </div>
    </nav>

    <!-- Mobile Menu -->
    <div id="mobileMenu" class="mobile-menu fixed top-0 right-0 w-64 h-full z-[60] bg-dark/95 backdrop-blur-lg border-l border-white/10 p-8 flex flex-col gap-6">
      <button id="mobileMenuClose" class="self-end text-white mb-4" aria-label="Close menu">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
        </svg>
      </button>
      <a class="text-lg font-medium hover:text-primary transition-colors" href="#features">Features</a>
      <a class="text-lg font-medium hover:text-primary transition-colors" href="#teams">Teams</a>
      <a class="text-lg font-medium hover:text-primary transition-colors" href="#players">Players</a>
      <hr class="border-white/10">
      <?php if (!empty($isLoggedIn)): ?>
        <a class="px-6 py-3 bg-primary text-dark rounded-full font-bold text-center hover:scale-105 transition-transform" href="/dashboard">Dashboard</a>
      <?php else: ?>
        <a class="px-6 py-3 border border-white/20 rounded-full font-bold text-center hover:bg-white/5 transition-all" href="/login">Log In</a>
        <a class="px-6 py-3 bg-primary text-dark rounded-full font-bold text-center hover:scale-105 transition-transform" href="/register">Sign Up</a>
      <?php endif; ?>
    </div>
    <div id="mobileMenuOverlay" class="fixed inset-0 bg-black/50 z-[55] hidden"></div>
    <!-- END: Navigation -->

    <!-- BEGIN: Hero Section -->
    <section
      class="relative min-h-screen flex items-center pt-20 overflow-hidden hero-gradient"
    >
      <div
        class="container mx-auto px-6 grid md:grid-cols-2 gap-12 items-center relative z-10"
      >
        <div class="animate-fade-in-up">
          <span
            class="inline-block px-4 py-1 rounded-full border border-primary/30 text-primary text-xs font-bold uppercase tracking-widest mb-6 bg-primary/10"
          >
            The Future of Amateur Football
          </span>
          <h1 class="text-5xl md:text-7xl font-extrabold leading-tight mb-6">
            Elevate Your <span class="text-primary">Game</span> With AI.
          </h1>
          <p class="text-muted text-lg md:text-xl mb-10 max-w-lg">
            Track every sprint, goal, and assist. NutmegPlay brings
            professional-grade performance analytics to every local pitch.
          </p>
          <div class="flex flex-wrap gap-4">
            <a
              href="/register"
              class="px-8 py-4 bg-primary text-dark rounded-full font-bold text-lg hover:shadow-[0_0_20px_rgba(29,185,84,0.4)] transition-all inline-block"
            >
              Get Started
            </a>
            <a
              href="#features"
              class="px-8 py-4 border border-white/20 rounded-full font-bold text-lg hover:bg-white/5 transition-all inline-block"
            >
              Learn More
            </a>
          </div>
        </div>
        <div class="relative animate-fade-in-up" style="animation-delay: 0.2s">
          <div
            class="relative rounded-2xl overflow-hidden shadow-2xl border border-white/10 group"
          >
            <img
              alt="Football Player in Action"
              class="w-full h-auto object-cover transition-transform duration-700 group-hover:scale-110"
              src="https://lh3.googleusercontent.com/aida-public/AB6AXuD8GYTGtRUwbDv7bIfDVmYh_7mQ3q2mEZh_LZIzRZNCmIh3oc-P02Ie39G0zI1pChvfqRjTnhzJoD2A6VK4FpTUNe-8SvlkTJbAmzO4LX_MIKsknAqcAZYHpg4GoNjiCqcgGxY_ymYcvPQwHHzpwNfHvMU2F5nlOd_LeP1EtxwZSfBFdwcZN7KygmjaMiPCz8inNvjJaIac3vi0KG2_Jag15vcRGpLlf9hCvQDasP2I1ecJVpBEOuwT-DeuZcCcVfhkpYmz0EBgcs0"
            />
            <div
              class="absolute inset-0 bg-gradient-to-t from-dark via-transparent to-transparent"
            ></div>
            <div
              class="absolute bottom-8 left-8 right-8 glass p-6 rounded-xl animate-bounce"
              style="animation-duration: 4s"
            >
              <div class="flex items-center justify-between mb-4">
                <span class="font-bold">Match Live</span>
                <span class="text-primary text-sm flex items-center gap-2">
                  <span class="w-2 h-2 bg-primary rounded-full animate-pulse"></span>
                  AI Tracking On
                </span>
              </div>
              <div class="flex gap-4">
                <div class="bg-white/5 p-3 rounded-lg flex-1">
                  <div class="text-[10px] text-muted uppercase">Top Speed</div>
                  <div class="text-xl font-bold">32.4 km/h</div>
                </div>
                <div class="bg-white/5 p-3 rounded-lg flex-1">
                  <div class="text-[10px] text-muted uppercase">Heat Map</div>
                  <div class="text-xl font-bold">Active</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <!-- END: Hero Section -->

    <!-- BEGIN: Feature Sections -->
    <section class="py-24 bg-dark" id="features">
      <div class="container mx-auto px-6">
        <!-- Feature 1: Matchmaking Lobby -->
        <div class="reveal grid md:grid-cols-2 gap-16 items-center mb-32">
          <div class="order-2 md:order-1 relative">
            <img
              alt="Matchmaking Lobby"
              class="rounded-2xl shadow-2xl border border-white/10"
              src="https://lh3.googleusercontent.com/aida-public/AB6AXuBjN3Z368cIxaA2w5INT4cbO5k3vBf_HtwglkgddnUc7cWHkRdTedFx5_VZzZ3pAZ0hzfpwkX1QuJLmevMeHWDUhz1D63uczDR8VC5etyYXSxFj_KsGGerNx1QmEAE6ZjaEoakUy3l2YJhssLdpWhJ0MAJahfn1dXSUswDMRX3ud5HCCtBf2vS083VKWYjTPmzgwUCUXHAu2_CpbRJ6LGS6kpzLTOTXaohly9Q0BFh8rw2DQqdhYHDL5e_P5qYqRoqDAiIRkw3FTUw"
            />
            <div
              class="absolute -bottom-6 -right-6 glass p-4 rounded-xl hidden md:block max-w-[200px]"
            >
              <p class="text-xs text-muted mb-2">Squad Status</p>
              <div class="flex items-center gap-2">
                <div class="w-10 h-1 bg-primary rounded-full"></div>
                <span class="font-bold">5/5 Ready</span>
              </div>
            </div>
          </div>
          <div class="order-1 md:order-2">
            <h2 class="text-3xl md:text-5xl font-bold mb-6">
              Find Your Perfect <span class="text-primary">Opponent</span>
            </h2>
            <p class="text-muted text-lg mb-8">
              Our smart matchmaking engine connects you with players and teams
              of similar skill levels. Whether it's a friendly kickabout or a
              ranked tournament, we've got you covered.
            </p>
            <ul class="space-y-4">
              <li class="flex items-center gap-3">
                <svg class="w-5 h-5 text-primary" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"></path>
                </svg>
                Skill-based ranking system
              </li>
              <li class="flex items-center gap-3">
                <svg class="w-5 h-5 text-primary" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"></path>
                </svg>
                Real-time squad coordination
              </li>
              <li class="flex items-center gap-3">
                <svg class="w-5 h-5 text-primary" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"></path>
                </svg>
                Automated lobby management
              </li>
            </ul>
          </div>
        </div>

        <!-- Feature 2: Player Dashboard -->
        <div class="reveal grid md:grid-cols-2 gap-16 items-center mb-32" id="players">
          <div>
            <h2 class="text-3xl md:text-5xl font-bold mb-6">
              Track Progression &amp;
              <span class="text-primary">Share Stats</span>
            </h2>
            <p class="text-muted text-lg mb-8">
              Visualize your career growth with interactive dashboards. Generate
              your custom FIFA-style season card based on real-world performance
              data.
            </p>
            <div class="grid grid-cols-2 gap-4">
              <div class="bg-card p-4 rounded-xl border border-white/5">
                <div class="text-primary text-2xl font-bold mb-1">24</div>
                <div class="text-xs text-muted uppercase tracking-wider">Matches Played</div>
              </div>
              <div class="bg-card p-4 rounded-xl border border-white/5">
                <div class="text-primary text-2xl font-bold mb-1">18</div>
                <div class="text-xs text-muted uppercase tracking-wider">Goals Scored</div>
              </div>
            </div>
          </div>
          <div class="relative flex justify-center">
            <div
              class="w-full max-w-sm bg-gradient-to-br from-[#FFD700] to-[#B8860B] p-1 rounded-[2rem] shadow-2xl shadow-primary/20 rotate-3 hover:rotate-0 transition-transform duration-500"
            >
              <div class="bg-[#1a1a1a] rounded-[1.9rem] p-6 text-center">
                <div class="w-48 h-48 mx-auto mb-4 overflow-hidden rounded-xl">
                  <img
                    alt="Player Avatar"
                    src="https://lh3.googleusercontent.com/aida-public/AB6AXuAoBaN-3AbtY_szyvNgYzUHPhWdPYKF04FaphdV8SLp3rlxCDOAQ7fYpabC8LeM5yoIZQJqXIV9njjHArd1cEB0fpsrQXcqaEf2u0h5-FA3f47QdQ17ZMVFDQlS6fSaZ-c44BIUNLNp89MhbYn-AOuZFE9Duvh0Cl80qR8F8JE_TbgWjk2iQX1Ov_1IzNIFNjXTiKujVUDiwxe-_XKxBsrzvwVE695RvJAcfA80ZWIf-oQRja273JyX-AN49v4p5kSnAsG6qZlcJug"
                  />
                </div>
                <h3 class="text-2xl font-black italic uppercase tracking-tighter">HUNTER</h3>
                <div class="grid grid-cols-2 gap-x-8 gap-y-2 mt-4 text-left px-4">
                  <div class="flex justify-between border-b border-white/10 pb-1">
                    <span class="text-muted font-bold text-xs">PAC</span>
                    <span class="font-black text-primary">90</span>
                  </div>
                  <div class="flex justify-between border-b border-white/10 pb-1">
                    <span class="text-muted font-bold text-xs">DRI</span>
                    <span class="font-black text-primary">88</span>
                  </div>
                  <div class="flex justify-between border-b border-white/10 pb-1">
                    <span class="text-muted font-bold text-xs">SHO</span>
                    <span class="font-black text-primary">86</span>
                  </div>
                  <div class="flex justify-between border-b border-white/10 pb-1">
                    <span class="text-muted font-bold text-xs">DEF</span>
                    <span class="font-black text-yellow-500">45</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Feature 3: Video Command Center -->
        <div class="reveal grid md:grid-cols-2 gap-16 items-center mb-32">
          <div class="order-2 md:order-1 bg-card p-6 rounded-2xl border border-white/5">
            <div class="flex items-center justify-between mb-6">
              <div class="flex gap-2">
                <span class="w-3 h-3 bg-red-500 rounded-full animate-pulse"></span>
                <span class="text-sm font-bold uppercase tracking-wider">Live Analysis</span>
              </div>
              <span class="text-xs text-muted">Cam A1: Online</span>
            </div>
            <img
              alt="Video Analysis"
              class="rounded-lg mb-4"
              src="https://lh3.googleusercontent.com/aida-public/AB6AXuCoivG02ww0FBoHhRwmNzlZq2-1wWI8KB1s34eHELfPMjdp0HWivyuL5y8MKi3ISphvPPKhk807LUYrXYuPRPxxR76q_O0_PzldVvv01V5zLy_lfEX1maDW1q2KZj39tBaQN4xfVXDAB-o0kvwUTLT_TkIjlBm1DkmGvIb1yyXMe6ZlN4qQ9wBWbn13RqYQFODVCx9RtV4t9s0g3CQmqaFU1ehLLzd3YGJDeFaDZgB8J0tOlA6BtyBlPWhSbVvXkz1KHZbMUteIbFA"
            />
            <div class="space-y-3">
              <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg border-l-4 border-primary">
                <div>
                  <p class="text-sm font-bold">Goal Detected</p>
                  <p class="text-[10px] text-muted">Confidence: 98%</p>
                </div>
                <span class="text-primary text-xs font-bold">14:22</span>
              </div>
              <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg border-l-4 border-yellow-500 opacity-60">
                <div>
                  <p class="text-sm font-bold">Possible Foul</p>
                  <p class="text-[10px] text-muted">Confidence: 85%</p>
                </div>
                <span class="text-yellow-500 text-xs font-bold">22:05</span>
              </div>
            </div>
          </div>
          <div class="order-1 md:order-2">
            <h2 class="text-3xl md:text-5xl font-bold mb-6">
              AI-Powered <span class="text-primary">Highlights</span>
            </h2>
            <p class="text-muted text-lg mb-8">
              Don't waste time scrubbing through hours of footage. Our AI
              automatically detects goals, assists, and key saves, creating
              instant highlight reels for you to share.
            </p>
            <div class="flex gap-4">
              <div class="p-4 bg-primary/10 rounded-xl flex-1 text-center">
                <p class="text-primary font-bold text-xl">Auto</p>
                <p class="text-[10px] text-muted uppercase">Clipping</p>
              </div>
              <div class="p-4 bg-primary/10 rounded-xl flex-1 text-center">
                <p class="text-primary font-bold text-xl">4K</p>
                <p class="text-[10px] text-muted uppercase">Resolution</p>
              </div>
              <div class="p-4 bg-primary/10 rounded-xl flex-1 text-center">
                <p class="text-primary font-bold text-xl">PRO</p>
                <p class="text-[10px] text-muted uppercase">Analysis</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Feature 4: Challenges & Rewards -->
        <div class="reveal glass rounded-[2.5rem] p-12 overflow-hidden relative" id="teams">
          <div class="absolute -top-24 -right-24 w-64 h-64 bg-primary/20 blur-[100px] rounded-full"></div>
          <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-primary/10 blur-[100px] rounded-full"></div>
          <div class="grid md:grid-cols-2 gap-12 items-center relative z-10">
            <div>
              <span class="px-3 py-1 bg-primary text-dark font-bold text-[10px] rounded-full uppercase mb-4 inline-block">Weekly Quest</span>
              <h2 class="text-4xl md:text-6xl font-black mb-6 italic uppercase tracking-tighter">
                Win Weekly <span class="text-primary">Challenges</span>
              </h2>
              <p class="text-muted text-lg mb-8">
                "Sprinter of the Week": Hit a top speed of 32km/h in a single
                session to unlock the Speedster Badge and earn +500 XP.
              </p>
              <a
                href="/register"
                class="bg-primary text-dark px-10 py-4 rounded-full font-black uppercase text-lg hover:scale-105 transition-transform flex items-center gap-2 w-fit"
              >
                Start Challenge
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path d="M17 8l4 4m0 0l-4 4m4-4H3" stroke-linecap="round" stroke-linejoin="round" stroke-width="3"></path>
                </svg>
              </a>
            </div>
            <div class="flex flex-col gap-4">
              <div class="bg-dark/50 p-6 rounded-2xl border border-white/5 space-y-4">
                <h4 class="font-bold text-sm uppercase tracking-widest flex items-center justify-between">
                  Live Leaderboard
                  <span class="text-primary text-[10px]">Speed (KM/H)</span>
                </h4>
                <div class="flex items-center justify-between bg-primary/5 p-3 rounded-lg border border-primary/20">
                  <div class="flex items-center gap-4">
                    <span class="font-bold text-primary italic">01</span>
                    <img alt="User" class="w-8 h-8 rounded-full border border-primary"
                      src="https://lh3.googleusercontent.com/aida-public/AB6AXuAGl7QlYBADXcwqdM0Jm9KL0vVOQ3SSuYy8s6S1GJnzXEaY7Q88agn6XMWs7Nx3pb42bdsbeTwkX8L8So0prX_ujaz7LEAtdgWDh8PCAaGIQCFLbgj7OakT1ZA6Fx68OXhCVLPZ9kwaIVoaNJQUPpctu1iPrxY6XAZNsCPm79SsjfzaD6dYmTrQAIAIBOlqWB_dSspm8a6oAJJi6vh98DauR5xSIzYyVGPaditN6YhfMpW8zIoI0b50R_zis3GGlN8IVZEhI5jawEU" />
                    <span class="font-medium text-sm">Marcus R.</span>
                  </div>
                  <span class="font-bold">36.4</span>
                </div>
                <div class="flex items-center justify-between bg-white/5 p-3 rounded-lg">
                  <div class="flex items-center gap-4">
                    <span class="font-bold text-muted italic">02</span>
                    <img alt="User" class="w-8 h-8 rounded-full"
                      src="https://lh3.googleusercontent.com/aida-public/AB6AXuDdrxqtgV3A8FapLSymrAtmzVMBqs1odgYjurmcyAwV1SgXiTjtrBEi-gVDFWskNuROmZEuiEWLdCFV62mmTXaJsg-vzjY_MFge7J2uw8kXukoPM3gfkq1csXzFtUI4PbwTUvDarUcf_6pbXMq47WRpyzonjz6zMUhQi4a5hJP6hGfUxfD3SO3HYQUNQRu_OAN5jMR0YfqTo6hIkPKThyYAwroywfwfUBhf5BjPhtxCGB20D8Qaq6uK6-coAq1sQTuZqKqeTXMK15g" />
                    <span class="font-medium text-sm">Sarah K.</span>
                  </div>
                  <span class="font-bold">35.8</span>
                </div>
                <div class="flex items-center justify-between bg-white/5 p-3 rounded-lg">
                  <div class="flex items-center gap-4">
                    <span class="font-bold text-muted italic">03</span>
                    <img alt="User" class="w-8 h-8 rounded-full"
                      src="https://lh3.googleusercontent.com/aida-public/AB6AXuCxCnaNZhvXaRicoiuWttNdTIBQOugMa_NcLNwG6LETRZoBnqd-3Vc9xLIDO7Wk0VlwOIfw02aWTBaK6zzh_NOAbXW1if1Rjz_gtYjpG8CzRAlqxGV8lVqtSnslq9e7UJbvRCmd6EbTRsM0-txJrjgLFf3l8M6nhu280Sj9E2zZ8AqFuqx7hVc3-YjZWYKnEdffXQlpzDu_NR9fS65eV3DxAjwbD6UJFU6O_SBgtfTVAh0NIBv7uqSHQvK-QcHqa_d3fC2EY_7U9Ps" />
                    <span class="font-medium text-sm">David B.</span>
                  </div>
                  <span class="font-bold">35.2</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <!-- END: Feature Sections -->

    <!-- BEGIN: CTA Section -->
    <section class="py-24 border-t border-white/10">
      <div class="container mx-auto px-6 text-center">
        <h2 class="text-4xl md:text-6xl font-black mb-10">
          Ready to dominate the
          <span class="text-primary underline decoration-primary/30 underline-offset-8">pitch</span>?
        </h2>
        <p class="text-muted text-xl mb-12 max-w-2xl mx-auto">
          Join over 100,000 players who are already using NutmegPlay to improve
          their game, find matches, and build their football legacy.
        </p>
        <div class="flex flex-col sm:flex-row justify-center gap-4">
          <a
            href="/register"
            class="px-10 py-4 bg-primary text-dark rounded-full font-bold text-lg hover:shadow-[0_0_20px_rgba(29,185,84,0.4)] transition-all inline-block"
          >
            Create Free Account
          </a>
          <a
            href="/login"
            class="px-10 py-4 border border-white/20 rounded-full font-bold text-lg hover:bg-white/5 transition-all inline-block"
          >
            Sign In
          </a>
        </div>
      </div>
    </section>
    <!-- END: CTA Section -->

    <!-- BEGIN: Footer -->
    <footer class="py-12 bg-dark border-t border-white/5">
      <div class="container mx-auto px-6 grid md:grid-cols-4 gap-12">
        <div class="col-span-2">
          <a href="/" class="flex items-center gap-2 mb-6" data-purpose="logo">
            <div class="w-6 h-6 bg-primary rounded-full flex items-center justify-center">
              <svg class="w-4 h-4 text-dark" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"></path>
              </svg>
            </div>
            <span class="text-lg font-bold tracking-tight">Nutmeg<span class="text-primary">Play</span></span>
          </a>
          <p class="text-muted text-sm max-w-xs leading-relaxed">
            The ultimate platform for the modern footballer. Empowering players
            with elite-level data and community connections.
          </p>
        </div>
        <div>
          <h5 class="font-bold mb-6 text-sm uppercase tracking-widest">Platform</h5>
          <ul class="space-y-4 text-muted text-sm">
            <li><a class="hover:text-primary transition-colors" href="/matchmaking">Matchmaking</a></li>
            <li><a class="hover:text-primary transition-colors" href="/players">Player Analytics</a></li>
            <li><a class="hover:text-primary transition-colors" href="/teams">Team Manager</a></li>
            <li><a class="hover:text-primary transition-colors" href="/video-upload">Video Highlights</a></li>
          </ul>
        </div>
        <div>
          <h5 class="font-bold mb-6 text-sm uppercase tracking-widest">Company</h5>
          <ul class="space-y-4 text-muted text-sm">
            <li><a class="hover:text-primary transition-colors" href="#">About Us</a></li>
            <li><a class="hover:text-primary transition-colors" href="#">Privacy Policy</a></li>
            <li><a class="hover:text-primary transition-colors" href="#">Terms of Service</a></li>
            <li><a class="hover:text-primary transition-colors" href="#">Contact</a></li>
          </ul>
        </div>
      </div>
      <div class="container mx-auto px-6 mt-12 pt-8 border-t border-white/5 flex flex-col md:flex-row justify-between items-center gap-6">
        <p class="text-xs text-muted/50">&copy; <?= date('Y') ?> NutmegPlay. All rights reserved.</p>
        <div class="flex gap-6">
          <a class="text-muted/50 hover:text-white transition-colors" href="#">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <path d="M24 4.557c-.883.392-1.832.656-2.828.775 1.017-.609 1.798-1.574 2.165-2.724-.951.564-2.005.974-3.127 1.195-.897-.957-2.178-1.555-3.594-1.555-3.179 0-5.515 2.966-4.797 6.045-4.091-.205-7.719-2.165-10.148-5.144-1.29 2.213-.669 5.108 1.523 6.574-.806-.026-1.566-.247-2.229-.616-.054 2.281 1.581 4.415 3.949 4.89-.693.188-1.452.232-2.224.084.626 1.956 2.444 3.379 4.6 3.419-2.07 1.623-4.678 2.348-7.29 2.04 2.179 1.397 4.768 2.212 7.548 2.212 9.142 0 14.307-7.721 13.995-14.646.962-.695 1.797-1.562 2.457-2.549z"></path>
            </svg>
          </a>
          <a class="text-muted/50 hover:text-white transition-colors" href="#">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"></path>
            </svg>
          </a>
        </div>
      </div>
    </footer>
    <!-- END: Footer -->

    <!-- BEGIN: Scripts -->
    <script data-purpose="scroll-reveal-and-mobile-menu">
      // Scroll reveal
      function reveal() {
        const reveals = document.querySelectorAll(".reveal");
        for (let i = 0; i < reveals.length; i++) {
          const windowHeight = window.innerHeight;
          const elementTop = reveals[i].getBoundingClientRect().top;
          const elementVisible = 150;
          if (elementTop < windowHeight - elementVisible) {
            reveals[i].classList.add("active");
          }
        }
      }
      window.addEventListener("scroll", reveal);
      reveal();

      // Mobile menu toggle
      const mobileMenuBtn = document.getElementById("mobileMenuBtn");
      const mobileMenuClose = document.getElementById("mobileMenuClose");
      const mobileMenu = document.getElementById("mobileMenu");
      const mobileMenuOverlay = document.getElementById("mobileMenuOverlay");

      function openMenu() {
        mobileMenu.classList.add("open");
        mobileMenuOverlay.classList.remove("hidden");
        document.body.style.overflow = "hidden";
      }
      function closeMenu() {
        mobileMenu.classList.remove("open");
        mobileMenuOverlay.classList.add("hidden");
        document.body.style.overflow = "";
      }

      mobileMenuBtn.addEventListener("click", openMenu);
      mobileMenuClose.addEventListener("click", closeMenu);
      mobileMenuOverlay.addEventListener("click", closeMenu);

      // Close mobile menu when clicking nav links
      mobileMenu.querySelectorAll("a").forEach(function(link) {
        link.addEventListener("click", closeMenu);
      });
    </script>
    <!-- END: Scripts -->
  </body>
</html>
