---
name: web-artifacts-builder
description: Construcción de aplicaciones web completas como artefactos HTML autocontenidos. Usa cuando necesites crear dashboards, herramientas interactivas, prototipos o demos que funcionen como un solo archivo HTML sin dependencias externas de servidor.
---

# Web Artifacts Builder Skill

## ¿Qué es un Web Artifact?

Un **artefacto web** es un archivo HTML autocontenido que incluye todo (CSS, JS, datos) en un solo archivo. Es ideal para:
- Prototipos rápidos
- Dashboards estáticos
- Herramientas de consulta offline
- Demos para el usuario

## Estructura Base

```html
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nombre del Artefacto</title>

    <!-- Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Design Tokens */
        :root {
            --bg:       #0f1117;
            --surface:  #1a1d27;
            --surface2: #22263a;
            --border:   #2e3347;
            --text:     #e8eaf0;
            --muted:    #6b7280;
            --accent:   #c49b37;
            --green:    #508d69;
            --red:      #ef4444;
        }

        * { box-sizing: border-box; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }

        /* Componentes base */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        .card-header {
            background: var(--surface2);
            border-bottom: 1px solid var(--border);
            border-radius: 12px 12px 0 0 !important;
            padding: 1rem 1.25rem;
            font-weight: 600;
        }
        .table { color: var(--text); }
        .table-hover tbody tr:hover { background: rgba(255,255,255,0.03); }
        .text-accent { color: var(--accent); }
        .badge-accent { background: rgba(196,155,55,0.15); color: var(--accent); border: 1px solid rgba(196,155,55,0.3); }
    </style>
</head>
<body>
    <div class="container-fluid p-4">
        <!-- Contenido aquí -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // JavaScript aquí
    </script>
</body>
</html>
```

## Patrones de Datos Embebidos

### JSON en JS
```javascript
const DATA = {
    jugadores: [
        { id: 1, nombre: '#BRAYAN', estrellas: 21, porcentaje: 95 },
        // ...
    ]
};
```

### Renderizado dinámico
```javascript
function renderTable(data) {
    const tbody = document.querySelector('#myTable tbody');
    tbody.innerHTML = data.map(row => `
        <tr>
            <td class="fw-bold">${row.nombre}</td>
            <td class="text-center text-accent">${row.estrellas} ⭐</td>
            <td class="text-center">${row.porcentaje}%</td>
        </tr>
    `).join('');
}
```

## Charts con Chart.js

```html
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<canvas id="myChart"></canvas>
<script>
new Chart(document.getElementById('myChart'), {
    type: 'bar',
    data: {
        labels: DATA.jugadores.map(j => j.nombre),
        datasets: [{
            label: 'Estrellas',
            data: DATA.jugadores.map(j => j.estrellas),
            backgroundColor: 'rgba(196,155,55,0.6)',
            borderColor: '#c49b37',
            borderWidth: 1
        }]
    },
    options: {
        plugins: { legend: { labels: { color: '#e8eaf0' } } },
        scales: {
            x: { ticks: { color: '#6b7280' }, grid: { color: '#2e3347' } },
            y: { ticks: { color: '#6b7280' }, grid: { color: '#2e3347' } }
        }
    }
});
</script>
```

## Reglas de Calidad

- **Sin dependencias locales** — Todo debe cargar desde CDN o estar embebido
- **Interactivo por defecto** — Incluir siempre filtros, búsqueda o gráficas
- **Estética premium** — Seguir los tokens del Design System
- **Mobile-friendly** — Usar Bootstrap grid responsivo
