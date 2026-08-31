(function () {
  const root = document.getElementById("app");
  const boot = JSON.parse(root.getAttribute("data-boot") || "{}");
  const KEY = "smmturk_mobile_key";
  const USER = "smmturk_mobile_user";
  const THEME = "smmturk_theme";

  const state = {
    route: "home",
    key: localStorage.getItem(KEY) || "",
    user: JSON.parse(localStorage.getItem(USER) || "null"),
    loading: false,
    error: "",
    notice: "",
    config: null,
    services: [],
    categories: [],
    orders: [],
    tickets: [],
    ticket: null,
    deposits: null,
    service: null,
    orderForm: { service: "", link: "", quantity: "", coupon: "", charge: "" },
    auth: { mode: "login", login: "", email: "", username: "", password: "", totp: "", challenge: "" },
  };

  const $ = (html) => {
    const t = document.createElement("template");
    t.innerHTML = html.trim();
    return t.content;
  };
  const esc = (v) => String(v ?? "")
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  const money = (v) => "$" + Number(v || 0).toFixed(3);

  function setUser(user, key) {
    state.user = user || null;
    if (key) {
      state.key = key;
      localStorage.setItem(KEY, key);
    }
    if (user) localStorage.setItem(USER, JSON.stringify(user));
    else {
      localStorage.removeItem(USER);
      localStorage.removeItem(KEY);
      state.key = "";
    }
  }

  async function api(action, body) {
    const payload = Object.assign({ action }, body || {});
    const headers = { "Content-Type": "application/json" };
    if (state.key) headers["X-API-Key"] = state.key;
    const res = await fetch(boot.api, { method: "POST", headers, body: JSON.stringify(payload) });
    let data = {};
    try { data = await res.json(); } catch (e) { data = { success: false, error: "Invalid server response" }; }
    if (!res.ok && !data.error) data.error = "Request failed";
    return data;
  }

  function go(route, extra) {
    state.route = route;
    state.error = "";
    state.notice = "";
    if (extra) Object.assign(state, extra);
    location.hash = route;
    render();
    loadRoute();
  }

  async function loadRoute() {
    if (!state.key) return;
    try {
      if (state.route === "home") {
        const data = await api("me");
        if (data.success) {
          setUser(data.user, state.key);
          state.recent = data.recent_orders || [];
          state.openTickets = data.open_tickets || 0;
          state.promo = data.promo;
        } else if (data.error === "Invalid API key") logout();
      }
      if (state.route === "services" || state.route === "order") {
        const cats = await api("categories");
        if (cats.success) state.categories = cats.categories || [];
        const svc = await api("services", { category: state.category || "", q: state.search || "", limit: 80 });
        if (svc.success) state.services = svc.services || [];
      }
      if (state.route === "orders") {
        const data = await api("orders");
        if (data.success) state.orders = data.orders || [];
      }
      if (state.route === "tickets") {
        const data = await api("tickets");
        if (data.success) state.tickets = data.tickets || [];
      }
      if (state.route === "ticket" && state.ticketId) {
        const data = await api("ticket", { id: state.ticketId });
        if (data.success) { state.ticket = data.ticket; state.replies = data.replies || []; }
      }
      if (state.route === "funds") {
        const data = await api("deposits");
        if (data.success) state.deposits = data;
      }
      if (state.route === "account") {
        const data = await api("me");
        if (data.success) setUser(data.user, state.key);
      }
    } catch (e) {
      state.error = "Network error. Check your connection.";
    }
    render();
  }

  function logout() {
    setUser(null, "");
    state.route = "auth";
    render();
  }

  function icon(name) {
    const p = {
      home: '<path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z"/>',
      plus: '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
      star: '<path d="m12 3 2.7 5.5 6 .9-4.4 4.3 1 6L12 17.8 6.7 19.7l1-6L3.3 9.4l6-.9z"/>',
      file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
      user: '<circle cx="12" cy="8" r="3.2"/><path d="M5 19c1.5-3.2 4-4.8 7-4.8S17.5 15.8 19 19"/>',
    };
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' + (p[name] || "") + "</svg>";
  }

  function topbar(title) {
    const bal = state.user ? money(state.user.balance) : "";
    return '<header class="topbar"><div class="brand"><img src="' + esc(boot.logo) + '" alt=""><span>' + esc(title || boot.siteName) + '</span></div>' +
      (state.user ? '<button type="button" class="balance-chip" data-go="funds">' + esc(bal) + "</button>" : "") + "</header>";
  }

  function nav() {
    const items = [
      ["home", "Home", "home"],
      ["order", "Order", "plus"],
      ["services", "Services", "star"],
      ["orders", "Orders", "file"],
      ["account", "Account", "user"],
    ];
    return '<nav class="bottom-nav">' + items.map(([id, label, ic]) =>
      '<button type="button" class="' + (state.route === id ? "is-on" : "") + '" data-go="' + id + '">' + icon(ic) + esc(label) + "</button>"
    ).join("") + "</nav>";
  }

  function alerts() {
    return (state.error ? '<div class="alert alert-error" role="alert">' + esc(state.error) + "</div>" : "") +
      (state.notice ? '<div class="alert alert-ok" role="status">' + esc(state.notice) + "</div>" : "");
  }

  function renderAuth() {
    const a = state.auth;
    const isReg = a.mode === "register";
    const is2fa = a.mode === "2fa";
    return '<div class="auth-wrap">' +
      '<div class="brand"><img src="' + esc(boot.logo) + '" alt=""><span>' + esc(boot.siteName) + "</span></div>" +
      "<h1>" + (is2fa ? "Two-factor code" : isReg ? "Create account" : "Welcome back") + "</h1>" +
      '<p class="muted" style="margin:8px 0 18px">Same panel. Built for your phone.</p>' +
      alerts() +
      (boot.google && !is2fa ? '<button type="button" class="btn btn-google" id="googleBtn">Continue with Google</button><div class="divider">or continue with email</div>' : "") +
      (is2fa
        ? '<form id="authForm"><label for="totp">Authenticator code</label><input class="field" id="totp" name="totp" inputmode="numeric" autocomplete="one-time-code" required><button class="btn btn-primary" type="submit">Verify</button></form>'
        : '<form id="authForm">' +
          (isReg ? '<label for="username">Username</label><input class="field" id="username" name="username" required minlength="3"><label for="email">Email</label><input class="field" id="email" name="email" type="email" required>' : '<label for="login">Username or email</label><input class="field" id="login" name="login" required>') +
          '<label for="password">Password</label><input class="field" id="password" name="password" type="password" required minlength="8">' +
          (isReg && boot.register ? '<label for="referral">Referral code (optional)</label><input class="field" id="referral" name="referral">' : "") +
          '<button class="btn btn-primary" type="submit">' + (isReg ? "Create account" : "Sign in") + "</button></form>") +
      (!is2fa ? '<p class="muted" style="margin-top:16px;text-align:center">' +
        (isReg ? 'Already have an account? <a href="#auth" id="toLogin">Sign in</a>' : (boot.register ? 'No account? <a href="#auth" id="toReg">Register</a>' : "")) +
        "</p>" : "") +
      "</div>";
  }

  function renderHome() {
    const u = state.user || {};
    const vip = (u.vip && u.vip.name) || "Bronze";
    const recent = state.recent || [];
    return topbar("Home") + '<main class="screen">' + alerts() +
      '<section class="hero-card"><p class="muted">Welcome</p><h1>' + esc(u.username || "") + "</h1>" +
      "<p style=\"margin-top:8px\">Balance <b>" + money(u.balance) + "</b> · VIP " + esc(vip) + "</p></section>" +
      '<div class="grid-2">' +
      '<button class="quick" data-go="order"><b>New order</b><span>Place a service</span></button>' +
      '<button class="quick" data-go="funds"><b>Add funds</b><span>Crypto deposit</span></button>' +
      '<button class="quick" data-go="orders"><b>Orders</b><span>Track delivery</span></button>' +
      '<button class="quick" data-go="tickets"><b>Support</b><span>' + (state.openTickets || 0) + " open</span></button>" +
      "</div>" +
      '<section class="card" style="margin-top:12px"><h2 style="font-size:16px;margin-bottom:8px">Recent orders</h2>' +
      (recent.length ? recent.map(orderRow).join("") : '<div class="empty">No orders yet. Start with a new order.</div>') +
      "</section></main>" + nav();
  }

  function orderRow(o) {
    const st = String(o.status || "").toLowerCase().replace(/\s+/g, "");
    return '<div class="list-item"><div><b>#' + esc(o.id) + " · " + esc(o.service_name) + "</b>" +
      '<div class="muted">' + esc(o.link) + " · x" + esc(o.quantity) + "</div></div>" +
      '<div><span class="badge badge-' + esc(st) + '">' + esc(o.status) + "</span><div class="muted">' + money(o.charge) + "</div></div></div>";
  }

  function renderOrder() {
    const f = state.orderForm;
    const services = state.services || [];
    return topbar("New order") + '<main class="screen">' + alerts() +
      '<form id="orderForm" class="card">' +
      '<label for="svc">Service</label><select class="field" id="svc" name="service" required>' +
      '<option value="">Select a service</option>' +
      services.map((s) => '<option value="' + esc(s.service) + '"' + (String(f.service) === String(s.service) ? " selected" : "") + ">" +
        esc(s.service) + " — " + esc(s.name) + " (" + esc(s.rate) + "/1k)</option>").join("") +
      "</select>" +
      '<label for="link">Link</label><input class="field" id="link" name="link" placeholder="https://instagram.com/username" required value="' + esc(f.link) + '">' +
      '<label for="qty">Quantity</label><input class="field" id="qty" name="quantity" type="number" min="1" required value="' + esc(f.quantity) + '">' +
      '<label for="coupon">Coupon (optional)</label><input class="field" id="coupon" name="coupon" value="' + esc(f.coupon) + '">' +
      (f.charge ? '<p class="muted">Estimated charge: <b>' + money(f.charge) + "</b></p>" : "") +
      '<button class="btn btn-primary" type="submit">Place order</button></form></main>' + nav();
  }

  function renderServices() {
    const list = state.services || [];
    const cats = state.categories || [];
    return topbar("Services") + '<main class="screen">' + alerts() +
      '<input class="field" id="search" placeholder="Search services" value="' + esc(state.search || "") + '">' +
      '<div class="tabs"><button type="button" class="chip' + (!state.category ? " is-on" : "") + '" data-cat="">All</button>' +
      cats.slice(0, 24).map((c) => '<button type="button" class="chip' + (state.category === c.category ? " is-on" : "") + '" data-cat="' + esc(c.category) + '">' + esc(c.category) + " (" + esc(c.count) + ")</button>").join("") +
      "</div>" +
      (list.length ? list.map((s) => '<button type="button" class="list-item" data-pick="' + esc(s.service) + '"><div><b>' + esc(s.name) + '</b><div class="muted">' + esc(s.category) + " · min " + esc(s.min) + "–" + esc(s.max) + '</div></div><div class="svc-rate">' + esc(s.rate) + "</div></button>").join("") : '<div class="empty">No services match your search.</div>') +
      "</main>" + nav();
  }

  function renderOrders() {
    const list = state.orders || [];
    return topbar("Orders") + '<main class="screen">' + alerts() +
      (list.length ? '<div class="card">' + list.map(orderRow).join("") + "</div>" : '<div class="empty">No orders yet.</div>') +
      "</main>" + nav();
  }

  function renderFunds() {
    const methods = (state.config && state.config.payment_methods) || [];
    const pending = state.deposits && state.deposits.pending;
    const hist = (state.deposits && state.deposits.deposits) || [];
    return topbar("Add funds") + '<main class="screen">' + alerts() +
      (methods.length ? "" : '<div class="alert alert-info">No payment methods are enabled. Contact support.</div>') +
      (pending ? '<section class="card"><h2 style="font-size:16px">Pending deposit #' + esc(pending.id) + "</h2>" +
        "<p>Amount <b>" + money(pending.amount) + "</b></p>" +
        (pending.wallet ? '<p class="muted">' + esc(pending.wallet.label) + " " + esc(pending.wallet.network) + '</p><div class="wallet">' + esc(pending.wallet.address) + "</div>" +
          '<div class="row-actions"><button type="button" class="btn btn-ghost" data-copy="' + esc(pending.wallet.address) + '">Copy address</button></div>' +
          '<form id="txForm" style="margin-top:12px"><label for="tx">TxHash</label><input class="field" id="tx" name="tx_hash" required><input type="hidden" name="deposit_id" value="' + esc(pending.id) + '"><button class="btn btn-primary" type="submit">I paid</button></form>' : "") +
        "</section>" : "") +
      '<form id="fundForm" class="card"><h2 style="font-size:16px;margin-bottom:8px">New deposit</h2>' +
      '<label for="method">Method</label><select class="field" id="method" name="method" required>' +
      methods.map((m) => '<option value="' + esc(m.slug) + '">' + esc(m.label) + " — " + esc(m.desc) + "</option>").join("") +
      '</select><label for="amount">Amount (USD)</label><input class="field" id="amount" name="amount" type="number" min="1" step="0.01" value="25" required>' +
      '<button class="btn btn-primary" type="submit">Continue</button></form>' +
      '<section class="card"><h2 style="font-size:16px;margin-bottom:8px">History</h2>' +
      (hist.length ? hist.map((d) => '<div class="list-item"><div><b>' + money(d.amount) + "</b><div class=\"muted\">" + esc(d.description) + '</div></div><span class="badge badge-' + esc(d.status) + '">' + esc(d.status) + "</span></div>").join("") : '<div class="empty">No deposits yet.</div>') +
      "</section></main>" + nav();
  }

  function renderTickets() {
    const list = state.tickets || [];
    return topbar("Support") + '<main class="screen">' + alerts() +
      '<form id="ticketForm" class="card"><h2 style="font-size:16px;margin-bottom:8px">New ticket</h2>' +
      '<label for="cat">Category</label><select class="field" id="cat" name="category">' +
      (state.config && state.config.ticket_categories || ["Other"]).map((c) => '<option>' + esc(c) + "</option>").join("") +
      '</select><label for="msg">Message</label><textarea class="field" id="msg" name="message" rows="4" required></textarea>' +
      '<button class="btn btn-primary" type="submit">Send ticket</button></form>' +
      '<section class="card"><h2 style="font-size:16px;margin-bottom:8px">Your tickets</h2>' +
      (list.length ? list.map((t) => '<button type="button" class="list-item" data-ticket="' + esc(t.id) + '"><div><b>#' + esc(t.id) + " · " + esc(t.subject) + '</b><div class="muted">' + esc(t.updated_at || "") + '</div></div><span class="badge">' + esc(t.status) + "</span></button>").join("") : '<div class="empty">No tickets yet.</div>') +
      "</section></main>" + nav();
  }

  function renderTicket() {
    const t = state.ticket || {};
    const replies = state.replies || [];
    return topbar("Ticket") + '<main class="screen">' + alerts() +
      '<section class="card"><h1 style="font-size:18px">#' + esc(t.id) + " — " + esc(t.subject) + '</h1><p class="muted">' + esc(t.status) + "</p>" +
      replies.map((r) => '<div class="reply' + (Number(r.is_staff) ? " staff" : "") + '"><div class="muted">' + (Number(r.is_staff) ? "Support" : "You") + " · " + esc(r.created_at) + "</div><div>" + esc(r.message) + "</div></div>").join("") +
      (t.status !== "closed" ? '<form id="replyForm" style="margin-top:12px"><textarea class="field" name="message" rows="3" required placeholder="Write a reply"></textarea><button class="btn btn-primary" type="submit">Reply</button></form>' : "") +
      '<button type="button" class="btn btn-ghost" data-go="tickets" style="margin-top:10px">Back to tickets</button></section></main>' + nav();
  }

  function renderAccount() {
    const u = state.user || {};
    return topbar("Account") + '<main class="screen">' + alerts() +
      '<section class="card"><h1 style="font-size:20px">' + esc(u.username) + "</h1>" +
      '<p class="muted">' + esc(u.email) + "</p>" +
      "<p style=\"margin-top:10px\">Balance <b>" + money(u.balance) + "</b></p>" +
      "<p>Spent <b>" + money(u.spent) + "</b></p>" +
      "<p>VIP <b>" + esc((u.vip && u.vip.name) || "Bronze") + "</b></p>" +
      (u.referral_code ? "<p>Referral <b>" + esc(u.referral_code) + "</b></p>" : "") +
      '<div class="row-actions"><button type="button" class="btn btn-ghost" data-go="funds">Add funds</button><button type="button" class="btn btn-ghost" data-go="tickets">Tickets</button></div>' +
      '<button type="button" class="btn btn-primary" id="logoutBtn" style="margin-top:16px">Log out</button></section></main>' + nav();
  }

  function render() {
    if (!state.key && state.route !== "auth") state.route = "auth";
    let html = "";
    if (state.route === "auth") html = renderAuth();
    else if (state.route === "order") html = renderOrder();
    else if (state.route === "services") html = renderServices();
    else if (state.route === "orders") html = renderOrders();
    else if (state.route === "funds") html = renderFunds();
    else if (state.route === "tickets") html = renderTickets();
    else if (state.route === "ticket") html = renderTicket();
    else if (state.route === "account") html = renderAccount();
    else html = renderHome();
    root.innerHTML = html;
    bind();
  }

  function bind() {
    root.querySelectorAll("[data-go]").forEach((el) => el.addEventListener("click", () => go(el.getAttribute("data-go"))));
    const authForm = root.querySelector("#authForm");
    if (authForm) authForm.addEventListener("submit", onAuth);
    const googleBtn = root.querySelector("#googleBtn");
    if (googleBtn) googleBtn.addEventListener("click", onGoogle);
    const toReg = root.querySelector("#toReg");
    if (toReg) toReg.addEventListener("click", (e) => { e.preventDefault(); state.auth.mode = "register"; render(); });
    const toLogin = root.querySelector("#toLogin");
    if (toLogin) toLogin.addEventListener("click", (e) => { e.preventDefault(); state.auth.mode = "login"; render(); });
    const orderForm = root.querySelector("#orderForm");
    if (orderForm) {
      orderForm.addEventListener("submit", onOrder);
      orderForm.addEventListener("change", onPreview);
    }
    const search = root.querySelector("#search");
    if (search) search.addEventListener("change", () => { state.search = search.value.trim(); go("services"); });
    root.querySelectorAll("[data-cat]").forEach((el) => el.addEventListener("click", () => { state.category = el.getAttribute("data-cat"); go("services"); }));
    root.querySelectorAll("[data-pick]").forEach((el) => el.addEventListener("click", () => {
      state.orderForm.service = el.getAttribute("data-pick");
      go("order");
    }));
    const fundForm = root.querySelector("#fundForm");
    if (fundForm) fundForm.addEventListener("submit", onFund);
    const txForm = root.querySelector("#txForm");
    if (txForm) txForm.addEventListener("submit", onTx);
    root.querySelectorAll("[data-copy]").forEach((el) => el.addEventListener("click", async () => {
      try { await navigator.clipboard.writeText(el.getAttribute("data-copy")); state.notice = "Address copied."; render(); } catch (e) {}
    }));
    const ticketForm = root.querySelector("#ticketForm");
    if (ticketForm) ticketForm.addEventListener("submit", onTicket);
    root.querySelectorAll("[data-ticket]").forEach((el) => el.addEventListener("click", () => { state.ticketId = el.getAttribute("data-ticket"); go("ticket"); }));
    const replyForm = root.querySelector("#replyForm");
    if (replyForm) replyForm.addEventListener("submit", onReply);
    const logoutBtn = root.querySelector("#logoutBtn");
    if (logoutBtn) logoutBtn.addEventListener("click", logout);
  }

  async function onAuth(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    state.error = "";
    try {
      if (state.auth.mode === "2fa") {
        const data = await api("login_2fa", { challenge: state.auth.challenge, code: fd.get("totp") });
        if (!data.success) { state.error = data.error || "Invalid code"; render(); return; }
        setUser(data.user, data.api_key); go("home"); return;
      }
      if (state.auth.mode === "register") {
        const data = await api("register", {
          username: fd.get("username"), email: fd.get("email"), password: fd.get("password"), referral: fd.get("referral") || ""
        });
        if (!data.success) { state.error = data.error || "Registration failed"; render(); return; }
        if (data.verify_required) { state.notice = data.message; state.auth.mode = "login"; render(); return; }
        setUser(data.user, data.api_key); go("home"); return;
      }
      const data = await api("login", { login: fd.get("login"), password: fd.get("password") });
      if (!data.success) { state.error = data.error || "Invalid credentials"; render(); return; }
      if (data.needs_2fa) { state.auth.mode = "2fa"; state.auth.challenge = data.challenge; render(); return; }
      setUser(data.user, data.api_key); go("home");
    } catch (err) {
      state.error = "Network error. Try again.";
      render();
    }
  }

  function onGoogle() {
    const native = window.SmmTurkNative;
    const url = boot.googleUrl + (native ? "&app=1" : "");
    if (native && typeof native.startGoogle === "function") native.startGoogle(url);
    else window.location.href = url;
  }

  async function onPreview() {
    const form = root.querySelector("#orderForm");
    if (!form) return;
    const fd = new FormData(form);
    const service = fd.get("service");
    const quantity = fd.get("quantity");
    if (!service || !quantity) return;
    const data = await api("order_preview", { service, quantity, coupon: fd.get("coupon") || "" });
    if (data.success) {
      state.orderForm = { service, link: fd.get("link") || "", quantity, coupon: fd.get("coupon") || "", charge: data.charge };
      const p = root.querySelector(".muted b");
      if (p) p.textContent = money(data.charge);
    }
  }

  async function onOrder(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = await api("order_create", {
      service: fd.get("service"), link: fd.get("link"), quantity: fd.get("quantity"), coupon: fd.get("coupon") || ""
    });
    if (!data.success) { state.error = data.error || "Could not place order"; render(); return; }
    if (data.user) setUser(data.user, state.key);
    state.notice = "Order #" + data.order_id + " placed.";
    go("orders");
  }

  async function onFund(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = await api("deposit_create", { method: fd.get("method"), amount: fd.get("amount") });
    if (!data.success) { state.error = data.error || "Could not start deposit"; render(); return; }
    if (data.redirect_url) {
      if (window.SmmTurkNative && window.SmmTurkNative.openExternal) window.SmmTurkNative.openExternal(data.redirect_url);
      else window.location.href = data.redirect_url;
      return;
    }
    state.notice = "Send the exact amount to the wallet below.";
    go("funds");
  }

  async function onTx(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = await api("deposit_submit_tx", { deposit_id: fd.get("deposit_id"), tx_hash: fd.get("tx_hash") });
    if (!data.success) { state.error = data.error || "Could not submit"; render(); return; }
    if (data.user) setUser(data.user, state.key);
    state.notice = data.message;
    go("funds");
  }

  async function onTicket(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = await api("ticket_create", { category: fd.get("category"), message: fd.get("message") });
    if (!data.success) { state.error = data.error || "Could not create ticket"; render(); return; }
    state.notice = "Ticket #" + data.ticket_id + " created.";
    go("tickets");
  }

  async function onReply(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = await api("ticket_reply", { id: state.ticketId, message: fd.get("message") });
    if (!data.success) { state.error = data.error || "Could not reply"; render(); return; }
    go("ticket");
  }

  async function bootApp() {
    if (localStorage.getItem(THEME) === "dark" || (!localStorage.getItem(THEME) && window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches)) {
      document.body.classList.add("theme-dark");
    }
    const params = new URLSearchParams(location.search);
    if (params.get("oauth_token")) {
      try {
        const data = await api("google_finish", { token: params.get("oauth_token") });
        if (data.success) setUser(data.user, data.api_key);
      } catch (e) {}
      history.replaceState({}, "", location.pathname);
    }
    if (params.get("challenge")) {
      state.auth.mode = "2fa";
      state.auth.challenge = params.get("challenge");
      history.replaceState({}, "", location.pathname);
    }
    if (params.get("oauth_error")) {
      state.error = "Google sign-in failed. Try again.";
      history.replaceState({}, "", location.pathname);
    }
    try { state.config = await api("config"); } catch (e) { state.config = {}; }
    if (state.config && state.config.success === false) state.config = {};
    const hash = (location.hash || "").replace("#", "");
    state.route = state.key ? (hash || "home") : "auth";
    render();
    loadRoute();
  }

  window.SmmTurkApplyAuth = function (token) {
    api("google_finish", { token: token }).then((data) => {
      if (data.success) { setUser(data.user, data.api_key); go("home"); }
    });
  };

  bootApp();
})();
