/**
 * HTMX Integration Script
 *
 * Uses hx-boost for transparent SPA-like navigation.
 * All <a> and <form> elements inside the <body hx-boost="true">
 * automatically use HTMX for navigation while preserving
 * browser history (back/forward buttons).
 *
 * Key Features:
 * - Dynamic navigation without full page reloads (via hx-boost)
 * - Browser history support (back/forward buttons)
 * - Progressive enhancement (works without JS via standard href)
 * - Active nav highlighting on page transitions
 */

let pendingNavLink = null;
let pendingNavTimeout = null;
let navProgressTimer = null;
let navProgressValue = 0;

function isNavigableLink(link) {
  if (!(link instanceof HTMLAnchorElement)) {
    return false;
  }

  const href = link.getAttribute("href") || "";
  if (!href || href.startsWith("#")) return false;
  if (href.startsWith("javascript:")) return false;
  if (href.startsWith("mailto:") || href.startsWith("tel:")) return false;
  if (link.target === "_blank" || link.hasAttribute("download")) return false;

  try {
    const url = new URL(link.href, window.location.href);
    return url.origin === window.location.origin;
  } catch (_error) {
    return false;
  }
}

function clearPendingNavLink() {
  if (pendingNavLink) {
    pendingNavLink.removeAttribute("data-nav-pending");
    pendingNavLink = null;
  }
}

function setNavigationProgress(value) {
  const progress = document.getElementById("navigation-progress");
  const bar = document.getElementById("navigation-progress-bar");

  if (progress) {
    progress.setAttribute("data-active", value > 0 && value < 1 ? "true" : (value >= 1 ? "true" : "false"));
  }
  if (bar) {
    bar.style.transform = `scaleX(${Math.max(0, Math.min(1, value))})`;
  }
}

function setNavigationLoading(active) {
  const progress = document.getElementById("navigation-progress");

  if (progress) {
    progress.setAttribute("data-active", active ? "true" : "false");
  }

  if (active) {
    document.body.setAttribute("data-nav-loading", "true");
  } else {
    document.body.removeAttribute("data-nav-loading");
  }
}

function startNavigationFeedback(link) {
  if (pendingNavTimeout) {
    window.clearTimeout(pendingNavTimeout);
    pendingNavTimeout = null;
  }
  if (navProgressTimer) {
    window.clearInterval(navProgressTimer);
    navProgressTimer = null;
  }

  clearPendingNavLink();

  if (link instanceof Element && link.matches("#sidebar nav a.nav-link, #topnav a")) {
    pendingNavLink = link;
    pendingNavLink.setAttribute("data-nav-pending", "true");
  }

  navProgressValue = 0.12;
  setNavigationLoading(true);
  setNavigationProgress(navProgressValue);

  navProgressTimer = window.setInterval(function () {
    navProgressValue = Math.min(0.9, navProgressValue + (1 - navProgressValue) * 0.14);
    setNavigationProgress(navProgressValue);
  }, 140);
}

function stopNavigationFeedback() {
  if (navProgressTimer) {
    window.clearInterval(navProgressTimer);
    navProgressTimer = null;
  }
  if (pendingNavTimeout) {
    window.clearTimeout(pendingNavTimeout);
  }

  setNavigationProgress(1);

  pendingNavTimeout = window.setTimeout(function () {
    clearPendingNavLink();
    setNavigationProgress(0);
    setNavigationLoading(false);
  }, 180);
}

// Scroll to top after HTMX page swap
document.addEventListener("htmx:afterSwap", function (evt) {
  window.scrollTo(0, 0);
  stopNavigationFeedback();
});

document.addEventListener("htmx:beforeRequest", function (evt) {
  const trigger = evt.detail && evt.detail.requestConfig
    ? evt.detail.requestConfig.elt
    : null;
  startNavigationFeedback(trigger);
});

document.addEventListener("htmx:responseError", function () {
  stopNavigationFeedback();
});

document.addEventListener("htmx:sendError", function () {
  stopNavigationFeedback();
});

document.addEventListener("htmx:timeout", function () {
  stopNavigationFeedback();
});

// Log HTMX interactions (development only)
if (window.location.hostname === "localhost") {
  document.addEventListener("htmx:xhr:loadstart", function (evt) {
    const xhr = evt && evt.detail ? evt.detail.xhr : null;
    const responseUrl = xhr && xhr.responseURL ? xhr.responseURL : "(unknown)";
    console.log("Loading:", responseUrl);
  });

  document.addEventListener("htmx:swapError", function (evt) {
    console.error("Swap error:", evt);
  });
}

// Mobile Menu Functions
function openMobileMenu() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("sidebar-overlay");
  if (sidebar) {
    sidebar.classList.remove("-translate-x-full");
    sidebar.classList.add("translate-x-0");
  }
  if (overlay) {
    overlay.classList.remove("hidden");
  }
  document.body.classList.add("overflow-hidden", "md:overflow-auto");
}

function closeMobileMenu() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("sidebar-overlay");

  if (sidebar) {
    if (window.innerWidth < 768) {
      sidebar.classList.add("-translate-x-full");
      sidebar.classList.remove("translate-x-0");
    }
  }
  if (overlay) {
    overlay.classList.add("hidden");
  }
  document.body.classList.remove("overflow-hidden");
}

function toggleMobileMenu() {
  const sidebar = document.getElementById("sidebar");
  if (sidebar) {
    const isOpen = sidebar.classList.contains("translate-x-0");
    if (window.innerWidth < 768) {
      isOpen ? closeMobileMenu() : openMobileMenu();
    } else {
      toggleSidebar();
    }
  }
}

// Sidebar Collapse/Expand Functions
function toggleSidebar() {
  const sidebar = document.getElementById("sidebar");
  if (!sidebar) return;
  const isCollapsed = sidebar.getAttribute("data-collapsed") === "true";
  isCollapsed ? expandSidebar() : collapseSidebar();
}

function collapseSidebar() {
  const sidebar = document.getElementById("sidebar");
  const topnav = document.getElementById("topnav");
  const mainContent = document.getElementById("main-content");

  if (sidebar) {
    sidebar.setAttribute("data-collapsed", "true");
    sidebar.style.width = "4rem";
  }
  if (topnav) {
    topnav.style.marginLeft = "";
    topnav.classList.remove("md:ml-64");
    topnav.classList.add("md:ml-16");
  }
  if (mainContent) {
    mainContent.classList.remove("md:ml-64");
    mainContent.classList.add("md:ml-16");
  }

  document.querySelectorAll(".sidebar-expanded-content").forEach((el) => {
    el.style.display = "none";
  });
  document.querySelectorAll(".sidebar-collapsed-content").forEach((el) => {
    el.style.display = "";
  });

  localStorage.setItem("sidebarCollapsed", "true");
}

function expandSidebar() {
  const sidebar = document.getElementById("sidebar");
  const topnav = document.getElementById("topnav");
  const mainContent = document.getElementById("main-content");

  if (sidebar) {
    sidebar.setAttribute("data-collapsed", "false");
    sidebar.style.width = "16rem";
  }
  if (topnav) {
    topnav.style.marginLeft = "";
    topnav.classList.remove("md:ml-16");
    topnav.classList.add("md:ml-64");
  }
  if (mainContent) {
    mainContent.classList.remove("md:ml-16");
    mainContent.classList.add("md:ml-64");
  }

  document.querySelectorAll(".sidebar-expanded-content").forEach((el) => {
    el.style.display = "";
  });
  document.querySelectorAll(".sidebar-collapsed-content").forEach((el) => {
    el.style.display = "none";
  });

  localStorage.setItem("sidebarCollapsed", "false");
}

// Initialize sidebar state from localStorage
function initSidebarState() {
  const isCollapsed = localStorage.getItem("sidebarCollapsed") === "true";
  if (isCollapsed && window.innerWidth >= 768) {
    collapseSidebar();
  } else if (window.innerWidth >= 768) {
    expandSidebar();
  }
}

// Initialize on first page load
document.addEventListener("DOMContentLoaded", function () {
  initSidebarState();

  // Close mobile menu on nav link click
  document.addEventListener("click", function (e) {
    const link = e.target.closest("#sidebar nav a.nav-link");
    if (link && window.innerWidth < 768) {
      closeMobileMenu();
    }
  });

  document.addEventListener("click", function (e) {
    const link = e.target.closest("a[href]");
    if (!link) return;
    if (link.closest("form")) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (!isNavigableLink(link)) return;

    startNavigationFeedback(link);
  });

  // Handle window resize
  window.addEventListener("resize", function () {
    if (window.innerWidth >= 768) {
      const overlay = document.getElementById("sidebar-overlay");
      if (overlay) overlay.classList.add("hidden");
      document.body.classList.remove("overflow-hidden");

      const isCollapsed = localStorage.getItem("sidebarCollapsed") === "true";
      const sidebar = document.getElementById("sidebar");
      if (sidebar) {
        sidebar.classList.remove("-translate-x-full");
        isCollapsed ? collapseSidebar() : expandSidebar();
      }
    } else {
      const sidebar = document.getElementById("sidebar");
      if (sidebar && !sidebar.classList.contains("translate-x-0")) {
        sidebar.classList.add("-translate-x-full");
      }
    }
  });
});

// Re-initialize sidebar state after hx-boost swaps the page
document.addEventListener("htmx:afterSettle", function () {
  initSidebarState();
  if (window.innerWidth < 768) {
    closeMobileMenu();
  }
  stopNavigationFeedback();
});

// Expose functions globally for onclick handlers in HTML
window.toggleSidebar = toggleSidebar;
window.collapseSidebar = collapseSidebar;
window.expandSidebar = expandSidebar;
window.openMobileMenu = openMobileMenu;
window.closeMobileMenu = closeMobileMenu;
window.toggleMobileMenu = toggleMobileMenu;
