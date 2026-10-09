(function () {
  const S = window.BSStock;
  if (!S) return;
  const h = S.h;
  const api = S.api;
  const view = S.view;
  const toast = S.showToast;
  const formatQty = S.formatQty;
  const local = {
    filter: "active",
    query: "",
    timer: 0,
    partTimer: 0,
    mechanics: null,
    job: null,
    pickKey: ""
  };
  const filters = [
    ["active", "Active"],
    ["completed", "Completed"],
    ["invoiced", "Invoiced"],
    ["cancelled", "Cancelled"],
    ["all", "All"]
  ];
  const working = [
    ["open", "Open"],
    ["in_progress", "In progress"],
    ["waiting_parts", "Waiting for parts"]
  ];

  function money(value) {
    if (value == null || value === "") return "—";
    const n = Number(value);
    if (!Number.isFinite(n)) return "—";
    return "R " + n.toFixed(2);
  }

  function newKey() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") return window.crypto.randomUUID().replace(/-/g, "");
    return Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
  }

  function unitText(unit) {
    return unit && unit.toLowerCase() !== "each" ? unit : "";
  }

  function lineTitle(line) {
    return unitText(line.unit) ? line.qty_label + " " + line.name : formatQty(line.qty) + " × " + line.name;
  }

  function pill(text) {
    return h("span", { class: "pill", text: text });
  }

  function lock(on) {
    view.querySelectorAll("button").forEach(function (button) { button.disabled = on; });
  }

  function stillOn(name, id) {
    const current = S.route();
    return current.name === name && (id == null || current.id === id);
  }

  async function loadMechanics(force) {
    if (local.mechanics && !force) return local.mechanics;
    const data = await api("mechanics");
    local.mechanics = data.mechanics || [];
    return local.mechanics;
  }

  function renderList() {
    const input = h("input", {
      id: "job-q",
      type: "search",
      placeholder: "Customer, registration, or JC-0001",
      value: local.query,
      autocomplete: "off",
      enterkeyhint: "search"
    });
    input.addEventListener("input", function () {
      local.query = input.value;
      clearTimeout(local.timer);
      local.timer = setTimeout(loadList, 250);
    });
    const chips = h("div", { class: "chips" });
    filters.forEach(function (item) {
      chips.appendChild(h("button", {
        type: "button",
        class: local.filter === item[0] ? "chip on" : "chip",
        text: item[1],
        onclick: function () {
          local.filter = item[0];
          renderList();
        }
      }));
    });
    const box = h("div", { id: "new-job" });
    view.replaceChildren(h("div", { class: "stack" }, [
      h("div", { class: "search-row" }, [
        input,
        h("button", { type: "button", class: "primary", text: "New job", onclick: function () { toggleNewJob(box); } })
      ]),
      box,
      chips,
      h("div", { id: "job-list", class: "stack" }, [h("p", { class: "muted", text: "Loading jobs…" })])
    ]));
    loadList();
  }

  async function loadList() {
    const box = document.getElementById("job-list");
    if (!box) return;
    const q = local.query.trim();
    try {
      const data = await api("jobs?status=" + encodeURIComponent(local.filter) + "&q=" + encodeURIComponent(q) + "&limit=50");
      if (!stillOn("jobs") || local.query.trim() !== q) return;
      const jobs = data.jobs || [];
      box.replaceChildren();
      if (!jobs.length) {
        box.appendChild(h("p", { class: "muted", text: q ? "No jobs match that search." : "No jobs here yet." }));
        return;
      }
      jobs.forEach(function (job) {
        box.appendChild(h("button", {
          type: "button",
          class: "row",
          onclick: function () { location.hash = "#/job/" + job.id; }
        }, [
          h("span", { class: "grow" }, [
            h("strong", { text: job.job_number + " · " + job.customer_name }),
            h("span", { class: "muted", text: [job.bike, job.registration, job.created_label].filter(Boolean).join(" · ") })
          ]),
          h("span", { class: "pill status-" + job.status, text: job.status_label })
        ]));
      });
    } catch (err) {
      box.replaceChildren(h("p", { class: "error", text: err.message }));
    }
  }

  function headerFields(job, mechanics) {
    const inputs = {};
    function field(key, label, props, tag) {
      const value = job && job[key] != null ? String(job[key]) : "";
      const input = h(tag || "input", Object.assign({ id: "f-" + key, value: value }, props || {}));
      inputs[key] = input;
      return h("label", {}, [label, input]);
    }
    const checks = [];
    const chosen = (job && job.mechanic_ids) || [];
    const crew = h("div", { class: "flags" });
    (mechanics || []).forEach(function (m) {
      const box = h("input", { type: "checkbox", value: String(m.id) });
      box.checked = chosen.indexOf(m.id) !== -1;
      checks.push(box);
      crew.appendChild(h("label", { class: "check" }, [box, m.name]));
    });
    const nodes = [
      field("customer_name", "Customer name", { type: "text", maxlength: "191", autocomplete: "off" }),
      field("customer_phone", "Phone", { type: "tel", maxlength: "40", autocomplete: "off" }),
      field("customer_email", "Email (optional)", { type: "email", maxlength: "191", autocomplete: "off" }),
      field("bike_make", "Make", { type: "text", maxlength: "64", placeholder: "Honda" }),
      field("bike_model", "Model", { type: "text", maxlength: "100", placeholder: "CRF250L" }),
      field("bike_year", "Year", { type: "text", inputmode: "numeric", maxlength: "4", placeholder: "2019" }),
      field("registration", "Registration", { type: "text", maxlength: "32" }),
      field("vin", "VIN / frame (optional)", { type: "text", maxlength: "64" }),
      field("odometer", "Odometer km (optional)", { type: "text", inputmode: "numeric", maxlength: "10" }),
      field("description", "Work to do", { maxlength: "5000" }, "textarea"),
      field("notes", "Notes", { maxlength: "5000" }, "textarea"),
      (mechanics || []).length ? h("div", { class: "stack" }, [h("span", { class: "muted", text: "Mechanics" }), crew]) : null
    ];
    function collect() {
      const body = {};
      Object.keys(inputs).forEach(function (key) { body[key] = inputs[key].value; });
      body.mechanic_ids = checks.filter(function (box) { return box.checked; }).map(function (box) { return Number(box.value); });
      return body;
    }
    return { nodes: nodes, collect: collect };
  }

  async function toggleNewJob(box) {
    if (box.firstChild) {
      box.replaceChildren();
      return;
    }
    let mechanics = [];
    try { mechanics = await loadMechanics(); } catch (err) { mechanics = []; }
    const fields = headerFields({ mechanic_ids: [BS_STOCK.userId] }, mechanics);
    const form = h("form", {
      class: "card",
      onsubmit: async function (event) {
        event.preventDefault();
        lock(true);
        try {
          const job = await api("jobs", { method: "POST", body: fields.collect() });
          toast(job.job_number + " opened.");
          location.hash = "#/job/" + job.id;
        } catch (err) {
          toast(err.message);
          lock(false);
        }
      }
    }, [h("h2", { text: "New job" })].concat(fields.nodes, [
      h("button", { type: "submit", class: "primary", text: "Open job" })
    ]));
    box.replaceChildren(form);
    const first = document.getElementById("f-customer_name");
    if (first) first.focus();
  }

  async function renderJob(id) {
    view.appendChild(h("p", { class: "muted", text: "Loading job…" }));
    try {
      const results = await Promise.all([
        api("job?id=" + encodeURIComponent(id)),
        loadMechanics().catch(function () { return []; })
      ]);
      if (!stillOn("job", id)) return;
      paintJob(results[0]);
    } catch (err) {
      view.replaceChildren(h("p", { class: "error", text: err.message }));
    }
  }

  function paintJob(job) {
    if (!stillOn("job", job.id)) return;
    local.job = job;
    S.stopScanner();
    view.replaceChildren(h("div", { class: "stack" }, [
      h("button", { type: "button", class: "texty", text: "Back to jobs", onclick: function () { location.hash = "#/jobs"; } }),
      headerCard(job),
      job.working ? addCard(job) : null,
      linesCard(job),
      labourCard(job),
      costingCard(job),
      summaryCard(job),
      actionsCard(job),
      movementsCard(job)
    ]));
  }

  function headerCard(job) {
    const phone = String(job.customer_phone || "").replace(/[^\d+]/g, "");
    const bike = [
      job.bike,
      job.registration ? "Reg " + job.registration : "",
      job.vin ? "VIN " + job.vin : "",
      job.odometer != null ? formatQty(job.odometer) + " km" : ""
    ].filter(Boolean).join(" · ");
    const crew = (job.mechanics || []).map(function (m) { return m.name; }).join(", ");
    const card = h("article", { class: "card" }, [
      h("div", { class: "job-head" }, [
        h("h2", { class: "title", text: job.job_number }),
        h("span", { class: "pill status-" + job.status, text: job.status_label })
      ]),
      h("div", { class: "grow" }, [
        h("strong", { text: job.customer_name }),
        phone ? h("a", { class: "quiet", href: "tel:" + phone, text: job.customer_phone }) : null,
        job.customer_email ? h("span", { class: "muted", text: job.customer_email }) : null
      ]),
      bike ? h("p", { class: "muted", text: bike }) : null,
      job.description ? h("p", { class: "prewrap", text: job.description }) : null,
      h("p", { class: "muted", text: crew ? "Mechanics: " + crew : "No mechanic assigned yet." }),
      job.notes ? h("p", { class: "prewrap muted", text: "Notes: " + job.notes }) : null,
      h("p", { class: "muted", text: [
        "Opened " + job.created_label + (job.created_by_name ? " by " + job.created_by_name : ""),
        job.completed_label ? "Completed " + job.completed_label + (job.completed_by_name ? " by " + job.completed_by_name : "") : ""
      ].filter(Boolean).join(" · ") })
    ]);

    if (job.working) {
      const status = h("select", { id: "job-status" });
      working.forEach(function (item) { status.appendChild(h("option", { value: item[0], text: item[1] })); });
      status.value = job.status;
      status.addEventListener("change", function () { saveHeader(job, { status: status.value }, "Status saved."); });
      card.appendChild(h("label", {}, ["Status", status]));
    }

    if (job.status !== "invoiced" && job.status !== "cancelled") {
      const editBox = h("div");
      card.appendChild(h("button", {
        type: "button",
        class: "secondary",
        text: "Edit details",
        onclick: function () {
          if (editBox.firstChild) {
            editBox.replaceChildren();
            return;
          }
          const fields = headerFields(job, local.mechanics || []);
          editBox.replaceChildren(h("div", { class: "stack" }, fields.nodes.concat([
            h("button", { type: "button", class: "primary", text: "Save details", onclick: function () { saveHeader(job, fields.collect(), "Job saved."); } })
          ])));
        }
      }));
      card.appendChild(editBox);
    }
    return card;
  }

  async function saveHeader(job, fields, message) {
    lock(true);
    try {
      const data = await api("job", { method: "POST", body: Object.assign({ id: job.id }, fields) });
      toast(message);
      if (fields.status === "cancelled") S.refreshLowCount();
      paintJob(data);
    } catch (err) {
      toast(err.message);
      lock(false);
      if (fields.status && local.job) paintJob(local.job);
    }
  }

  function addCard(job) {
    const input = h("input", {
      id: "part-q",
      type: "search",
      placeholder: "Part number, barcode, or name",
      autocomplete: "off",
      enterkeyhint: "search"
    });
    input.addEventListener("input", function () {
      clearTimeout(local.partTimer);
      local.partTimer = setTimeout(function () { findParts(input.value, false); }, 250);
    });
    const form = h("form", {
      class: "search stack",
      onsubmit: function (event) {
        event.preventDefault();
        clearTimeout(local.partTimer);
        findParts(input.value, false);
      }
    }, [
      h("label", { for: "part-q" }, ["Add a part or sundry to " + job.job_number]),
      h("div", { class: "search-row" }, [
        input,
        h("button", { type: "submit", class: "primary", text: "Search" }),
        h("button", {
          type: "button",
          class: "secondary",
          id: "scan",
          text: "Scan",
          onclick: function () {
            if (document.getElementById("scanner")) S.closeScanner();
            else S.openScanner(function (code) {
              input.value = code;
              findParts(code, true);
            });
          }
        })
      ])
    ]);
    return h("article", { class: "card" }, [
      form,
      h("div", { id: "part-results", class: "stack" }),
      h("div", { id: "picked" })
    ]);
  }

  async function findParts(raw, fromScan) {
    const q = String(raw || "").trim();
    const box = document.getElementById("part-results");
    if (!box) return;
    if (!q) {
      box.replaceChildren();
      return;
    }
    try {
      const data = await api("search?q=" + encodeURIComponent(q));
      const input = document.getElementById("part-q");
      if (!input || input.value.trim() !== q) return;
      const list = data.results || [];
      box.replaceChildren();
      if (fromScan && list.length === 1) {
        pick(list[0]);
        return;
      }
      if (!list.length) {
        box.appendChild(h("p", { class: "muted", text: "No parts match that search." }));
        if (fromScan) toast("No part matches that code.");
        return;
      }
      list.forEach(function (product) {
        box.appendChild(h("button", { type: "button", class: "row", onclick: function () { pick(product); } }, [
          h("span", { class: "grow" }, [
            h("strong", { text: product.name }),
            h("span", { class: "muted", text: [product.sku || "No part number", product.sundry ? "Sundry" : ""].filter(Boolean).join(" · ") })
          ]),
          h("span", { class: "qty", text: formatQty(product.sellable_qty) + (unitText(product.unit) ? " " + product.unit : "") })
        ]));
      });
    } catch (err) {
      toast(err.message);
    }
  }

  function pick(product) {
    if (!product.countable) {
      toast("Count the specific part number, not the group.");
      return;
    }
    local.pickKey = newKey();
    const results = document.getElementById("part-results");
    if (results) results.replaceChildren();
    const panel = document.getElementById("picked");
    if (!panel) return;
    const unit = unitText(product.unit);
    const from = h("select", { id: "pick-location" });
    (product.locations || []).forEach(function (place) {
      from.appendChild(h("option", { value: String(place.id), text: place.name + " (" + formatQty(place.qty) + (unit ? " " + unit : "") + " on hand)" }));
    });
    if (local.job && local.job.default_location_id && from.querySelector('option[value="' + Number(local.job.default_location_id) + '"]')) {
      from.value = String(local.job.default_location_id);
    }
    const qty = h("input", { id: "pick-qty", type: "number", inputmode: "decimal", min: "0", step: product.sundry ? "any" : "1", value: "1" });
    panel.replaceChildren(h("div", { class: "picked stack" }, [
      h("div", { class: "grow" }, [
        h("strong", { text: product.name }),
        h("span", { class: "muted", text: [
          product.sku || "No part number",
          product.sundry ? "Workshop sundry" : "Part",
          product.cost == null ? "no cost price" : "Cost " + money(product.cost),
          product.price !== "" && product.price != null ? "Shop " + money(product.price) : "no shop price"
        ].join(" · ") })
      ]),
      h("label", {}, ["Quantity" + (unit ? " (" + unit + ")" : ""), qty]),
      h("label", {}, ["Take from", from]),
      h("div", { class: "actions" }, [
        h("button", { type: "button", class: "warn", text: "Add to job", onclick: function () { book(product, qty, from); } }),
        h("button", { type: "button", class: "secondary", text: "Clear", onclick: function () { panel.replaceChildren(); } })
      ])
    ]));
    qty.focus();
  }

  async function book(product, qty, from) {
    if (qty.value === "" || !(Number(qty.value) > 0)) {
      toast("Enter a quantity greater than zero.");
      return;
    }
    lock(true);
    try {
      const data = await api("job/lines", {
        method: "POST",
        body: {
          job_id: local.job.id,
          product_id: product.id,
          qty: qty.value,
          location_id: Number(from.value),
          request_key: local.pickKey
        }
      });
      toast("Added to " + data.job_number + ".");
      S.refreshLowCount();
      paintJob(data);
    } catch (err) {
      toast(err.message);
      lock(false);
    }
  }

  function linesCard(job) {
    const parts = job.lines.filter(function (line) { return line.kind !== "sundry"; });
    const sundries = job.lines.filter(function (line) { return line.kind === "sundry"; });
    return h("article", { class: "card" }, [
      h("h2", { text: "Parts" }),
      lineGroup(job, parts, "No parts on this job yet."),
      h("h2", { text: "Sundries" }),
      lineGroup(job, sundries, "No sundries on this job yet.")
    ]);
  }

  function lineGroup(job, lines, empty) {
    if (!lines.length) return h("p", { class: "muted", text: empty });
    const box = h("div", { class: "lines" });
    lines.forEach(function (line) {
      box.appendChild(h("div", { class: "line" }, [
        h("div", { class: "grow" }, [
          h("strong", { text: lineTitle(line) }),
          h("span", { class: "muted", text: [line.sku, line.location_name].filter(Boolean).join(" · ") }),
          h("span", { class: "muted" }, [
            "Cost ",
            line.cost_missing ? pill("no cost price") : money(line.cost),
            " · Charge ",
            line.price_missing ? pill("no shop price") : money(line.charge)
          ])
        ]),
        job.working ? h("div", { class: "line-actions" }, [
          h("button", { type: "button", class: "secondary", text: "Return", onclick: function () { returnLine(job, line, false); } }),
          h("button", { type: "button", class: "texty", text: "Remove", onclick: function () { returnLine(job, line, true); } })
        ]) : null
      ]));
    });
    return box;
  }

  async function returnLine(job, line, all) {
    let qty = "";
    if (all) {
      if (!window.confirm("Remove " + line.name + " from the job and put " + line.qty_label + " back at " + line.location_name + "?")) return;
    } else {
      const answer = window.prompt("How much goes back to " + line.location_name + "? Up to " + line.qty_label + ".", formatQty(line.qty));
      if (answer == null) return;
      if (answer.trim() === "" || !(Number(answer) > 0)) {
        toast("Enter a quantity greater than zero.");
        return;
      }
      qty = answer.trim();
    }
    lock(true);
    try {
      const data = await api("job/lines/return", {
        method: "POST",
        body: { job_id: job.id, line_id: line.id, qty: qty, request_key: newKey() }
      });
      toast("Stock returned.");
      S.refreshLowCount();
      paintJob(data);
    } catch (err) {
      toast(err.message);
      lock(false);
    }
  }

  function labourCard(job) {
    const mechanics = local.mechanics || [];
    const list = h("div", { class: "lines" });
    if (!job.labour.length) list.appendChild(h("p", { class: "muted", text: "No labour logged yet." }));
    job.labour.forEach(function (entry) {
      list.appendChild(h("div", { class: "line" }, [
        h("div", { class: "grow" }, [
          h("strong", { text: formatQty(entry.hours) + " h · " + entry.mechanic }),
          h("span", { class: "muted", text: [entry.date_label, entry.note].filter(Boolean).join(" · ") }),
          h("span", { class: "muted" }, [
            "Cost ",
            entry.cost == null ? pill("no cost rate") : money(entry.cost),
            " · Charge ",
            entry.charge == null ? pill("no charge-out rate") : money(entry.charge)
          ])
        ]),
        job.working ? h("button", { type: "button", class: "texty", text: "Delete", onclick: function () { deleteLabour(job, entry); } }) : null
      ]));
    });
    const card = h("article", { class: "card" }, [h("h2", { text: "Labour" }), list]);
    if (!job.working) return card;
    if (!mechanics.length) {
      card.appendChild(h("p", { class: "muted", text: "No mechanics found. Mechanics are users who can open the stock app." }));
      return card;
    }
    const who = h("select", { id: "lab-user" });
    mechanics.forEach(function (m) { who.appendChild(h("option", { value: String(m.id), text: m.name })); });
    if (mechanics.some(function (m) { return m.id === BS_STOCK.userId; })) who.value = String(BS_STOCK.userId);
    const date = h("input", { id: "lab-date", type: "date", value: BS_STOCK.today });
    const hours = h("input", { id: "lab-hours", type: "number", inputmode: "decimal", min: "0", max: "24", step: "any", placeholder: "1.5" });
    const note = h("input", { id: "lab-note", type: "text", maxlength: "500", placeholder: "Optional" });
    card.appendChild(h("label", {}, ["Mechanic", who]));
    card.appendChild(h("label", {}, ["Date", date]));
    card.appendChild(h("label", {}, ["Hours", hours]));
    card.appendChild(h("label", {}, ["Note", note]));
    card.appendChild(h("button", {
      type: "button",
      class: "primary",
      text: "Add labour",
      onclick: async function () {
        if (hours.value === "" || !(Number(hours.value) > 0)) {
          toast("Enter the hours, like 1.5.");
          return;
        }
        lock(true);
        try {
          const data = await api("job/labour", {
            method: "POST",
            body: { job_id: job.id, user_id: Number(who.value), work_date: date.value, hours: hours.value, note: note.value }
          });
          toast("Labour added.");
          paintJob(data);
        } catch (err) {
          toast(err.message);
          lock(false);
        }
      }
    }));
    return card;
  }

  async function deleteLabour(job, entry) {
    if (!window.confirm("Delete " + formatQty(entry.hours) + " h by " + entry.mechanic + "?")) return;
    lock(true);
    try {
      const data = await api("job/labour/delete", { method: "POST", body: { job_id: job.id, labour_id: entry.id } });
      toast("Labour deleted.");
      paintJob(data);
    } catch (err) {
      toast(err.message);
      lock(false);
    }
  }

  function costingCard(job) {
    const c = job.costing;
    const grid = h("div", { class: "costs" }, [
      h("span"),
      h("strong", { text: "Cost" }),
      h("strong", { text: "Charge" })
    ]);
    [
      ["Parts", c.parts, c.parts.missing_cost],
      ["Sundries", c.sundries, c.sundries.missing_cost],
      ["Labour " + formatQty(c.labour.hours) + " h", c.labour, c.labour.missing_cost]
    ].forEach(function (row) {
      grid.appendChild(h("span", { text: row[0] }));
      grid.appendChild(h("span", { class: "qty", text: money(row[1].cost) + (row[2] ? " *" : "") }));
      grid.appendChild(h("span", { class: "qty", text: money(row[1].charge) }));
    });
    grid.appendChild(h("strong", { text: "Total" }));
    grid.appendChild(h("strong", { class: "qty", text: money(c.total.cost) }));
    grid.appendChild(h("strong", { class: "qty", text: money(c.total.charge) }));
    const margin = c.total.margin_percent == null ? "—" : formatQty(c.total.margin_percent) + "%";
    return h("article", { class: "card" }, [
      h("h2", { text: "Costing" }),
      grid,
      h("p", { class: "qty", text: "Gross profit " + money(c.total.gross_profit) + " · Margin " + margin }),
      c.warnings.length ? h("div", { class: "stack" }, c.warnings.map(function (text) { return h("p", { class: "warning", text: "* " + text }); })) : null,
      h("p", { class: "muted", text: "Costs exclude VAT. Charges use the shop price when the stock was booked and the charge-out rate when labour was logged." })
    ]);
  }

  function summaryCard(job) {
    return h("article", { class: "card" }, [
      h("h2", { text: "Summary" }),
      h("p", { class: "prewrap", text: job.summary }),
      h("button", { type: "button", class: "secondary", text: "Copy summary", onclick: function () { copyText(job.summary); } })
    ]);
  }

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      toast("Summary copied.");
    } catch (err) {
      const area = h("textarea", { readonly: true });
      area.value = text;
      document.body.appendChild(area);
      area.select();
      const ok = document.execCommand && document.execCommand("copy");
      area.remove();
      toast(ok ? "Summary copied." : "Copy did not work. Select the text instead.");
    }
  }

  function actionsCard(job) {
    const buttons = [];
    if (job.working) {
      buttons.push(h("button", { type: "button", class: "good", text: "Mark completed", onclick: function () { saveHeader(job, { status: "completed" }, "Job completed."); } }));
    }
    if (job.status === "completed") {
      buttons.push(h("button", { type: "button", class: "secondary", text: "Reopen", onclick: function () { saveHeader(job, { status: "in_progress" }, "Job reopened."); } }));
    }
    if (job.can_invoice) {
      buttons.push(h("button", { type: "button", class: "solid", text: "Create WooCommerce order", onclick: function () { invoice(job); } }));
    }
    if (job.status !== "invoiced" && job.status !== "cancelled") {
      buttons.push(h("button", { type: "button", class: "warn", text: "Cancel job", onclick: function () { cancelJob(job); } }));
    }
    const nodes = [];
    if (buttons.length) nodes.push(h("div", { class: "actions" }, buttons));
    if (job.order_id) {
      nodes.push(job.order_url && /^https?:\/\//.test(job.order_url)
        ? h("a", { class: "quiet", href: job.order_url, target: "_blank", rel: "noopener", text: "Open WooCommerce order #" + job.order_id })
        : h("p", { class: "muted", text: "WooCommerce order #" + job.order_id }));
    }
    if (job.status === "cancelled") nodes.push(h("p", { class: "muted", text: "This job was cancelled." }));
    return nodes.length ? h("article", { class: "card" }, nodes) : null;
  }

  async function invoice(job) {
    const warn = job.costing.warnings.length ? "\n\n" + job.costing.warnings.join("\n") : "";
    const text = "Create a pending-payment WooCommerce order for " + money(job.costing.total.charge) + "?\n\nStock on this job was already taken out, so the order will not deduct it again. The job becomes invoiced and can no longer change." + warn;
    if (!window.confirm(text)) return;
    lock(true);
    try {
      const data = await api("job/invoice", { method: "POST", body: { job_id: job.id } });
      toast("Order #" + data.order_id + " created.");
      paintJob(data);
    } catch (err) {
      toast(err.message);
      lock(false);
    }
  }

  function cancelJob(job) {
    const text = job.lines.length
      ? "Cancel " + job.job_number + " and put all its parts and sundries back where they came from?"
      : "Cancel " + job.job_number + "?";
    if (!window.confirm(text)) return;
    saveHeader(job, { status: "cancelled", return_stock: true }, "Job cancelled.");
  }

  function movementsCard(job) {
    const rows = job.movements || [];
    if (!rows.length) return null;
    const list = h("div", { class: "lines" });
    rows.forEach(function (row) {
      list.appendChild(h("div", { class: "line" }, [
        h("div", { class: "grow" }, [
          h("strong", { text: row.product_name }),
          h("span", { class: "muted", text: [row.note, row.location_name, row.user_name, row.created_label].filter(Boolean).join(" · ") })
        ]),
        h("span", { class: "qty", text: S.formatDelta(row.qty_delta) })
      ]));
    });
    return h("article", { class: "card" }, [h("h2", { text: "Stock movements" }), list]);
  }

  function rateInput(id, value, placeholder) {
    return h("input", {
      id: id,
      type: "number",
      inputmode: "decimal",
      min: "0",
      step: "0.01",
      value: value == null ? "" : Number(value).toFixed(2),
      placeholder: placeholder || "Not set"
    });
  }

  function settingsSection(page) {
    const rates = (S.state.settings && S.state.settings.labour) || {};
    const cost = rateInput("rate-cost", rates.cost_rate);
    const charge = rateInput("rate-charge", rates.charge_rate);
    const crew = h("div", { class: "stack" }, [h("p", { class: "muted", text: "Loading mechanics…" })]);
    page.appendChild(h("section", { class: "card" }, [
      h("h2", { text: "Workshop labour" }),
      h("p", { class: "muted", text: "Hourly rates in rand. A mechanic's own rate wins over the default. Rates are copied onto each labour entry when it is saved, so changing them later does not rewrite old jobs." }),
      h("label", {}, ["Default cost rate (what an hour costs Bike Sense)", cost]),
      h("label", {}, ["Default charge-out rate (what the customer pays per hour)", charge]),
      h("button", {
        type: "button",
        class: "primary",
        text: "Save default rates",
        onclick: async function () {
          try {
            S.state.settings = await api("settings", { method: "POST", body: { labour_cost_rate: cost.value, labour_charge_rate: charge.value } });
            toast("Default rates saved.");
            paintCrew(crew, await loadMechanics(true));
          } catch (err) {
            toast(err.message);
          }
        }
      }),
      h("h2", { text: "Mechanic rates" }),
      crew
    ]));
    loadMechanics(true).then(function (list) { paintCrew(crew, list); }).catch(function (err) {
      crew.replaceChildren(h("p", { class: "error", text: err.message }));
    });
  }

  function paintCrew(box, mechanics) {
    box.replaceChildren();
    if (!mechanics.length) {
      box.appendChild(h("p", { class: "muted", text: "No mechanics found. Mechanics are users who can open the stock app." }));
      return;
    }
    const rates = (S.state.settings && S.state.settings.labour) || {};
    mechanics.forEach(function (m) {
      const cost = rateInput("cost-" + m.id, m.cost_rate, rates.cost_rate == null ? "Not set" : "Default " + Number(rates.cost_rate).toFixed(2));
      const charge = rateInput("charge-" + m.id, m.charge_rate, rates.charge_rate == null ? "Not set" : "Default " + Number(rates.charge_rate).toFixed(2));
      box.appendChild(h("div", { class: "rate-row" }, [
        h("strong", { text: m.name }),
        h("label", {}, ["Cost / h", cost]),
        h("label", {}, ["Charge / h", charge]),
        h("button", {
          type: "button",
          class: "secondary",
          text: "Save",
          onclick: async function () {
            try {
              const data = await api("mechanics", { method: "POST", body: { user_id: m.id, cost_rate: cost.value, charge_rate: charge.value } });
              local.mechanics = data.mechanics || [];
              toast("Rates saved for " + m.name + ".");
            } catch (err) {
              toast(err.message);
            }
          }
        })
      ]));
    });
  }

  S.jobs = {
    list: renderList,
    job: renderJob,
    settings: settingsSection
  };
})();
