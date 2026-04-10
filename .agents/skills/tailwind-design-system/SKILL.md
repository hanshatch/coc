---
name: tailwind-design-system
description: Sistema de diseño con TailwindCSS. Usa cuando el proyecto use Tailwind para construir componentes, layouts y design systems. Incluye patrones para dark mode, componentes premium y configuración del tema.
---

# Tailwind Design System Skill

## Configuración del Tema (tailwind.config.js)

```javascript
/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: 'class',
    content: ['./src/**/*.{html,js,jsx,tsx,php}'],
    theme: {
        extend: {
            colors: {
                surface: {
                    DEFAULT: '#1a1d27',
                    2: '#22263a',
                    3: '#2e3347',
                },
                accent: {
                    DEFAULT: '#c49b37',
                    light: '#d4aa47',
                    dark:  '#a88530',
                },
                sage: '#508d69',
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
                mono: ['JetBrains Mono', 'monospace'],
            },
            borderRadius: {
                DEFAULT: '12px',
                card:    '16px',
            },
            boxShadow: {
                card: '0 4px 24px rgba(0,0,0,0.3)',
                glow: '0 0 20px rgba(196,155,55,0.3)',
            },
        },
    },
    plugins: [],
}
```

## Componentes Base

### Card
```html
<div class="bg-surface rounded-card border border-surface-3 shadow-card p-6">
    <h3 class="text-white font-semibold text-lg mb-4">Título</h3>
    <p class="text-gray-400">Contenido</p>
</div>
```

### Botón Primario con Glow
```html
<button class="bg-accent hover:bg-accent-light text-black font-semibold px-6 py-2.5 rounded-xl
               transition-all duration-200 hover:shadow-glow hover:-translate-y-0.5 active:translate-y-0">
    Acción
</button>
```

### Input Elegante
```html
<input type="text"
    class="w-full bg-surface-2 border border-surface-3 text-white rounded-xl px-4 py-2.5
           placeholder:text-gray-500 outline-none
           focus:border-accent focus:ring-2 focus:ring-accent/20
           transition-all duration-200">
```

### Badge / Etiqueta
```html
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
             bg-accent/10 text-accent border border-accent/20">
    ⭐ Líder
</span>
```

### Stat Card
```html
<div class="bg-surface rounded-card border border-surface-3 p-6 text-center
            hover:border-accent/30 transition-colors duration-200">
    <div class="text-4xl mb-2">🏆</div>
    <div class="text-3xl font-bold text-accent mb-1">42</div>
    <div class="text-gray-400 text-sm uppercase tracking-wide">Victorias</div>
</div>
```

## Sidebar Layout

```html
<div class="flex min-h-screen bg-gray-950">
    <!-- Sidebar -->
    <aside class="w-64 bg-surface border-r border-surface-3 flex flex-col">
        <div class="p-6 border-b border-surface-3">
            <h1 class="text-accent font-bold text-xl">⚔️ H@tch</h1>
        </div>
        <nav class="flex-1 p-4 space-y-1">
            <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-gray-300
                               hover:bg-surface-2 hover:text-white transition-all duration-150
                               [&.active]:bg-accent/10 [&.active]:text-accent [&.active]:border [&.active]:border-accent/20">
                <i class="bi bi-grid-fill"></i>
                <span>Dashboard</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 p-8 overflow-auto">
        <!-- Contenido -->
    </main>
</div>
```

## Tabla Premium

```html
<div class="bg-surface rounded-card border border-surface-3 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-surface-2 border-b border-surface-3">
                <th class="text-left px-4 py-3 text-gray-400 font-medium">Jugador</th>
                <th class="text-center px-4 py-3 text-gray-400 font-medium">Estrellas</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-3">
            <tr class="hover:bg-surface-2 transition-colors">
                <td class="px-4 py-3 text-white font-medium">#BRAYAN</td>
                <td class="px-4 py-3 text-center text-accent">21 ⭐</td>
            </tr>
        </tbody>
    </table>
</div>
```

## Dark Mode Toggle

```javascript
// Aplicar clase 'dark' al html para activar dark mode
document.documentElement.classList.toggle('dark');
```

## Checklist Tailwind

- [ ] `darkMode: 'class'` configurado en tailwind.config
- [ ] Colores custom registrados en `theme.extend.colors`
- [ ] Font Inter importada y configurada
- [ ] Usar `transition-all duration-200` en elementos hover
- [ ] `hover:-translate-y-0.5` en botones y cards clickeables
