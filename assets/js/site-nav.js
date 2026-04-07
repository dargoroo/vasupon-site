(() => {
  const root = document.getElementById("site-nav-root");
  if (!root) return;

  const current = document.body.dataset.navCurrent || "";
  const items = [
    { key: "home", href: "index.html", label: "Home" },
    { key: "profile", href: "about.html", label: "Profile" },
    { key: "research", href: "research.html", label: "Research" },
    { key: "courses", href: "courses.html", label: "Courses" },
    { key: "portal", href: "cpe_portal/", label: "CPE RBRU Apps", portal: true },
    { key: "contact", href: "contact.html", label: "Contact" }
  ];

  root.innerHTML = `
    <nav>
      <div class="logo">Vasupon P.</div>
      <ul>
        ${items
          .map((item) => {
            const isActive = item.key === current;
            const style = item.portal
              ? "color: var(--primary); padding: 5px 10px; border: 1px solid var(--primary); border-radius: 4px;"
              : isActive
                ? "color: var(--primary);"
                : "";
            return `<li><a href="${item.href}"${style ? ` style="${style}"` : ""}>${item.label}</a></li>`;
          })
          .join("")}
      </ul>
    </nav>
  `;
})();
