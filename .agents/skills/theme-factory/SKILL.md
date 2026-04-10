---
name: theme-factory
description: Construcción de sistemas de diseño y temas completos para aplicaciones web. Usa cuando necesites crear design tokens, paletas de color consistentes, componentes reutilizables, o un sistema de tema dark/light mode.
---

# Theme Factory Skill

## Filosofía

Un buen tema es un **sistema de decisiones**, no una colección de colores. Define primero los tokens semánticos, luego los componentes.

## Estructura de un Tema Completo

```
theme/
├── tokens.css       — Variables CSS base (primitivos)
├── semantic.css     — Variables semánticas (uso con significado)
├── components.css   — Estilos de componentes
└── utilities.css    — Clases utilitarias
```

## tokens.css — Primitivos

```css
:root {
  /* Colores base */
  --gray-900: #0f1117;
  --gray-800: #1a1d27;
  --gray-700: #22263a;
  --gray-600: #2e3347;
  --gray-500: #4a5168;
  --gray-400: #6b7280;
  --gray-300: #9ca3af;
  --gray-200: #d1d5db;
  --gray-100: #f3f4f6;

  /* Acento principal */
  --gold-500: #c49b37;
  --gold-400: #d4aa47;
  --gold-600: #a88530;
  --gold-rgb: 196, 155, 55;

  /* Verde */
  --green-500: #508d69;
  --green-400: #62a07b;

  /* Semánticos */
  --red-500: #ef4444;
  --yellow-500: #f59e0b;
  --blue-500: #3b82f6;

  /* Tipografía */
  --font-sans: 'Inter', system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', monospace;

  /* Espaciado */
  --space-1: 4px;
  --space-2: 8px;
  --space-3: 12px;
  --space-4: 16px;
  --space-6: 24px;
  --space-8: 32px;
  --space-12: 48px;
  --space-16: 64px;

  /* Radios */
  --radius-sm: 6px;
  --radius:    12px;
  --radius-lg: 20px;
  --radius-full: 9999px;

  /* Sombras */
  --shadow-sm: 0 1px 3px rgba(0,0,0,0.2);
  --shadow:    0 4px 16px rgba(0,0,0,0.3);
  --shadow-lg: 0 12px 40px rgba(0,0,0,0.4);

  /* Transiciones */
  --ease: cubic-bezier(0.4, 0, 0.2, 1);
  --duration-fast:   150ms;
  --duration:        250ms;
  --duration-slow:   400ms;
}
```

## semantic.css — Capa Semántica

```css
[data-theme="dark"] {
  --color-bg:          var(--gray-900);
  --color-surface:     var(--gray-800);
  --color-surface-2:   var(--gray-700);
  --color-border:      var(--gray-600);
  --color-text:        var(--gray-100);
  --color-text-muted:  var(--gray-400);
  --color-accent:      var(--gold-500);
  --color-accent-rgb:  var(--gold-rgb);
  --color-success:     var(--green-500);
  --color-danger:      var(--red-500);
  --color-warning:     var(--yellow-500);
  --color-info:        var(--blue-500);
}

[data-theme="light"] {
  --color-bg:          var(--gray-100);
  --color-surface:     #ffffff;
  --color-surface-2:   var(--gray-200);
  --color-border:      var(--gray-300);
  --color-text:        var(--gray-900);
  --color-text-muted:  var(--gray-500);
  --color-accent:      var(--gold-600);
  --color-accent-rgb:  168, 133, 48;
}
```

## Implementar Toggle Dark/Light

```html
<button id="themeToggle" onclick="toggleTheme()">
    <i class="bi bi-moon-stars-fill"></i>
</button>

<script>
function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
}

// Restaurar al cargar
const saved = localStorage.getItem('theme') || 'dark';
document.documentElement.setAttribute('data-theme', saved);
</script>
```

## Plantillas de Temas

### Dark Gold (H@tch Style)
```css
--color-bg: #0f1117; --color-accent: #c49b37; --color-success: #508d69;
```

### Dark Blue (Corporate)
```css
--color-bg: #040d21; --color-accent: #3b82f6; --color-success: #10b981;
```

### Dark Purple (Creative)
```css
--color-bg: #0d0715; --color-accent: #a855f7; --color-success: #22c55e;
```

### Dark Rose (Luxury)
```css
--color-bg: #150a0a; --color-accent: #fb7185; --color-success: #34d399;
```
