<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

const DGT_DATEX_URL = 'https://nap.dgt.es/datex2/v3/dgt/SituationPublication/datex2_v36.xml';
const CACHE_FILE = __DIR__ . '/_cache_dgt_datex2_v36.xml';
const CACHE_TTL_SECONDS = 30;

/* ===================== HTTP + Cache ===================== */
function http_get(string $url, int $timeout = 12): string {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL no está habilitado en PHP.');

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('No se pudo inicializar cURL');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'v16_mapa_php/1.3 (+Leaflet; NAP DGT DATEX2)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/xml,text/xml;q=0.9,*/*;q=0.8'],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('Error cURL: ' . $err);
    if ($code < 200 || $code >= 300) throw new RuntimeException('HTTP ' . $code . ' descargando feed DGT');

    return (string)$body;
}

function get_cached_feed(): string {
    $okCache = is_file(CACHE_FILE) && (time() - filemtime(CACHE_FILE) <= CACHE_TTL_SECONDS);
    if ($okCache) {
        $xml = file_get_contents(CACHE_FILE);
        if ($xml !== false && trim($xml) !== '') return $xml;
    }
    $xml = http_get(DGT_DATEX_URL, 12);
    @file_put_contents(CACHE_FILE, $xml);
    return $xml;
}

/* ===================== Parsing DATEX2 ===================== */
function node_text(?DOMNode $n): string { return $n ? trim((string)$n->textContent) : ''; }

function extract_latlon(DOMXPath $xp, DOMElement $record): ?array {
    $lat = $xp->query(".//*[local-name()='pointByCoordinates']//*[local-name()='latitude']", $record);
    $lon = $xp->query(".//*[local-name()='pointByCoordinates']//*[local-name()='longitude']", $record);
    if ($lat && $lon && $lat->length && $lon->length) return [(float)node_text($lat->item(0)), (float)node_text($lon->item(0))];

    $lat = $xp->query(".//*[local-name()='pointCoordinates']//*[local-name()='latitude']", $record);
    $lon = $xp->query(".//*[local-name()='pointCoordinates']//*[local-name()='longitude']", $record);
    if ($lat && $lon && $lat->length && $lon->length) return [(float)node_text($lat->item(0)), (float)node_text($lon->item(0))];

    $lat = $xp->query(".//*[local-name()='locationForDisplay']//*[local-name()='latitude'] | .//*[local-name()='locationForDisplay']//*[local-name()='pointCoordinates']//*[local-name()='latitude']", $record);
    $lon = $xp->query(".//*[local-name()='locationForDisplay']//*[local-name()='longitude'] | .//*[local-name()='locationForDisplay']//*[local-name()='pointCoordinates']//*[local-name()='longitude']", $record);
    if ($lat && $lon && $lat->length && $lon->length) return [(float)node_text($lat->item(0)), (float)node_text($lon->item(0))];

    $lat = $xp->query(".//*[local-name()='alertCPointLocation']//*[local-name()='latitude']", $record);
    $lon = $xp->query(".//*[local-name()='alertCPointLocation']//*[local-name()='longitude']", $record);
    if ($lat && $lon && $lat->length && $lon->length) return [(float)node_text($lat->item(0)), (float)node_text($lon->item(0))];

    $lat = $xp->query(".//*[local-name()='latitude']", $record);
    $lon = $xp->query(".//*[local-name()='longitude']", $record);
    if ($lat && $lon && $lat->length && $lon->length) return [(float)node_text($lat->item(0)), (float)node_text($lon->item(0))];

    return null;
}

function in_spain_bbox(float $lat, float $lon): bool {
    return ($lat >= 27.0 && $lat <= 44.8 && $lon >= -19.5 && $lon <= 5.5);
}

function is_possible_v16(array $rec): bool {
    $type  = mb_strtolower($rec['record_type'] ?? '');
    $desc  = mb_strtolower($rec['description'] ?? '');
    $cause = mb_strtolower($rec['cause'] ?? '');

    $hits = ['vehicle','stationary','obstruction','breakdown','vehicul','aver','deten','inmov','arcen','obst','carril','parad','averiado'];
    foreach ($hits as $h) {
        if (str_contains($type, $h) || str_contains($desc, $h) || str_contains($cause, $h)) return true;
    }
    return false;
}

function parse_datex2_to_points(string $xml, bool $returnAll = false, bool $debug = false): array {
    libxml_use_internal_errors(true);

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    if (!$doc->loadXML($xml)) {
        $errs = libxml_get_errors();
        libxml_clear_errors();
        throw new RuntimeException('XML inválido: ' . ($errs[0]->message ?? ''));
    }

    $xp = new DOMXPath($doc);
    $records = $xp->query("//*[local-name()='situationRecord']");
    if (!$records) return $debug ? [['__debug' => ['registros_total' => 0]]] : [];

    $stats = [
        'registros_total' => $records->length,
        'con_coordenadas' => 0,
        'en_bbox'         => 0,
        'tras_filtro'     => 0,
        'sin_filtro'      => $returnAll,
    ];

    $out = [];

    foreach ($records as $r) {
        /** @var DOMElement $r */
        $id = $r->getAttribute('id') ?: '';

        $xsiType = $r->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
        $recordType = $xsiType ?: $r->localName;

        $descNode = $xp->query(
            ".//*[local-name()='generalPublicComment']//*[local-name()='value']"
            . " | .//*[local-name()='comment']//*[local-name()='value']"
            . " | .//*[local-name()='generalPublicComment']",
            $r
        );
        $description = ($descNode && $descNode->length) ? node_text($descNode->item(0)) : '';

        $causeNode = $xp->query(
            ".//*[local-name()='causeType']"
            . " | .//*[local-name()='cause']//*[local-name()='causeType']",
            $r
        );
        $cause = ($causeNode && $causeNode->length) ? node_text($causeNode->item(0)) : '';

        $timeNode = $xp->query(
            ".//*[local-name()='situationRecordCreationTime']"
            . " | .//*[local-name()='situationRecordVersionTime']",
            $r
        );
        $ts = ($timeNode && $timeNode->length) ? node_text($timeNode->item(0)) : '';

        $latlon = extract_latlon($xp, $r);
        if (!$latlon) continue;
        $stats['con_coordenadas']++;

        [$lat, $lon] = $latlon;
        if (!in_spain_bbox($lat, $lon)) continue;
        $stats['en_bbox']++;

        $rec = [
            'id' => $id,
            'record_type' => $recordType,
            'description' => $description,
            'cause' => $cause,
            'timestamp' => $ts,
            'lat' => $lat,
            'lng' => $lon,
        ];

        if (!$returnAll && !is_possible_v16($rec)) continue;

        $stats['tras_filtro']++;
        $out[] = $rec;
    }

    $out = array_slice($out, 0, 5000);
    if ($debug) array_unshift($out, ['__debug' => $stats]);
    return $out;
}

/* ===================== AJAX endpoint ===================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $returnAll = (isset($_GET['all']) && $_GET['all'] === '1');
    $debug     = (isset($_GET['debug']) && $_GET['debug'] === '1');

    try {
        $xml = get_cached_feed();
        $items = parse_datex2_to_points($xml, $returnAll, $debug);

        $count = 0;
        foreach ($items as $it) {
            if (is_array($it) && array_key_exists('__debug', $it)) continue;
            $count++;
        }

        echo json_encode([
            'ok' => true,
            'fuente' => 'DGT NAP DATEX2 v3.6 (SituationPublication)',
            'cuenta' => $count,
            'items' => $items,
            'generado_en' => date('c'),
            'antiguedad_cache_seg' => is_file(CACHE_FILE) ? (time() - filemtime(CACHE_FILE)) : null
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Balizas V16 </title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="docs/favicon.png">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="anonymous">

    <style>
        html, body { height: 100%; margin: 0; }
        #map { position: fixed; inset: 0; width: 100vw; height: 100vh; }

        .topbar {
            position: fixed;
            z-index: 1000;
            top: 12px;
            left: 12px;
            right: 12px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            justify-content: space-between;
            pointer-events: none;
        }

        .card {
            pointer-events: auto;
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 12px;
            padding: 12px 14px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.14);
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        }
        .title { display:flex; align-items:center; gap:10px; }
        .title h1 { font-size: 15px; margin: 0; font-weight: 800; letter-spacing: .2px; }
        .muted { color: #555; font-size: 12px; line-height: 1.35; }

        .legend { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 10px; }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            border-radius: 999px;
            padding: 7px 10px;
            border: 1px solid rgba(0,0,0,0.10);
            background:#fff;
        }

        .btn {
            cursor: pointer;
            border: 1px solid rgba(0,0,0,0.12);
            background: white;
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .row { display:flex; gap:10px; align-items:center; flex-wrap: wrap; }
        .chk { display:flex; gap:6px; align-items:center; font-size:12px; }

        /* Icono “baliza” (SVG embebido) */
        .v16-icon {
            width: 28px;
            height: 28px;
            transform: translate(-14px, -28px);
        }

        /* Popup un poco más “app” */
        .popup h3 { margin: 0 0 8px 0; font-size: 14px; }
        .kv { margin: 4px 0; font-size: 13px; }
        .kv b { display:inline-block; min-width: 92px; }
        .small { color:#666; font-size: 12px; margin-top: 6px; }
    </style>
</head>
<body>

<div class="topbar">
    <div class="card" style="max-width: 820px;">
        <div class="title">
            <h1>Balizas V16 (inferido) – España</h1>
        </div>
        <div class="muted">
            Puntos obtenidos del feed oficial de incidencias (DGT NAP DATEX2). “V16” se infiere por heurística (vehículo detenido/averiado/obstrucción).
        </div>

        <div class="legend">
            <span class="pill" id="statusPill">Cargando…</span>

            <div class="row">
                <label class="chk"><input type="checkbox" id="chkAll"> Ver todas las incidencias (sin filtro)</label>
                <label class="chk"><input type="checkbox" id="chkDebug"> Mostrar estadísticas</label>
            </div>

            <button class="btn" id="btnReload" type="button">Actualizar ahora</button>
        </div>
    </div>

    <div class="card" style="min-width: 280px; text-align: right;">
        <div class="muted">Actualización automática</div>
        <div style="font-size: 14px; font-weight: 800;">cada 30 segundos</div>
        <div class="muted" id="lastUpdate">—</div>
    </div>
</div>

<div id="map"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin="anonymous"></script>

<script>
(() => {
    const REFRESH_MS = 30000;

    const map = L.map('map', { zoomControl: true }).setView([40.2, -3.7], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    // Evita el “mapa gris / tiles en un cuadrado”
    setTimeout(() => map.invalidateSize(true), 0);
    setTimeout(() => map.invalidateSize(true), 200);
    setTimeout(() => map.invalidateSize(true), 800);
    window.addEventListener('resize', () => map.invalidateSize(true));

    // Capa con clustering
    const clusters = L.markerClusterGroup({
        showCoverageOnHover: false,
        spiderfyOnMaxZoom: true,
        maxClusterRadius: 60,     // cuanto agrupa “desde lejos”
        disableClusteringAtZoom: 14
    });
    map.addLayer(clusters);

    const statusPill = document.getElementById('statusPill');
    const lastUpdate = document.getElementById('lastUpdate');
    const btnReload  = document.getElementById('btnReload');
    const chkAll     = document.getElementById('chkAll');
    const chkDebug   = document.getElementById('chkDebug');

    function escHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (m) => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
        }[m]));
    }

    function setStatus(text) { statusPill.textContent = text; }

    // Icono tipo “baliza V16” en SVG (amarillo)
    const v16Svg = `
      <svg class="v16-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" aria-hidden="true">
        <defs>
          <filter id="s" x="-20%" y="-20%" width="140%" height="140%">
            <feDropShadow dx="0" dy="2" stdDeviation="2" flood-opacity=".35"/>
          </filter>
        </defs>
        <g filter="url(#s)">
          <path d="M32 6 L58 54 H6 Z" fill="#F1C40F" stroke="#B8860B" stroke-width="3" />
          <rect x="29" y="22" width="6" height="18" rx="2" fill="#111"/>
          <circle cx="32" cy="46" r="3.5" fill="#111"/>
        </g>
      </svg>
    `;

    const v16Icon = L.divIcon({
        className: '',
        html: v16Svg,
        iconSize: [28, 28],
        iconAnchor: [14, 28],
        popupAnchor: [0, -26]
    });

    function formatFecha(ts) {
        if (!ts) return '—';
        const d = new Date(ts);
        if (isNaN(d.getTime())) return '—';
        return d.toLocaleString('es-ES');
    }

    async function loadPoints() {
        btnReload.disabled = true;
        setStatus('Cargando datos…');

        try {
            const params = new URLSearchParams();
            params.set('ajax', '1');
            if (chkAll.checked) params.set('all', '1');
            if (chkDebug.checked) params.set('debug', '1');

            const r = await fetch('?' + params.toString(), { cache: 'no-store' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Respuesta inválida');

            clusters.clearLayers();

            let debugInfo = null;

            for (const it of j.items) {
                if (it && it.__debug) {
                    debugInfo = it.__debug;
                    continue;
                }

                const m = L.marker([it.lat, it.lng], { icon: v16Icon });

                const popupHtml = `
                  <div class="popup">
                    <h3>${chkAll.checked ? 'Incidencia (DGT)' : 'Posible baliza V16 (inferido)'}</h3>
                    <div class="kv"><b>Fecha/hora:</b> ${escHtml(formatFecha(it.timestamp))}</div>
                    <div class="kv"><b>Tipo:</b> ${escHtml(it.record_type || '—')}</div>
                    <div class="kv"><b>Causa:</b> ${escHtml(it.cause || '—')}</div>
                    <div class="kv"><b>Descripción:</b> ${escHtml(it.description || '—')}</div>
                    <div class="small">${it.id ? ('ID interno: ' + escHtml(it.id)) : ''}</div>
                  </div>
                `;

                m.bindPopup(popupHtml, { maxWidth: 380 });
                clusters.addLayer(m);
            }

            let estado = `Mostrando ${j.cuenta} puntos`;
            if (debugInfo && chkDebug.checked) {
                estado += ` | registros=${debugInfo.registros_total}, coords=${debugInfo.con_coordenadas}, bbox=${debugInfo.en_bbox}, tras_filtro=${debugInfo.tras_filtro}`;
            }
            setStatus(estado);
            lastUpdate.textContent = 'Última actualización: ' + new Date().toLocaleString('es-ES');

            setTimeout(() => map.invalidateSize(true), 50);

        } catch (e) {
            console.error(e);
            setStatus('Error al cargar (ver consola)');
        } finally {
            btnReload.disabled = false;
        }
    }

    btnReload.addEventListener('click', loadPoints);
    chkAll.addEventListener('change', loadPoints);
    chkDebug.addEventListener('change', loadPoints);

    loadPoints();
    setInterval(loadPoints, REFRESH_MS);
})();
</script>
</body>
</html>
