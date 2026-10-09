(function () {
  const view = document.getElementById("view");
  const nav = document.getElementById("nav");
  const toast = document.getElementById("toast");
  const state = {
    settings: null,
    lowCount: 0,
    query: "",
    results: [],
    searched: false,
    bootError: "",
    timer: 0
  };
  let scanner = null;
  let scanHandled = false;

  function lib() {
    return window.__Html5QrcodeLibrary__ || {};
  }

  function h(tag, props, kids) {
    const node = document.createElement(tag);
    Object.keys(props || {}).forEach(function (key) {
      const value = props[key];
      if (value == null || value === false) return;
      if (key === "class") node.className = value;
      else if (key === "text") node.textContent = String(value);
      else if (key.indexOf("on") === 0 && typeof value === "function") node.addEventListener(key.slice(2).toLowerCase(), value);
      else if (key === "value") {
        node.setAttribute("value", String(value));
        node.value = value;
      } else if (key === "checked" || key === "disabled") node[key] = value;
      else node.setAttribute(key, value === true ? "" : String(value));
    });
    (kids || []).forEach(function (kid) {
      if (kid == null || kid === false) return;
      node.appendChild(typeof kid === "string" ? document.createTextNode(kid) : kid);
    });
    return node;
  }

  function formatQty(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return "0";
    if (Math.abs(n - Math.round(n)) < 0.001) return String(Math.round(n));
    return n.toFixed(2).replace(/0+$/, "").replace(/\.$/, "");
  }

  function formatDelta(value) {
    const n = Number(value);
    if (!Number.isFinite(n) || Math.abs(n) < 0.001) return "0";
    const text = formatQty(Math.abs(n));
    return n > 0 ? "+" + text : "-" + text;
  }

  function formatMoney(product) {
    if (product.price === "" || product.price == null) return "";
    const amount = Number(product.price);
    if (!Number.isFinite(amount)) return "";
    const text = amount.toFixed(2);
    return product.currency === "ZAR" ? "R " + text : text + " " + (product.currency || "");
  }

  function api(path, options) {
    const opts = options || {};
    return fetch(BS_STOCK.root + String(path).replace(/^\//, ""), {
      method: opts.method || "GET",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": BS_STOCK.nonce
      },
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error(data.message || "Something went wrong.");
        return data;
      });
    });
  }

  function showToast(message) {
    toast.hidden = false;
    toast.textContent = message;
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(function () { toast.hidden = true; }, 3200);
  }

  function route() {
    const hash = location.hash || "#/";
    const product = hash.match(/^#\/p\/(\d+)/);
    if (product) return { name: "product", id: Number(product[1]) };
    if (hash.indexOf("#/low") === 0) return { name: "low" };
    if (hash.indexOf("#/activity") === 0) return { name: "activity" };
    if (hash.indexOf("#/settings") === 0) return { name: "settings" };
    return { name: "search" };
  }

  function paintNav(name) {
    const items = [
      ["search", "Search", "#/"],
      ["low", "Low stock", "#/low"],
      ["activity", "Activity", "#/activity"],
      ["settings", "Settings", "#/settings"]
    ];
    nav.replaceChildren();
    items.forEach(function (item) {
      const link = h("a", { href: item[2], class: name === item[0] ? "on" : "" }, [item[1]]);
      if (item[0] === "low" && state.lowCount > 0) {
        link.appendChild(h("span", { class: "badge", text: String(state.lowCount) }));
      }
      nav.appendChild(link);
    });
  }

  async function stopScanner() {
    if (!scanner) return;
    const current = scanner;
    scanner = null;
    try { await current.stop(); } catch (e) { /* already stopped */ }
    try { current.clear(); } catch (e) { /* already cleared */ }
  }

  async function openScanner() {
    await stopScanner();
    let panel = document.getElementById("scanner");
    if (!panel) {
      panel = h("div", { id: "scanner", class: "scanner" }, [
        h("div", { id: "reader" }),
        h("p", { class: "muted", text: "Point the camera at a barcode or QR code." })
      ]);
      document.querySelector(".search").after(panel);
    }
    const button = document.getElementById("scan");
    if (button) button.textContent = "Stop";
    scanHandled = false;
    const Html5Qrcode = lib().Html5Qrcode;
    const formats = lib().Html5QrcodeSupportedFormats || {};
    if (!Html5Qrcode) {
      showToast("The scanner did not load. Type the part number.");
      return;
    }
    const wanted = ["QR_CODE", "CODE_128", "CODE_39", "EAN_13", "EAN_8", "UPC_A", "UPC_E", "CODABAR", "ITF"];
    const list = wanted.map(function (name) { return formats[name]; }).filter(function (value) { return value !== undefined; });
    try {
      scanner = list.length ? new Html5Qrcode("reader", { formatsToSupport: list, verbose: false }) : new Html5Qrcode("reader");
      await scanner.start(
        { facingMode: "environment" },
        { fps: 8, qrbox: { width: 260, height: 140 } },
        function (text) {
          if (scanHandled) return;
          scanHandled = true;
          const input = document.getElementById("q");
          state.query = text;
          if (input) input.value = text;
          closeScanner();
          runSearch(true);
        }
      );
    } catch (err) {
      showToast("The camera is not available. Type the part number instead.");
      closeScanner();
    }
  }

  async function closeScanner() {
    await stopScanner();
    const panel = document.getElementById("scanner");
    if (panel) panel.remove();
    const button = document.getElementById("scan");
    if (button) button.textContent = "Scan";
  }

  function paintResults() {
    const box = document.getElementById("results");
    if (!box) return;
    box.replaceChildren();
    if (!state.searched) {
      box.appendChild(h("p", { class: "muted", text: "Type a part number or scan a barcode." }));
      return;
    }
    if (!state.results.length) {
      box.appendChild(h("p", { class: "muted", text: "No parts match that search." }));
      return;
    }
    state.results.forEach(function (product) {
      box.appendChild(h("button", {
        type: "button",
        class: "row",
        onclick: function () { location.hash = "#/p/" + product.id; }
      }, [
        h("span", { class: "grow" }, [
          h("strong", { text: product.name }),
          h("span", { class: "muted", text: product.sku || "No part number" })
        ]),
        product.is_low ? h("span", { class: "pill", text: "Low" }) : null,
        h("span", { class: product.is_low ? "qty low" : "qty", text: product.tracked ? formatQty(product.sellable_qty) : "—" })
      ]));
    });
    if (state.results.length === 20) {
      box.appendChild(h("p", { class: "muted", text: "Keep typing to narrow this down." }));
    }
  }

  async function runSearch(fromScan) {
    const q = state.query.trim();
    if (!q) {
      state.results = [];
      state.searched = false;
      paintResults();
      return;
    }
    try {
      const data = await api("search?q=" + encodeURIComponent(q));
      if (state.query.trim() !== q) return;
      state.results = data.results || [];
      state.searched = true;
      if (fromScan && state.results.length === 1) {
        location.hash = "#/p/" + state.results[0].id;
        return;
      }
      paintResults();
      if (fromScan && !state.results.length) showToast("No part matches that code.");
    } catch (err) {
      showToast(err.message);
    }
  }

  function renderSearch() {
    const input = h("input", {
      id: "q",
      type: "search",
      placeholder: "T450/500-18",
      value: state.query,
      autocomplete: "off",
      enterkeyhint: "search"
    });
    input.addEventListener("input", function () {
      state.query = input.value;
      clearTimeout(state.timer);
      state.timer = setTimeout(function () { runSearch(false); }, 250);
    });
    const form = h("form", {
      class: "search stack",
      onsubmit: function (event) {
        event.preventDefault();
        clearTimeout(state.timer);
        runSearch(false);
      }
    }, [
      h("label", { for: "q" }, ["Part number, barcode, or name"]),
      h("div", { class: "search-row" }, [
        input,
        h("button", { type: "submit", class: "primary", text: "Search" }),
        h("button", {
          type: "button",
          class: "secondary",
          id: "scan",
          text: "Scan",
          onclick: function () {
            if (document.getElementById("scanner")) closeScanner();
            else openScanner();
          }
        })
      ])
    ]);
    view.appendChild(form);
    view.appendChild(h("div", { id: "results", class: "stack" }));
    paintResults();
  }

  function shopLine(product) {
    if (!product.tracked) return "Not counted yet.";
    if (!state.settings || !state.settings.publish) return "Counted. The shop is not publishing stock yet.";
    if (product.shop && Number(product.shop.stock_quantity) > 0) {
      return "On the shop: in stock (" + formatQty(product.shop.stock_quantity) + ").";
    }
    return "On the shop: out of stock.";
  }

  function productView(product) {
    const places = h("div", { class: "places" });
    (product.locations || []).forEach(function (place) {
      places.appendChild(h("div", {}, [
        h("span", { text: place.name + (place.is_sellable ? "" : " (not on the shop)") }),
        h("strong", { text: formatQty(place.qty) })
      ]));
    });

    const location = h("select", { id: "location" });
    (product.locations || []).forEach(function (place) {
      location.appendChild(h("option", { value: String(place.id), text: place.name }));
    });
    const to = h("select", { id: "to-location" });
    (product.locations || []).forEach(function (place) {
      to.appendChild(h("option", { value: String(place.id), text: place.name }));
    });
    if (to.options.length > 1) to.selectedIndex = 1;

    const qty = h("input", { id: "qty", type: "number", inputmode: "decimal", min: "0", step: "any" });
    const note = h("input", { id: "note", type: "text", maxlength: "500", placeholder: "Optional" });
    const barcode = h("input", { id: "barcode", type: "text", maxlength: "64", value: product.barcode || "" });
    const low = h("input", {
      id: "low",
      type: "number",
      inputmode: "decimal",
      min: "0",
      step: "any",
      value: product.low_stock_override == null ? "" : formatQty(product.low_stock_override),
      placeholder: state.settings ? "Shop default " + formatQty(state.settings.default_low) : ""
    });

    const card = h("article", { class: "card" }, [
      h("button", { type: "button", class: "texty", text: "Back to search", onclick: function () { location.hash = "#/"; } }),
      h("div", { class: "part" }, [
        product.image && /^https?:\/\//.test(product.image) ? h("img", { src: product.image, alt: "" }) : h("div"),
        h("div", {}, [
          h("h2", { class: "title", text: product.name }),
          h("p", { class: "muted", text: [product.sku || "No part number", formatMoney(product)].filter(Boolean).join(" · ") }),
          product.is_low ? h("span", { class: "pill", text: "Low stock" }) : null
        ])
      ]),
      h("p", { class: "shop-line", text: shopLine(product) }),
      h("p", { class: "qty", text: "For the shop: " + (product.tracked ? formatQty(product.sellable_qty) : "—") }),
      places
    ]);

    if (!product.countable) {
      card.appendChild(h("p", { class: "muted", text: "Count the specific part number, not the group." }));
    } else {
      card.appendChild(h("label", {}, ["Location", location]));
      card.appendChild(h("label", {}, ["Quantity", qty]));
      card.appendChild(h("label", {}, ["Note", note]));
      const actions = h("div", { class: "actions" }, [
        h("button", { type: "button", class: "good", text: "Goods in", onclick: function () { move(product, "in"); } }),
        h("button", { type: "button", class: "warn", text: "Goods out", onclick: function () { move(product, "out"); } }),
        h("button", { type: "button", class: "solid", text: "Set count", onclick: function () { move(product, "count"); } })
      ]);
      card.appendChild(actions);
      if ((product.locations || []).length > 1) {
        card.appendChild(h("label", {}, ["Transfer to", to]));
        card.appendChild(h("button", { type: "button", class: "secondary", text: "Transfer", onclick: function () { move(product, "transfer"); } }));
      }
    }

    card.appendChild(h("label", {}, ["Barcode", barcode]));
    card.appendChild(h("label", {}, ["Low stock at", low]));
    card.appendChild(h("button", { type: "button", class: "secondary", text: "Save details", onclick: function () { saveDetails(product); } }));
    if (product.permalink && /^https?:\/\//.test(product.permalink)) {
      card.appendChild(h("a", { class: "quiet", href: product.permalink, target: "_blank", rel: "noopener", text: "View on the shop" }));
    }
    return card;
  }

  function formBits() {
    return {
      location: document.getElementById("location"),
      to: document.getElementById("to-location"),
      qty: document.getElementById("qty"),
      note: document.getElementById("note")
    };
  }

  async function move(product, type) {
    const form = formBits();
    if (!form.qty || form.qty.value === "") {
      showToast(type === "count" ? "Enter the quantity you counted." : "Enter a quantity greater than zero.");
      return;
    }
    if (type !== "count" && Number(form.qty.value) <= 0) {
      showToast("Enter a quantity greater than zero.");
      return;
    }
    const body = {
      product_id: product.id,
      location_id: Number(form.location.value),
      type: type,
      qty: form.qty.value,
      note: form.note.value
    };
    if (type === "transfer") body.to_location_id = Number(form.to.value);
    const buttons = view.querySelectorAll("button");
    buttons.forEach(function (button) { button.disabled = true; });
    try {
      const data = await api("movements", { method: "POST", body: body });
      showToast(type === "count" ? "Count saved." : "Stock updated.");
      refreshLowCount();
      view.replaceChildren(productView(data.product));
    } catch (err) {
      showToast(err.message);
      buttons.forEach(function (button) { button.disabled = false; });
    }
  }

  async function saveDetails(product) {
    const barcode = document.getElementById("barcode");
    const low = document.getElementById("low");
    try {
      const data = await api("product", {
        method: "POST",
        body: {
          product_id: product.id,
          barcode: barcode ? barcode.value : "",
          low_stock: low && low.value !== "" ? low.value : ""
        }
      });
      showToast("Details saved.");
      refreshLowCount();
      view.replaceChildren(productView(data));
    } catch (err) {
      showToast(err.message);
    }
  }

  async function renderProduct(id) {
    view.appendChild(h("p", { class: "muted", text: "Loading part…" }));
    try {
      const product = await api("product?id=" + encodeURIComponent(id));
      if (route().name !== "product" || route().id !== id) return;
      view.replaceChildren(productView(product));
    } catch (err) {
      view.replaceChildren(h("p", { class: "error", text: err.message }));
    }
  }

  async function renderLow() {
    view.appendChild(h("p", { class: "muted", text: "Checking low stock…" }));
    try {
      const data = await api("low");
      if (route().name !== "low") return;
      const items = data.items || [];
      state.lowCount = items.length;
      paintNav("low");
      view.replaceChildren();
      if (!items.length) {
        view.appendChild(h("p", { class: "muted", text: "No counted parts are at or below the threshold." }));
        return;
      }
      const list = h("div", { class: "stack" });
      items.forEach(function (product) {
        list.appendChild(h("button", {
          type: "button",
          class: "row",
          onclick: function () { location.hash = "#/p/" + product.id; }
        }, [
          h("span", { class: "grow" }, [
            h("strong", { text: product.name }),
            h("span", { class: "muted", text: (product.sku || "No part number") + " · at " + formatQty(product.low_stock_threshold) })
          ]),
          h("span", { class: "qty low", text: formatQty(product.sellable_qty) })
        ]));
      });
      view.appendChild(list);
    } catch (err) {
      view.replaceChildren(h("p", { class: "error", text: err.message }));
    }
  }

  async function renderActivity() {
    view.appendChild(h("p", { class: "muted", text: "Loading activity…" }));
    try {
      const data = await api("movements?limit=50");
      if (route().name !== "activity") return;
      const rows = data.movements || [];
      view.replaceChildren();
      if (!rows.length) {
        view.appendChild(h("p", { class: "muted", text: "No movements yet. Count a part to start the ledger." }));
        return;
      }
      const list = h("div", { class: "stack" });
      rows.forEach(function (row) {
        const detail = row.type_label + " " + (row.type === "count" ? formatQty(row.qty) + " (" + formatDelta(row.qty_delta) + ")" : formatDelta(row.qty_delta));
        list.appendChild(h("button", {
          type: "button",
          class: "row",
          onclick: function () { location.hash = "#/p/" + row.product_id; }
        }, [
          h("span", { class: "grow" }, [
            h("strong", { text: row.product_name }),
            h("span", { class: "muted", text: [row.sku, row.location_name, row.user_name, row.created_label].filter(Boolean).join(" · ") }),
            row.note ? h("span", { class: "muted", text: row.note }) : null
          ]),
          h("span", { class: "qty", text: detail })
        ]));
      });
      view.appendChild(list);
    } catch (err) {
      view.replaceChildren(h("p", { class: "error", text: err.message }));
    }
  }

  async function refreshLowCount() {
    try {
      const data = await api("low");
      state.lowCount = (data.items || []).length;
      paintNav(route().name);
    } catch (err) { /* the list still works without the badge */ }
  }

  async function saveSettings(next, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return false;
    const data = await api("settings", {
      method: "POST",
      body: {
        publish: next.publish,
        default_low: next.default_low
      }
    });
    state.settings = data;
    return true;
  }

  async function renderSettings() {
    if (!state.settings) {
      view.appendChild(h("p", { class: "error", text: state.bootError || "Settings are not available." }));
      return;
    }
    const settings = state.settings;
    const publish = h("input", { type: "checkbox" });
    publish.checked = !!settings.publish;
    publish.addEventListener("change", async function () {
      const turningOn = publish.checked;
      const message = turningOn
        ? "Publish counted quantities to the shop? Parts you have not counted will stay as they are."
        : "Stop publishing stock? Counted parts go back to the shop settings they had before the first publish.";
      try {
        const saved = await saveSettings({ publish: turningOn, default_low: settings.default_low }, message);
        if (!saved) publish.checked = !turningOn;
        else {
          showToast(turningOn ? "The shop is publishing counted stock." : "The shop is no longer publishing stock.");
          renderSettings();
        }
      } catch (err) {
        publish.checked = !turningOn;
        showToast(err.message);
      }
    });

    const low = h("input", { id: "default-low", type: "number", min: "0", step: "any", value: formatQty(settings.default_low) });
    const name = h("input", { id: "loc-name", type: "text", maxlength: "191" });
    const code = h("input", { id: "loc-code", type: "text", maxlength: "64" });
    const sellable = h("input", { type: "checkbox" });
    sellable.checked = true;

    const locations = h("div", { class: "stack" });
    (settings.locations || []).forEach(function (location) {
      const sell = h("input", { type: "checkbox" });
      const active = h("input", { type: "checkbox" });
      sell.checked = !!location.is_sellable;
      active.checked = !!location.is_active;
      sell.addEventListener("change", function () { updateLocation(location.id, { is_sellable: sell.checked }); });
      active.addEventListener("change", function () { updateLocation(location.id, { is_active: active.checked }); });
      locations.appendChild(h("div", { class: "card" }, [
        h("strong", { text: location.name + (location.code ? " · " + location.code : "") }),
        h("div", { class: "flags" }, [
          h("label", { class: "check" }, [sell, "Counts for the shop"]),
          h("label", { class: "check" }, [active, "Active"])
        ])
      ]));
    });

    view.replaceChildren(h("div", { class: "stack" }, [
      h("section", { class: "card" }, [
        h("h2", { text: "Shop" }),
        h("label", { class: "check" }, [publish, "Publish stock to the shop"]),
        h("p", { class: "muted", text: "When this is on, parts you have counted or received show their quantity on the shop. Above zero is in stock. Zero is out of stock and cannot be added to the cart. Parts you have not counted stay as they are. Quotes are unchanged." })
      ]),
      h("section", { class: "card" }, [
        h("h2", { text: "Low stock" }),
        h("label", {}, ["Shop-wide threshold", low]),
        h("button", {
          type: "button",
          class: "primary",
          text: "Save threshold",
          onclick: async function () {
            try {
              await saveSettings({ publish: state.settings.publish, default_low: low.value });
              refreshLowCount();
              showToast("Threshold saved.");
            } catch (err) {
              showToast(err.message);
            }
          }
        })
      ]),
      h("section", { class: "stack" }, [
        h("h2", { text: "Locations" }),
        locations,
        h("form", {
          class: "card",
          onsubmit: async function (event) {
            event.preventDefault();
            try {
              await api("locations", {
                method: "POST",
                body: { name: name.value, code: code.value, is_sellable: sellable.checked }
              });
              state.settings = await api("settings");
              showToast("Location added.");
              renderSettings();
            } catch (err) {
              showToast(err.message);
            }
          }
        }, [
          h("h2", { text: "Add a location" }),
          h("label", {}, ["Name", name]),
          h("label", {}, ["Code", code]),
          h("label", { class: "check" }, [sellable, "Counts for the shop"]),
          h("button", { type: "submit", class: "primary", text: "Add location" })
        ])
      ])
    ]));
  }

  async function updateLocation(id, fields) {
    try {
      await api("locations/update", { method: "POST", body: Object.assign({ id: id }, fields) });
      state.settings = await api("settings");
      showToast("Location saved.");
    } catch (err) {
      showToast(err.message);
      renderSettings();
    }
  }

  async function render() {
    await stopScanner();
    const current = route();
    paintNav(current.name);
    view.replaceChildren();
    if (state.bootError && !state.settings && current.name !== "search") {
      view.appendChild(h("p", { class: "error", text: state.bootError }));
      return;
    }
    if (current.name === "search") renderSearch();
    else if (current.name === "product") renderProduct(current.id);
    else if (current.name === "low") renderLow();
    else if (current.name === "activity") renderActivity();
    else renderSettings();
  }

  async function boot() {
    try {
      state.settings = await api("settings");
    } catch (err) {
      state.bootError = err.message;
    }
    try {
      const low = await api("low");
      state.lowCount = (low.items || []).length;
    } catch (err) {
      state.lowCount = 0;
    }
    await render();
  }

  window.addEventListener("hashchange", render);
  boot();
})();
