---
name: frontend-design
description: Experto en diseño frontend moderno. Usa cuando construyas interfaces de usuario, componentes HTML/CSS/JS, layouts responsivos, sistemas de diseño, animaciones, o cualquier tarea de UI/UX con HTML, CSS vanilla, o frameworks frontend.
---

# Frontend Design Skill

## Principios Core

### 1. Design System First
Antes de escribir cualquier componente, define los tokens de diseño:
- **Colores**: Paleta primaria, secundaria, semántica (éxito, error, warning), neutros
- **Tipografía**: Familias, escala de tamaños, pesos, line-height
- **Espaciado**: Escala 4px base (4, 8, 12, 16, 24, 32, 48, 64px)
- **Bordes**: Radios, colores, grosores
- **Sombras**: Elevación por capas

### 2. Estética Premium Obligatoria
Cada UI debe sentirse moderna y pulida:
- Usa **Google Fonts** (Inter, Outfit, Plus Jakarta Sans son excelentes opciones)
- Prefiere **dark mode** con acentos vibrantes pero no fosforescentes
- Implementa **glassmorphism** donde sea apropiado: `backdrop-filter: blur()`
- Añade **micro-animaciones**: transiciones suaves (200-300ms ease)
- Usa **gradientes sutiles**, no bloques planos de color
- Aplica **sombras de color** en elementos interactivos hover

### 3. CSS Variables para Todo
```css
:root {
  --color-bg: #0f1117;
  --color-surface: #1a1d27;
  --color-surface-2: #22263a;
  --color-accent: #c49b37;
  --color-text: #e8eaf0;
  --color-muted: #6b7280;
  --radius: 12px;
  --radius-sm: 8px;
  --shadow: 0 4px 24px rgba(0,0,0,0.3);
  --transition: 200ms cubic-bezier(0.4,0,0.2,1);
}
```

## Patrones de Componentes

### Cards con Glassmorphism
```css
.glass-card {
  background: rgba(255,255,255,0.05);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: var(--radius);
}
```

### Botones con Micro-interacciones
```css
.btn-primary {
  position: relative;
  overflow: hidden;
  transition: transform var(--transition), box-shadow var(--transition);
}
.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(var(--accent-rgb), 0.4);
}
.btn-primary:active { transform: translateY(0); }
```

### Inputs Elegantes
```css
.input {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: var(--radius-sm);
  transition: border-color var(--transition), box-shadow var(--transition);
}
.input:focus {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.15);
  outline: none;
}
```

## Layouts

### Grid Responsivo con CSS Grid
```css
.grid-auto {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1.5rem;
}
```

### Sidebar + Content Layout
```css
.layout {
  display: grid;
  grid-template-columns: 260px 1fr;
  min-height: 100vh;
}
@media (max-width: 768px) {
  .layout { grid-template-columns: 1fr; }
}
```

## Animaciones

### Fade-in al cargar
```css
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}
.animate-in {
  animation: fadeInUp 0.4s ease forwards;
}
```

### Skeleton Loading
```css
@keyframes shimmer {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
.skeleton {
  background: linear-gradient(90deg, #1a1d27 25%, #22263a 50%, #1a1d27 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}
```

## Checklist de Calidad

Antes de entregar cualquier UI, verificar:
- [ ] Responsive en mobile (375px), tablet (768px) y desktop (1280px)
- [ ] Estados hover/focus/active en todos los elementos interactivos
- [ ] Contraste de color WCAG AA mínimo (4.5:1)
- [ ] No hay colores FOSOFRESCentes ni saturaciones > 85%
- [ ] Tipografía de Google Fonts cargada correctamente
- [ ] Transiciones suaves (no saltos bruscos)
- [ ] IDs únicos en elementos interactivos para accesibilidad
- [ ] Sin placeholders con imágenes rotas — usar `generate_image` tool

## Paletas Recomendadas

### Dark Gold (Actual H@tch)
```
BG: #0f1117 | Surface: #1a1d27 | Accent: #c49b37 | Green: #508d69
```

### Dark Blue Premium
```
BG: #040d21 | Surface: #0d1b35 | Accent: #3b82f6 | Purple: #8b5cf6
```

### Dark Emerald
```
BG: #071a12 | Surface: #0d2418 | Accent: #10b981 | Gold: #f59e0b
```
