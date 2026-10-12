/* RH Pay Solutions — interacciones del sitio. Sin dependencias. */
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  /* Formularios: URL del servicio que recibe los datos (CRM, correo o backend).
     Mientras esté vacía, el formulario valida y redirige a la página de gracias
     sin enviar datos. Ver README para conectarlo. */
  const FORM_ENDPOINT = "";

  /* Header con sombra al hacer scroll */
  const header = $("[data-header]");
  if (header) {
    const onScroll = () => header.classList.toggle("is-scrolled", window.scrollY > 8);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  /* Menú móvil */
  const toggle = $("[data-menu-toggle]");
  const nav = $("[data-nav]");
  if (toggle && nav) {
    const setOpen = (open) => {
      nav.classList.toggle("is-open", open);
      toggle.setAttribute("aria-expanded", String(open));
      toggle.setAttribute("aria-label", open ? "Cerrar menú" : "Abrir menú");
      const use = toggle.querySelector("use");
      if (use) use.setAttribute("href", use.getAttribute("href").replace(/#.*$/, `#${open ? "close" : "menu"}`));
      document.body.classList.toggle("menu-open", open);
    };
    toggle.addEventListener("click", () => setOpen(!nav.classList.contains("is-open")));
    nav.addEventListener("click", (e) => { if (e.target.closest("a")) setOpen(false); });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") setOpen(false); });
  }

  /* Submenús: clic/teclado (en escritorio también abren con hover vía CSS) */
  $$(".has-sub > .nav__link").forEach((btn) => {
    const item = btn.parentElement;
    btn.addEventListener("click", () => {
      const open = !item.classList.contains("is-open");
      $$(".has-sub.is-open").forEach((o) => { if (o !== item) { o.classList.remove("is-open"); o.firstElementChild.setAttribute("aria-expanded", "false"); } });
      item.classList.toggle("is-open", open);
      btn.setAttribute("aria-expanded", String(open));
    });
  });
  document.addEventListener("click", (e) => {
    if (!e.target.closest(".has-sub")) $$(".has-sub.is-open").forEach((o) => { o.classList.remove("is-open"); o.firstElementChild.setAttribute("aria-expanded", "false"); });
  });
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    const open = $(".has-sub.is-open");
    if (open) { open.classList.remove("is-open"); open.firstElementChild.setAttribute("aria-expanded", "false"); open.firstElementChild.focus(); }
  });

  /* Carruseles */
  const initSlider = (root) => {
    const track = $(".slider__track", root);
    const prev = $("[data-prev]", root);
    const next = $("[data-next]", root);
    const bar = $(".slider__progress span", root);
    const update = () => {
      const max = track.scrollWidth - track.clientWidth;
      const ratio = track.clientWidth / track.scrollWidth;
      if (bar) { bar.style.width = `${Math.max(ratio, 0.12) * 100}%`; bar.style.transform = `translateX(${max > 0 ? (track.scrollLeft / max) * ((1 / Math.max(ratio, 0.12)) - 1) * 100 : 0}%)`; }
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft >= max - 2;
      root.querySelector(".slider__controls").hidden = max <= 2;
    };
    const step = () => (track.firstElementChild?.getBoundingClientRect().width || 300) + 20;
    prev.addEventListener("click", () => track.scrollBy({ left: -step(), behavior: "smooth" }));
    next.addEventListener("click", () => track.scrollBy({ left: step(), behavior: "smooth" }));
    track.addEventListener("scroll", update, { passive: true });
    new ResizeObserver(update).observe(track);
    root._update = update;
  };
  $$("[data-slider]").forEach(initSlider);

  /* Pestañas verticales (patrón ARIA tabs) */
  $$("[data-tabs]").forEach((root) => {
    const tabs = $$('[role="tab"]', root);
    const select = (tab, focus = false) => {
      tabs.forEach((t) => {
        const on = t === tab;
        t.setAttribute("aria-selected", String(on));
        t.tabIndex = on ? 0 : -1;
        const panel = document.getElementById(t.getAttribute("aria-controls"));
        panel.hidden = !on;
        if (on) panel.querySelector("[data-slider]")?._update?.();
      });
      if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
      tab.addEventListener("click", () => select(tab));
      tab.addEventListener("keydown", (e) => {
        const keys = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
        if (e.key in keys) { e.preventDefault(); select(tabs[(i + keys[e.key] + tabs.length) % tabs.length], true); }
        if (e.key === "Home") { e.preventDefault(); select(tabs[0], true); }
        if (e.key === "End") { e.preventDefault(); select(tabs[tabs.length - 1], true); }
      });
    });
  });

  /* ---------- Animaciones (inspiradas en la referencia; se omiten con "reducir movimiento") ---------- */
  const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* Títulos que aparecen palabra por palabra */
  const splitWords = (el) => {
    let i = 0;
    const walk = (node, grad) => {
      [...node.childNodes].forEach((n) => {
        if (n.nodeType === 3) {
          const frag = document.createDocumentFragment();
          n.textContent.split(/(\s+)/).forEach((part) => {
            if (!part) return;
            if (/^\s+$/.test(part)) { frag.append(part); return; }
            const outer = document.createElement("span");
            const inner = document.createElement("span");
            outer.className = "w";
            inner.className = grad ? "w__i text-grad" : "w__i";
            inner.style.setProperty("--i", i++);
            inner.textContent = part;
            outer.append(inner);
            frag.append(outer);
          });
          n.replaceWith(frag);
        } else if (n.nodeType === 1) {
          const g = grad || n.classList.contains("text-grad");
          n.classList.remove("text-grad");
          walk(n, g);
        }
      });
    };
    walk(el, false);
    el.classList.add("words");
  };
  const headings = reduce ? [] : $$(".hero h1, .page-hero h1, .article-hero h1, .section-head h2, .band h2, .cta-final h2, .lead-card h2");
  headings.forEach(splitWords);

  /* Cascada: los hijos de estas cuadrículas entran uno tras otro */
  const staggers = reduce ? [] : $$(".bento, .diff-grid, .grid-cards, .grid-quotes, .stats, .steps, .module-grid, .logo-grid, .faq, .chips, .checklist, .mosaic");
  staggers.forEach((g) => {
    g.classList.add("stagger");
    [...g.children].forEach((c, i) => c.style.setProperty("--i", Math.min(i, 12)));
  });

  /* Contadores en las cifras (20+, 32, 6, 13) */
  const counters = reduce ? [] : $$(".stats strong").map((el) => {
    const m = el.textContent.trim().match(/^(\d+)(.*)$/);
    if (!m) return null;
    el.dataset.target = m[1];
    el.dataset.suffix = m[2];
    el.textContent = `0${m[2]}`;
    return el;
  }).filter(Boolean);
  const countUp = (el) => {
    const target = +el.dataset.target, dur = 1400, t0 = performance.now();
    const tick = (t) => {
      const p = Math.min((t - t0) / dur, 1);
      el.textContent = `${Math.round(target * (1 - Math.pow(1 - p, 3)))}${el.dataset.suffix}`;
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };

  const watched = [...$$(".reveal"), ...headings, ...staggers, ...counters];
  if ("IntersectionObserver" in window && watched.length) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        en.target.classList.add("is-visible");
        if (en.target.dataset.target) countUp(en.target);
        io.unobserve(en.target);
      });
    }, { rootMargin: "0px 0px -8% 0px" });
    watched.forEach((el) => io.observe(el));
  } else {
    watched.forEach((el) => el.classList.add("is-visible"));
  }
  /* Respaldo: lo que ya está en pantalla nunca se queda oculto */
  setTimeout(() => watched.forEach((el) => {
    if (el.classList.contains("is-visible") || el.getBoundingClientRect().top > innerHeight) return;
    el.classList.add("is-visible");
    if (el.dataset.target) countUp(el);
  }), 1500);

  /* Ilustración del hero: paralaje al hacer scroll e inclinación con el cursor */
  const dash = $(".dash");
  if (dash && !reduce) {
    const card = $(".dash__card", dash);
    const glow = $(".dash__glow", dash);
    let tilt = { x: 0, y: 0 }, ticking = false;
    const render = () => {
      const y = Math.min(window.scrollY, 600);
      glow.style.transform = `rotate(-4deg) translateY(${y * 0.08}px)`;
      card.style.transform = `translateY(${y * -0.04}px) rotateX(${tilt.y}deg) rotateY(${tilt.x}deg)`;
      ticking = false;
    };
    const request = () => { if (!ticking) { ticking = true; requestAnimationFrame(render); } };
    window.addEventListener("scroll", request, { passive: true });
    if (matchMedia("(hover: hover)").matches) {
      dash.addEventListener("pointermove", (e) => {
        const r = dash.getBoundingClientRect();
        tilt = { x: ((e.clientX - r.left) / r.width - 0.5) * 8, y: ((e.clientY - r.top) / r.height - 0.5) * -8 };
        request();
      });
      dash.addEventListener("pointerleave", () => { tilt = { x: 0, y: 0 }; request(); });
    }
  }

  /* Índice de contenidos: resalta la sección visible */
  const tocLinks = $$(".toc a[href^='#']");
  if (tocLinks.length && "IntersectionObserver" in window) {
    const map = new Map(tocLinks.map((a) => [document.getElementById(a.hash.slice(1)), a]));
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) { tocLinks.forEach((a) => a.classList.remove("is-active")); map.get(en.target)?.classList.add("is-active"); }
      });
    }, { rootMargin: "-20% 0px -70% 0px" });
    map.forEach((_, sec) => sec && io.observe(sec));
  }

  /* Formularios */
  $$("form[data-form]").forEach((form) => {
    const status = $(".form__status", form);
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      let firstInvalid = null;
      $$("input, select, textarea", form).forEach((f) => {
        const ok = f.checkValidity();
        f.setAttribute("aria-invalid", String(!ok));
        if (!ok && !firstInvalid) firstInvalid = f;
      });
      if (firstInvalid) {
        status.textContent = firstInvalid.type === "checkbox" ? "Acepta el aviso de privacidad para continuar." : "Revisa los campos marcados.";
        status.classList.add("is-error");
        firstInvalid.focus();
        return;
      }
      status.classList.remove("is-error");
      status.textContent = "Enviando…";
      const btn = $("button[type=submit]", form);
      btn.disabled = true;
      try {
        if (FORM_ENDPOINT) {
          const data = Object.fromEntries(new FormData(form));
          data.formulario = form.dataset.form;
          data.pagina = location.pathname;
          const res = await fetch(FORM_ENDPOINT, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) });
          if (!res.ok) throw new Error(res.status);
        }
        location.href = form.dataset.redirect;
      } catch {
        status.textContent = "No pudimos enviar tus datos. Intenta de nuevo o escríbenos por WhatsApp.";
        status.classList.add("is-error");
        btn.disabled = false;
      }
    });
    form.addEventListener("input", (e) => { if (e.target.getAttribute("aria-invalid") === "true" && e.target.checkValidity()) e.target.setAttribute("aria-invalid", "false"); });
  });

  /* Infografías: clic o toque para ampliar en una ventana (lightbox) */
  const zoomImgs = $$(".prose figure img");
  if (zoomImgs.length && typeof HTMLDialogElement === "function") {
    const ICON_ZOOM = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M11 8v6M8 11h6"/></svg>';
    const ICON_OUT = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M8 11h6"/></svg>';
    const ICON_CLOSE = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
    const dlg = document.createElement("dialog");
    dlg.className = "lightbox";
    dlg.setAttribute("aria-label", "Imagen ampliada");
    dlg.innerHTML = '<div class="lightbox__bar"><button type="button" class="lightbox__btn" data-lb-zoom aria-pressed="false"></button><button type="button" class="lightbox__btn" data-lb-close>' + ICON_CLOSE + '<span>Cerrar</span></button></div><div class="lightbox__stage" data-lb-stage tabindex="0"><img class="lightbox__img" alt=""></div><p class="lightbox__cap"></p>';
    document.body.appendChild(dlg);
    const stage = $("[data-lb-stage]", dlg);
    const big = $(".lightbox__img", dlg);
    const cap = $(".lightbox__cap", dlg);
    const zoomBtn = $("[data-lb-zoom]", dlg);
    let zoomed = false;
    let drag = null;
    let moved = false;

    const label = () => { zoomBtn.innerHTML = (zoomed ? ICON_OUT + "<span>Alejar</span>" : ICON_ZOOM + "<span>Acercar</span>"); zoomBtn.setAttribute("aria-pressed", String(zoomed)); };
    const setZoom = (on, ev) => {
      if (on === zoomed) return;
      const r = big.getBoundingClientRect();
      const fx = ev && r.width ? (ev.clientX - r.left) / r.width : .5;
      const fy = ev && r.height ? (ev.clientY - r.top) / r.height : .5;
      zoomed = on;
      dlg.classList.toggle("is-zoomed", on);
      if (on) {
        big.style.width = Math.max(big.naturalWidth || 0, r.width * 1.7) + "px";
        const w = big.getBoundingClientRect();
        stage.scrollLeft = fx * w.width - stage.clientWidth / 2 + (w.left - stage.getBoundingClientRect().left + stage.scrollLeft);
        stage.scrollTop = fy * w.height - stage.clientHeight / 2 + (w.top - stage.getBoundingClientRect().top + stage.scrollTop);
      } else {
        big.style.width = "";
        stage.scrollTo(0, 0);
      }
      label();
    };
    const open = (img) => {
      big.src = img.currentSrc || img.src;
      big.alt = img.alt;
      cap.textContent = (img.closest("figure").querySelector("figcaption") || {}).textContent || "";
      zoomed = true; setZoom(false); label();
      dlg.showModal();
      document.documentElement.classList.add("lb-open");
    };
    dlg.addEventListener("close", () => { document.documentElement.classList.remove("lb-open"); zoomed = false; dlg.classList.remove("is-zoomed"); big.style.width = ""; });
    $("[data-lb-close]", dlg).addEventListener("click", () => dlg.close());
    zoomBtn.addEventListener("click", () => setZoom(!zoomed));
    stage.addEventListener("click", (e) => {
      if (moved) { moved = false; return; }
      if (e.target === big) setZoom(!zoomed, e);
      else if (!zoomed) dlg.close();
    });
    stage.addEventListener("pointerdown", (e) => {
      if (!zoomed || e.pointerType !== "mouse") return;
      drag = { x: e.clientX, y: e.clientY, l: stage.scrollLeft, t: stage.scrollTop };
      moved = false;
      stage.setPointerCapture(e.pointerId);
      stage.classList.add("is-dragging");
    });
    stage.addEventListener("pointermove", (e) => {
      if (!drag) return;
      const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
      if (Math.abs(dx) + Math.abs(dy) > 5) moved = true;
      stage.scrollLeft = drag.l - dx;
      stage.scrollTop = drag.t - dy;
    });
    const endDrag = () => { drag = null; stage.classList.remove("is-dragging"); };
    stage.addEventListener("pointerup", endDrag);
    stage.addEventListener("pointercancel", endDrag);

    zoomImgs.forEach((img) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "zoomable";
      btn.setAttribute("aria-label", "Ampliar imagen: " + (img.alt || "infografía"));
      img.replaceWith(btn);
      btn.appendChild(img);
      btn.insertAdjacentHTML("beforeend", '<span class="zoomable__hint" aria-hidden="true">' + ICON_ZOOM + "Ampliar</span>");
      btn.addEventListener("click", () => open(img));
    });
  }
})();
