<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Space Trader</title>
<style>
:root {
    --fg: #0f0;
    --bg: #000;
    --panel: #050505;
    --line: #0f0;
    --neg: #f55;
    --pos: #5f5;
    --warn: #ff0;
    --muted: #88ff88;
}

* { box-sizing: border-box; }
html, body { height: 100%; }
body {
    margin: 0;
    padding: 12px;
    background: var(--bg);
    color: var(--fg);
    font-family: Arial, sans-serif;
}

h2 { margin: 0 0 12px 0; font-size: 22px; }

.app {
    display: grid;
    grid-template-columns: minmax(320px, 1fr) 360px;
    grid-template-rows: auto 1fr auto auto;
    grid-template-areas:
        "status target"
        "map target"
        "exchange exchange"
        "console console";
    gap: 10px;
    align-items: stretch;
}

.panel {
    border: 1px solid var(--line);
    background: var(--panel);
    padding: 10px;
    min-width: 0;
}

#status { grid-area: status; }
#target { grid-area: target; height: 100%; overflow: auto; }
#mapPanel { grid-area: map; min-height: 280px; }
#market { grid-area: exchange; }
#console { grid-area: console; }

.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th, td {
    border: 1px solid var(--line);
    padding: 6px;
    text-align: center;
    vertical-align: middle;
    word-wrap: break-word;
}
button {
    margin: 2px;
    background: #101010;
    color: var(--fg);
    border: 1px solid var(--line);
    padding: 4px 8px;
    cursor: pointer;
}
button:hover { background: #1c1c1c; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
.bad { color: var(--neg); }
.goodprice { color: var(--pos); }
.small { font-size: 12px; opacity: 0.9; }
.console {
    max-height: 240px;
    overflow-y: auto;
    background: #111;
    font-family: monospace;
}
.log-pos { color: var(--pos); }
.log-neg { color: var(--neg); }
.log-neutral { color: var(--fg); }
.log-warn { color: var(--warn); }

.map-title {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: baseline;
    margin-bottom: 8px;
}
.map-shell {
    position: relative;
    width: 100%;
    height: 280px;
    border: 1px solid var(--line);
    overflow: hidden;
    background:
        radial-gradient(circle at 50% 50%, rgba(0,255,0,0.06), transparent 55%),
        linear-gradient(180deg, rgba(0,255,0,0.04), rgba(0,0,0,0));
}
.map-node {
    position: absolute;
    transform: translate(-50%, -50%);
    font-size: 11px;
    text-align: center;
    line-height: 1.1;
    white-space: nowrap;
    cursor: pointer;
    user-select: none;
}
.map-node .dot {
    display: block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin: 0 auto 3px auto;
    background: currentColor;
    box-shadow: 0 0 8px currentColor;
}
.map-node.current .dot { width: 12px; height: 12px; }
.map-node.selected { text-decoration: underline; }
.route-line {
    position: absolute;
    inset: 0;
    pointer-events: none;
}
.base-price-table { margin-top: 8px; }
.base-price-table td, .base-price-table th { font-size: 12px; }

@media (max-width: 900px) {
    .app {
        grid-template-columns: 1fr;
        grid-template-areas:
            "status"
            "target"
            "map"
            "exchange"
            "console";
    }
    #target { height: auto; }
    #mapPanel { min-height: 240px; }
}
</style>
</head>
<body>
<h2>Space Trader</h2>

<div class="app">
    <div class="panel" id="status"></div>
    <div class="panel" id="target"></div>

    <div class="panel" id="mapPanel">
        <div class="map-title">
            <b>Map</b>
            <span class="small">Click a location to target it</span>
        </div>
        <div class="map-shell" id="mapShell"></div>
    </div>

    <div class="panel" id="market"></div>
    <div class="panel console" id="console"></div>
</div>

<script>
/* =====================
   STATE
   ===================== */
const state = {
    location: "Earth",
    credits: 1000,
    fuel: 20,
    maxFuel: 20,
    cargoSpace: 20,
    cargo: {},
    day: 1
};

let heat = 0;
let rumors = []; // { loc, item, strength, expires }
let prices = {};
let logs = [];
let target = null;
let visitedLocations = new Set(["Earth"]);

/* =====================
   COMMODITIES
   ===================== */
const goods = [
    { name: "Food", base: 10, contraband: false },
    { name: "Ore", base: 25, contraband: false },
    { name: "Tech", base: 100, contraband: false },
    { name: "Luxury", base: 150, contraband: false },
    { name: "Gas", base: 60, contraband: false },
    { name: "Spice", base: 250, contraband: true },
    { name: "Weapons", base: 500, contraband: true }
];

function buildBasePrices(mods) {
    const out = {};
    for (const g of goods) {
        const mod = mods[g.name] ?? 1;
        out[g.name] = Math.max(1, Math.round(g.base * mod));
    }
    return out;
}

/* =====================
   WORLD DATA
   ===================== */
const locations = {
    Earth: {
        dist: 0,
        bonus: "Tech",
        fuelRate: 5,
        x: 18,
        y: 80,
        faction: "Federation",
        police: 3,
        lore: "Core trade hub. Stable and expensive.",
        basePrices: buildBasePrices({ Food: 1.25, Ore: 1.15, Tech: 0.75, Luxury: 1.35, Gas: 1.05, Spice: 1.6, Weapons: 1.8 })
    },
    Mars: {
        dist: 3,
        bonus: "Ore",
        fuelRate: 6,
        x: 32,
        y: 70,
        faction: "Miners Guild",
        police: 2,
        lore: "Industrial mining world.",
        basePrices: buildBasePrices({ Food: 1.2, Ore: 0.7, Tech: 1.25, Luxury: 1.15, Gas: 1.1, Spice: 1.4, Weapons: 1.6 })
    },
    Venus: {
        dist: 2,
        bonus: "Luxury",
        fuelRate: 6,
        x: 25,
        y: 88,
        faction: "Syndicate",
        police: 1,
        lore: "Pleasure world. Weak enforcement.",
        basePrices: buildBasePrices({ Food: 1.35, Ore: 1.25, Tech: 1.1, Luxury: 0.75, Gas: 1.0, Spice: 1.3, Weapons: 1.5 })
    },
    "Jupiter Station": {
        dist: 6,
        bonus: "Food",
        fuelRate: 7,
        x: 56,
        y: 54,
        faction: "Federation",
        police: 3,
        lore: "Mass population demand.",
        basePrices: buildBasePrices({ Food: 0.7, Ore: 1.1, Tech: 1.1, Luxury: 1.2, Gas: 1.0, Spice: 1.2, Weapons: 1.5 })
    },
    Saturn: {
        dist: 7,
        bonus: "Gas",
        fuelRate: 8,
        x: 68,
        y: 42,
        faction: "Independents",
        police: 2,
        lore: "Fuel extraction zone.",
        basePrices: buildBasePrices({ Food: 1.1, Ore: 1.0, Tech: 1.15, Luxury: 1.1, Gas: 0.7, Spice: 1.1, Weapons: 1.4 })
    },
    "Titan Outpost": {
        dist: 8,
        bonus: "Spice",
        fuelRate: 9,
        x: 80,
        y: 30,
        faction: "Cartel",
        police: 1,
        lore: "Contraband hotspot.",
        basePrices: buildBasePrices({ Food: 1.2, Ore: 1.1, Tech: 1.2, Luxury: 1.05, Gas: 1.0, Spice: 0.8, Weapons: 1.3 })
    },
    Neptune: {
        dist: 10,
        bonus: "Weapons",
        fuelRate: 10,
        x: 92,
        y: 18,
        faction: "Warlords",
        police: 0,
        lore: "Lawless arms market.",
        basePrices: buildBasePrices({ Food: 1.4, Ore: 1.2, Tech: 1.3, Luxury: 1.2, Gas: 1.15, Spice: 1.25, Weapons: 0.75 })
    }
};

/* =====================
   TIME / DATE
   ===================== */
function formatDate(dayNumber) {
    const month = Math.floor((dayNumber - 1) / 30) + 1;
    const dayOfMonth = ((dayNumber - 1) % 30) + 1;
    return `Month ${month}, Day ${dayOfMonth}`;
}

/* =====================
   LOGGING
   ===================== */
function log(msg, type = "neutral") {
    logs.unshift({ msg, type });
    if (logs.length > 100) logs.pop();
    renderConsole();
}

function renderConsole() {
    document.getElementById("console").innerHTML = logs
        .map(entry => `<div class="log-${entry.type}">${entry.msg}</div>`)
        .join("");
}

/* =====================
   RUMORS
   ===================== */
function generateRumor() {
    const names = Object.keys(locations);
    const loc = names[Math.floor(Math.random() * names.length)];
    const duration = 2 + Math.floor(Math.random() * 4);
    rumors.push({
        loc,
        item: locations[loc].bonus,
        strength: 1.5 + Math.random(),
        expires: state.day + duration
    });
    log(`Rumor: ${locations[loc].bonus} surge at ${loc} (${duration}d)`, "warn");
}

function cleanupRumors() {
    rumors = rumors.filter(r => r.expires > state.day);
}

/* =====================
   PRICES
   ===================== */
function randPriceFromBase(base) {
    return Math.floor(base * (0.85 + Math.random() * 0.3));
}

function getPrices() {
    const loc = locations[state.location];
    const out = {};

    for (const g of goods) {
        let p = randPriceFromBase(loc.basePrices[g.name]);
        for (const r of rumors) {
            if (r.loc === state.location && r.item === g.name) {
                p *= r.strength;
            }
        }
        out[g.name] = Math.max(1, Math.floor(p));
    }

    return out;
}

/* =====================
   HELPERS
   ===================== */
function cargoUsed() {
    return Object.values(state.cargo).reduce((sum, slot) => sum + slot.qty, 0);
}

function getAvg(itemName) {
    const slot = state.cargo[itemName];
    return (!slot || slot.qty === 0) ? 0 : Math.round(slot.totalSpent / slot.qty);
}

function getPercent(g) {
    const base = locations[state.location].basePrices[g.name];
    return Math.round(((prices[g.name] - base) / base) * 100);
}

function travelDistance(from, to) {
    return Math.abs(locations[to].dist - locations[from].dist);
}

/* =====================
   MAP
   ===================== */
function renderMap() {
    const map = document.getElementById("mapShell");
    const w = map.clientWidth || 300;
    const h = map.clientHeight || 280;
    const px = (pct, size) => (pct / 100) * size;
    let html = "";

    if (target && target !== state.location) {
        const a = locations[state.location];
        const b = locations[target];
        html += `<svg class="route-line" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
            <line x1="${px(a.x, w)}" y1="${px(a.y, h)}" x2="${px(b.x, w)}" y2="${px(b.y, h)}" stroke="#0f0" stroke-width="2" stroke-dasharray="6,5" />
        </svg>`;
    }

    for (const [name, loc] of Object.entries(locations)) {
        const dist = travelDistance(state.location, name);
        const reachable = state.fuel >= dist;
        const isCurrent = name === state.location;
        const isTarget = name === target;
        const color = isCurrent ? "#ff0" : (reachable ? "#0f0" : "#555");
        const hint = rumors.some(r => r.loc === name) ? "⚠ " : "";

        html += `
            <div class="map-node ${isCurrent ? "current" : ""} ${isTarget ? "selected" : ""}"
                 style="left:${loc.x}%; top:${loc.y}%; color:${color}"
                 onclick="setTarget('${name}')">
                <span class="dot"></span>
                <span>${hint}${name}</span>
            </div>
        `;
    }

    map.innerHTML = html;
}

function setTarget(name) {
    target = name;
    renderTarget();
    renderMap();
}

function renderBasePricesTable(locName) {
    const basePrices = locations[locName].basePrices;
    let html = `<div class="base-price-table"><b>Known base prices</b><br><div class="table-wrap"><table>
        <tr><th>Commodity</th><th>Base</th></tr>`;

    for (const g of goods) {
        html += `<tr><td>${g.contraband ? "<span class='bad'>⚠</span> " : ""}${g.name}</td><td>$${basePrices[g.name]}</td></tr>`;
    }

    html += `</table></div><div class="small"><span class='bad'>⚠</span> = Contraband</div></div>`;
    return html;
}

function renderTarget() {
    const panel = document.getElementById("target");
    if (!target) {
        panel.innerHTML = `<b>Target</b><br>Select a location on the map.`;
        return;
    }

    const loc = locations[target];
    const dist = travelDistance(state.location, target);
    const isCurrent = target === state.location;
    const canTravel = state.fuel >= dist;
    const active = rumors.filter(r => r.loc === target);
    const rumorTxt = active.length
        ? active.map(r => `${r.item} +${Math.round((r.strength - 1) * 100)}% (${r.expires - state.day}d left)`).join("<br>")
        : "None";
    const actionLabel = isCurrent ? "Wait 1 Day" : (canTravel ? "Travel" : "Travel (No Fuel)");
    const actionOnClick = isCurrent ? 'waitHere()' : `travel('${target}')`;

    panel.innerHTML = `
        <b>Target</b><br>
        ${isCurrent ? `${target} (Current Location)` : target}<br>
        ${loc.lore}<br>
        Faction: ${loc.faction}<br>
        Police: ${loc.police}<br>
        Distance: ${dist}<br>
        Fuel Needed: ${dist}<br>
        ${isCurrent ? `Wait advances time by 1 day.` : `ETA: ${formatDate(state.day + dist)}`}<br>
        Specialty: ${loc.bonus}<br>
        Rumors:<br>${rumorTxt}<br>
        ${visitedLocations.has(target) ? renderBasePricesTable(target) : `<br><b>Known base prices</b><br>Visit this location to reveal its base commodity prices.`}
        <br>
        <button onclick="${actionOnClick}" ${(!isCurrent && !canTravel) ? 'disabled' : ''}>
            ${actionLabel}
        </button>
    `;
}

/* =====================
   UI
   ===================== */
function updateUI() {
    document.getElementById("status").innerHTML = `
        <b>Status</b><br>
        Location: ${state.location}<br>
        Date: ${formatDate(state.day)}<br>
        Credits: ${state.credits}<br>
        Cargo: ${cargoUsed()}/${state.cargoSpace}<br>
        Fuel: ${state.fuel}/${state.maxFuel} <button onclick="refuel()">Refuel</button><br>
        Heat: ${heat}<br>
        <span class="small">Fuel rate at current location: $${locations[state.location].fuelRate}/unit</span>
    `;

    let marketHTML = `<b>Exchange</b><br><div class="table-wrap"><table>
        <tr>
            <th>Item</th>
            <th>Price</th>
            <th>%</th>
            <th>Owned (Avg)</th>
            <th>Buy</th>
            <th>Sell</th>
        </tr>`;

    for (const g of goods) {
        const owned = state.cargo[g.name]?.qty || 0;
        const avg = getAvg(g.name);
        const pct = getPercent(g);
        const pctClass = pct < 0 ? "goodprice" : "bad";

        marketHTML += `
            <tr>
                <td>${g.contraband ? "<span class='bad'>⚠</span> " : ""}${g.name}</td>
                <td>$${prices[g.name]}</td>
                <td class="${pctClass}">${pct > 0 ? "+" : ""}${pct}%</td>
                <td>${owned} @ $${avg}</td>
                <td>
                    <button onclick="buy('${g.name}')">1</button>
                    <button onclick="buyMax('${g.name}')">All</button>
                </td>
                <td>
                    <button onclick="sell('${g.name}')">1</button>
                    <button onclick="sellAll('${g.name}')">All</button>
                </td>
            </tr>`;
    }

    marketHTML += `</table></div>`;
    document.getElementById("market").innerHTML = marketHTML;

    renderTarget();
    renderMap();
}

/* =====================
   TRADE
   ===================== */
function buy(name) {
    const price = prices[name];
    if (state.credits < price) return;
    if (cargoUsed() >= state.cargoSpace) return;

    if (!state.cargo[name]) state.cargo[name] = { qty: 0, totalSpent: 0 };
    state.cargo[name].qty += 1;
    state.cargo[name].totalSpent += price;
    state.credits -= price;

    log(`Buy 1 ${name} @ $${price}`, "neutral");
    updateUI();
}

function buyMax(name) {
    const price = prices[name];
    const qty = Math.min(Math.floor(state.credits / price), state.cargoSpace - cargoUsed());
    if (qty <= 0) return;

    if (!state.cargo[name]) state.cargo[name] = { qty: 0, totalSpent: 0 };
    state.cargo[name].qty += qty;
    state.cargo[name].totalSpent += qty * price;
    state.credits -= qty * price;

    log(`Buy ${qty} ${name} @ $${price}`, "neutral");
    updateUI();
}

function sell(name) {
    const slot = state.cargo[name];
    if (!slot) return;

    const avg = getAvg(name);
    const price = prices[name];
    const profit = price - avg;

    slot.qty -= 1;
    slot.totalSpent = Math.max(0, slot.totalSpent - avg);
    state.credits += price;
    if (slot.qty <= 0) delete state.cargo[name];

    log(`Sell 1 ${name} @ $${price} (${profit >= 0 ? "+" : ""}${profit})`, profit >= 0 ? "pos" : "neg");
    updateUI();
}

function sellAll(name) {
    const slot = state.cargo[name];
    if (!slot) return;

    const avg = getAvg(name);
    const price = prices[name];
    const profit = (price - avg) * slot.qty;

    state.credits += price * slot.qty;
    delete state.cargo[name];

    log(`Sell ALL ${name} (${profit >= 0 ? "+" : ""}${profit})`, profit >= 0 ? "pos" : "neg");
    updateUI();
}

/* =====================
   FUEL / WAIT
   ===================== */
function refuel() {
    const needed = state.maxFuel - state.fuel;
    if (needed <= 0) {
        log("Fuel tank already full", "neutral");
        return;
    }

    const rate = locations[state.location].fuelRate;
    const cost = needed * rate;
    if (state.credits < cost) {
        log(`Not enough credits to refuel ($${cost} needed)`, "neg");
        return;
    }

    state.credits -= cost;
    state.fuel = state.maxFuel;
    log(`Refueled ${needed} for $${cost}`, "neutral");
    updateUI();
}

function waitHere() {
    target = state.location;
    state.day += 1;
    cleanupRumors();
    if (Math.random() < 0.35) generateRumor();
    prices = getPrices();
    log(`Waited 1 day at ${state.location}`, "neutral");
    updateUI();
}

/* =====================
   TRAVEL
   ===================== */
function travel(dest) {
    if (!dest || !locations[dest]) return;

    const dist = travelDistance(state.location, dest);
    if (state.fuel < dist) {
        log(`Not enough fuel for ${dest} (${dist} required)`, "neg");
        return;
    }

    const origin = state.location;
    state.fuel -= dist;
    state.location = dest;
    state.day += dist;
    visitedLocations.add(dest);

    cleanupRumors();
    if (Math.random() < 0.7) generateRumor();
    prices = getPrices();

    log(`Travel ${origin} → ${dest} (${dist}d)`, "neutral");
    updateUI();
}

/* =====================
   INIT
   ===================== */
function runChecks() {
    console.assert(document.getElementById("status"), "Status panel missing");
    console.assert(document.getElementById("target"), "Target panel missing");
    console.assert(document.getElementById("mapShell"), "Map panel missing");
    console.assert(document.getElementById("market"), "Market panel missing");
    console.assert(document.getElementById("console"), "Console panel missing");
    console.assert(Object.keys(locations).length >= 4, "Expected multiple locations");
    console.assert(goods.length >= 5, "Expected multiple goods");
}

prices = getPrices();
generateRumor();
runChecks();
updateUI();
</script>
</body>
</html>
